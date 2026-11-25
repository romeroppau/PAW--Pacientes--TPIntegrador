<?php

// Los endpoints del calendario sanitario (contrato en docs/api/contrato-calendario.md).
// Los métodos son static para conectarlos al router así: [CalendarioControlador::class, 'ver'].
// Desde la migración 025 (25/11) el veterinario también lo edita: agregar, marcar como dada, cambiar
// la fecha y quitar. Cada edición responde el calendario completo, igual que ver().
class CalendarioControlador
{
    private const MASCOTA_NO_ENCONTRADA = 'Mascota no encontrada';
    private const EVENTO_NO_ENCONTRADO = 'Esa vacuna o desparasitación no está en el calendario de la mascota';
    private const YA_DADA = 'Esa vacuna o desparasitación ya está dada: no se cambia';
    private const CON_TURNO = 'Tiene un turno programado para darla: cancelalo primero';

    // GET /api/pacientes/mascotas/{id}/calendario-sanitario
    // El cliente solo ve sus mascotas; si pide la de otro recibe 404, igual que si no existiera.
    public static function ver(array $parametros): void
    {
        // Se exige sesión de cliente o veterinario, pero no CSRF porque es GET.
        $sesion = Auth::requerirRol(['CLIENTE', 'VETERINARIO']);

        $db = Database::conectar();
        $hoy = date('Y-m-d');
        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : (new MascotaRepositorio($db))->detalle($id, $hoy);

        if ($mascota === null || ($sesion['rol'] === 'CLIENTE' && $mascota['duenio']['id'] !== (int) $sesion['id'])) {
            Response::error(self::MASCOTA_NO_ENCONTRADA, 404);
            return;
        }

        $calendario = new CalendarioSanitario($db);

        // Una mascota que todavía no tiene calendario (por ejemplo, cargada antes de que existiera)
        // lo recibe la primera vez que alguien lo pide.
        if ($calendario->eventos($id, $hoy) === []) {
            $calendario->generarPara($id, $hoy);
        }

        Response::json(self::respuesta($calendario, $mascota, $hoy));
    }

    // POST /api/pacientes/mascotas/{id}/calendario-sanitario — {"plan_sanitario_id": 5, "fecha_programada": "2026-12-01"}
    public static function agregar(array $parametros): void
    {
        self::editar($parametros, null, function (CalendarioSanitario $calendario, array $mascota, array $datos, string $hoy): bool {
            $errores = [];
            if (($error = ValidadorCalendario::planId($datos['plan_sanitario_id'] ?? null)) !== null) {
                $errores['plan_sanitario_id'] = $error;
            }
            if (($error = ValidadorCalendario::fechaProgramada($datos['fecha_programada'] ?? null, $hoy)) !== null) {
                $errores['fecha_programada'] = $error;
            }
            if ($errores !== []) {
                Response::error('Revisá los datos', 400, $errores);
                return false;
            }

            switch ($calendario->agregar($mascota['id'], $datos['plan_sanitario_id'], $datos['fecha_programada'])) {
                case 'ok':
                    return true;
                case 'plan_invalido':
                    Response::error('Esa vacuna o desparasitación no es para la especie de la mascota', 400, ['plan_sanitario_id' => 'Elegí una de la lista']);
                    return false;
                case 'ya_pendiente':
                    Response::error('Ya está en el calendario y todavía no se dio: si hace falta, cambiale la fecha', 409);
                    return false;
                default: // ya_dada
                    Response::error('Ya se la dieron, y es de una sola vez', 409);
                    return false;
            }
        });
    }

    // POST /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}/dada — {"fecha_aplicada": "2026-11-20"}
    // Para lo que se dio fuera de una consulta de HuellaVet (por ejemplo, en otra veterinaria).
    public static function marcarDada(array $parametros): void
    {
        self::editarEvento($parametros, function (CalendarioSanitario $calendario, array $mascota, array $datos, string $hoy, int $eventoId): bool {
            $error = ValidadorCalendario::fechaAplicada($datos['fecha_aplicada'] ?? null, $hoy, $mascota['fecha_nacimiento']);
            if ($error !== null) {
                Response::error($error, 400, ['fecha_aplicada' => $error]);
                return false;
            }

            $anterior = null;
            $resultado = $calendario->marcarDada($mascota['id'], $eventoId, $datos['fecha_aplicada'], $anterior);

            if ($resultado === 'dosis_anterior') {
                Response::error("Primero marcá como dada: $anterior", 409);
                return false;
            }

            if ($resultado === 'con_turno') {
                Response::error(self::CON_TURNO . ', o dala en esa consulta', 409);
                return false;
            }

            return $resultado === 'ok' || self::errorEvento($resultado);
        });
    }

