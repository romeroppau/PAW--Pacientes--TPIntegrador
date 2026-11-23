<?php

// Las promociones de franjas (Entrega 5): la veterinaria pone un descuento a la consulta de los turnos
// de una franja (un día a la mañana o a la tarde) desde "Qué promocionar" de Reportes, y los clientes
// las ven en el sitio. Contrato: docs/api/contrato-mascotas-turnos.md, sección "Precio del turno y
// franjas promocionadas". Reglas en PrecioTurno; SQL en PromocionFranjaRepositorio.
class PromocionFranjaControlador
{
    // GET /api/pacientes/promociones-turnos (público): el precio base y las franjas en promoción.
    public static function listar(): void
    {
        $precio = PrecioTurno::precioBase();
        $promociones = self::repositorio()->vigentes(self::hoy());

        Response::json([
            'precio_consulta' => $precio,
            'datos' => array_map(fn (array $p) => self::formato($p, $precio), $promociones),
        ]);
    }

    // POST /api/pacientes/promociones-turnos (veterinaria, CSRF): { dia_semana, franja, porcentaje, hasta }
    public static function crear(): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        [$datos, $errores] = PrecioTurno::validarPromocion(Request::json(), new DateTimeImmutable(self::hoy()));
        if ($errores !== []) {
            Response::error('Datos inválidos', 400, $errores);
            return;
        }

        $repositorio = self::repositorio();
        $resultado = $repositorio->crear($datos, (int) $sesion['id'], date('Y-m-d H:i:s'), self::hoy());
        if (is_array($resultado)) {
            $vigente = $resultado[1];
            Response::json([
                'error' => PrecioTurno::nombreDeFranja($datos['dia_semana'], $datos['franja'])
                    . " ya tiene una promoción del {$vigente['porcentaje']} % hasta el "
                    . implode('/', array_reverse(explode('-', $vigente['hasta']))) . '. Terminala para publicar otra.',
                'promocion_id' => $vigente['id'],
            ], 409);
            return;
        }

        Logger::info("Promoción de franja $resultado publicada: {$datos['dia_semana']} {$datos['franja']}, {$datos['porcentaje']} % hasta {$datos['hasta']} (usuario {$sesion['id']})");

        $precio = PrecioTurno::precioBase();
        foreach ($repositorio->vigentes(self::hoy()) as $promocion) {
            if ($promocion['id'] === $resultado) {
                Response::json(self::formato($promocion, $precio), 201);
                return;
            }
        }
    }

    // DELETE /api/pacientes/promociones-turnos/{id} (veterinaria, CSRF): la termina ahora. Los turnos
    // que ya se sacaron con el descuento lo conservan.
    public static function terminar(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        if ($id === null || !self::repositorio()->terminar($id, date('Y-m-d H:i:s'), self::hoy())) {
            Response::error('No hay una promoción vigente con ese número', 404);
            return;
        }

        Logger::info("Promoción de franja $id terminada (usuario {$sesion['id']})");
        http_response_code(204);
    }

    private static function formato(array $p, float $precio): array
    {
        return $p + [
            'nombre' => PrecioTurno::nombreDeFranja($p['dia_semana'], $p['franja']),
            'precio' => $precio,
            'precio_promocion' => PrecioTurno::conDescuento($precio, $p['porcentaje']),
        ];
    }

    // Hoy en Argentina (index.php fija la zona horaria).
    private static function hoy(): string
    {
        return date('Y-m-d');
    }

    private static function repositorio(): PromocionFranjaRepositorio
    {
        return new PromocionFranjaRepositorio(Database::conectar());
    }
}