    // PUT /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId} — {"fecha_programada": "2026-12-01"}
    public static function cambiarFecha(array $parametros): void
    {
        self::editarEvento($parametros, function (CalendarioSanitario $calendario, array $mascota, array $datos, string $hoy, int $eventoId): bool {
            $error = ValidadorCalendario::fechaProgramada($datos['fecha_programada'] ?? null, $hoy);
            if ($error !== null) {
                Response::error($error, 400, ['fecha_programada' => $error]);
                return false;
            }

            $resultado = $calendario->cambiarFecha($mascota['id'], $eventoId, $datos['fecha_programada']);

            return $resultado === 'ok' || self::errorEvento($resultado);
        });
    }

    // DELETE /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}
    public static function quitar(array $parametros): void
    {
        self::editarEvento($parametros, function (CalendarioSanitario $calendario, array $mascota, array $datos, string $hoy, int $eventoId): bool {
            $resultado = $calendario->quitar($mascota['id'], $eventoId);

            if ($resultado === 'con_turno') {
                Response::error(self::CON_TURNO, 409);
                return false;
            }

            return $resultado === 'ok' || self::errorEvento($resultado);
        }, false);
    }

    // ---------- Ayudas ----------

    // La respuesta de ver() y de cada edición. El plan de la especie va para que el veterinario pueda
    // agregar al calendario y elegir la vacuna de un turno.
    private static function respuesta(CalendarioSanitario $calendario, array $mascota, string $hoy): array
    {
        $eventos = $calendario->eventos($mascota['id'], $hoy);

        return [
            'mascota' => [
                'id' => $mascota['id'],
                'nombre' => $mascota['nombre'],
                'especie' => $mascota['especie'],
                'fecha_nacimiento' => $mascota['fecha_nacimiento'],
                // El dueño (la veterinaria lo ve en la tabla, con el enlace a su ficha).
                'duenio' => ['id' => $mascota['duenio']['id'], 'nombre' => $mascota['duenio']['nombre']],
            ],
            'vacunas_al_dia' => CalendarioSanitario::vacunasAlDia($eventos),
            'eventos' => $eventos,
            'plan' => $calendario->plan($mascota['especie']),
        ];
    }

    // Una edición del calendario: veterinario con CSRF y una mascota que existe. $accion corre en una
    // transacción con la mascota bloqueada (dos clics a la vez no agregan dos veces lo mismo); responde
    // el error y devuelve false, o devuelve true y se responde el calendario.
    private static function editar(array $parametros, ?int $eventoId, callable $accion, bool $conCuerpo = true): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $db = Database::conectar();
        $hoy = date('Y-m-d');
        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : (new MascotaRepositorio($db))->detalle($id, $hoy);

        if ($mascota === null) {
            Response::error(self::MASCOTA_NO_ENCONTRADA, 404);
            return;
        }

        if ($eventoId === 0) {
            Response::error(self::EVENTO_NO_ENCONTRADO, 404);
            return;
        }

        $datos = $conCuerpo ? Request::json() : [];
        $calendario = new CalendarioSanitario($db);

        $db->beginTransaction();
        try {
            $db->prepare('SELECT id FROM mascota WHERE id = ? FOR UPDATE')->execute([$id]);

            if (!$accion($calendario, $mascota, $datos, $hoy, $eventoId)) {
                $db->rollBack();
                return;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        Response::json(self::respuesta($calendario, $mascota, $hoy));
    }

    // Igual que editar(), para un evento de la ruta ({eventoId}); uno inválido da 404.
    private static function editarEvento(array $parametros, callable $accion, bool $conCuerpo = true): void
    {
        self::editar($parametros, ParametroRuta::entero($parametros, 'eventoId') ?? 0, $accion, $conCuerpo);
    }

    // 'inexistente' → 404 y 'ya_dada' → 409. Devuelve false (para cortar la edición).
    private static function errorEvento(string $resultado): bool
    {
        if ($resultado === 'inexistente') {
            Response::error(self::EVENTO_NO_ENCONTRADO, 404);
        } else {
            Response::error(self::YA_DADA, 409);
        }

        return false;
    }
}
