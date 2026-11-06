<?php

// Los endpoints de la consulta, la factura y el historial clínico (contrato en docs/api/contrato-consultas.md).
// Los métodos son static para conectarlos al router así: [ConsultaControlador::class, 'ver'].
class ConsultaControlador
{
    private const CONSULTA_NO_ENCONTRADA = 'Consulta no encontrada';
    private const CONSULTA_FINALIZADA = 'La consulta ya está finalizada';
    private const CANTIDAD_MAXIMA = 100000;

    // POST /api/pacientes/turnos/{id}/atencion
    public static function iniciarAtencion(array $parametros): void
    {
        // Se exige sesión de veterinario y token CSRF.
        self::veterinario(true);

        $repositorio = self::repositorio();
        $turnoId = self::entero($parametros, 'id');

        // Se valida que el turno exista y esté programado antes de iniciar la atención, para no procesar datos que no se usarán.
        if ($turnoId === null) {
            Response::error('Turno no encontrado', 404);
            return;
        }

        $resultado = $repositorio->iniciarAtencion($turnoId);

        if ($resultado['estado'] === 'turno_inexistente') {
            Response::error('Turno no encontrado', 404);
            return;
        }

        if ($resultado['estado'] === 'no_programado') {
            Response::error('El turno no está programado o ya tiene una consulta', 409);
            return;
        }

        Response::json(['consulta' => $repositorio->detalle($resultado['id'])], 201);
    }

    // GET /api/pacientes/consultas/{id}
    public static function ver(array $parametros): void
    {
        // Se exige sesión de veterinario, pero no CSRF porque es GET.
        self::veterinario();

        $id = self::entero($parametros, 'id');
        $consulta = $id === null ? null : self::repositorio()->detalle($id);

        if ($consulta === null) {
            Response::error(self::CONSULTA_NO_ENCONTRADA, 404);
            return;
        }

        Response::json($consulta);
    }

    // PUT /api/pacientes/consultas/{id}
    public static function guardar(array $parametros): void
    {
        self::veterinario(true);

        // Se valida que la consulta exista y esté abierta antes de leer el cuerpo, para no procesar datos que no se usarán.
        $repositorio = self::repositorio();
        $id = self::entero($parametros, 'id');
        $estado = $id === null ? null : $repositorio->estado($id);

        if ($estado === null) {
            Response::error(self::CONSULTA_NO_ENCONTRADA, 404);
            return;
        }

        if ($estado !== 'ABIERTA') {
            Response::error(self::CONSULTA_FINALIZADA, 409);
            return;
        }

        $datos = Request::json();
        $errores = ValidadorConsulta::validarCambios($datos);

        if ($errores !== []) {
            Response::error(implode(' ', $errores), 400);
            return;
        }

        // Solo se cambian los campos que llegaron. Texto vacío = sin dato (NULL). valor_base vacío o ausente = no cambia.
        $cambios = [];

        foreach (['diagnostico', 'observaciones'] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $texto = trim($datos[$campo]);
                $cambios[$campo] = $texto === '' ? null : $texto;
            }
        }

        if (isset($datos['valor_base'])) {
            $cambios['valor_base'] = number_format((float) $datos['valor_base'], 2, '.', '');
        }

        $repositorio->actualizar($id, $cambios);

        Response::json($repositorio->detalle($id));
    }

    // POST /api/pacientes/consultas/{id}/insumos
    public static function agregarInsumo(array $parametros): void
    {
        self::agregarItem($parametros, 'insumos', 'insumo_id', 'Insumo no encontrado');
    }

    // POST /api/pacientes/consultas/{id}/productos
    public static function agregarProducto(array $parametros): void
    {
        self::agregarItem($parametros, 'productos', 'producto_id', 'Producto no encontrado');
    }

    // DELETE /api/pacientes/consultas/{id}/insumos/{itemId}
    public static function quitarInsumo(array $parametros): void
    {
        self::quitarItem($parametros, 'insumos');
    }

    // DELETE /api/pacientes/consultas/{id}/productos/{itemId}
    public static function quitarProducto(array $parametros): void
    {
        self::quitarItem($parametros, 'productos');
    }

    // POST /api/pacientes/consultas/{id}/finalizar
    // Orden: 1) validar, 2) descontar el stock en Inventario, 3) cerrar la consulta en una transacción.
    // Repetirlo es seguro: cada ítem manda siempre el mismo id_operacion y Inventario no descuenta dos veces.
    public static function finalizar(array $parametros): void
    {
        // Se exige sesión de veterinario y token CSRF.
        $sesion = self::veterinario(true);

        $repositorio = self::repositorio();
        $id = self::entero($parametros, 'id');
        $consulta = $id === null ? null : $repositorio->detalle($id);

        if ($consulta === null) {
            Response::error(self::CONSULTA_NO_ENCONTRADA, 404);
            return;
        }

        // Segundo clic o reintento sobre una consulta ya cerrada: se devuelve la misma factura.
        if ($consulta['estado'] === 'FINALIZADA') {
            Response::json(['consulta' => $consulta, 'factura' => $repositorio->factura($id)]);
            return;
        }

        $errores = ValidadorConsulta::validarParaFinalizar($consulta);

        if ($errores !== []) {
            Response::error(implode(' ', $errores), 400);
            return;
        }

        $items = [];

        // Se arma la lista de ítems a descontar en Inventario, con id_operacion único por ítem de la consulta.
        foreach ($consulta['insumos'] as $item) {
            $items[] = [
                'tipo' => 'INSUMO', 'id' => (int) $item['insumo_id'], 'cantidad' => (int) $item['cantidad'],
                'id_operacion' => "consulta-$id-insumo-{$item['id']}"
            ];
        }

        // Se arma la lista de ítems a descontar en Inventario, con id_operacion único por ítem de la consulta.
        foreach ($consulta['productos'] as $item) {
            $items[] = [
                'tipo' => 'PRODUCTO', 'id' => (int) $item['producto_id'], 'cantidad' => (int) $item['cantidad'],
                'id_operacion' => "consulta-$id-producto-{$item['id']}"
            ];
        }

        if ($items !== []) {
            $respuesta = ClienteInventario::descontar((int) $sesion['id'], $items);

            if ($respuesta['codigo'] === 409) {
                Response::json([
                    'error' => 'Stock insuficiente',
                    'items' => $respuesta['datos']['items'] ?? []
                ], 409);
                return;
            }

            // Un insumo o producto de la consulta ya no existe en Inventario: se agregó mientras existía.
            if ($respuesta['codigo'] === 404) {
                Logger::error("Pacientes: Inventario no encontró un ítem al finalizar la consulta $id");
                Response::error('Un insumo o producto de la consulta ya no existe en Inventario. Quitalo e intentá de nuevo.', 409);
                return;
            }

            if ($respuesta['codigo'] !== 200) {
                Logger::error("Pacientes: Inventario respondió {$respuesta['codigo']} al finalizar la consulta $id");
                Response::error('No se pudo comunicar con Inventario. La consulta sigue abierta.', 502);
                return;
            }
        }

        // Si otro pedido la cerró mientras tanto, finalizar() devuelve null y se usa la factura ya creada.
        $factura = $repositorio->finalizar($id) ?? $repositorio->factura($id);

        Response::json(['consulta' => $repositorio->detalle($id), 'factura' => $factura]);
    }

    // GET /api/pacientes/consultas/{id}/factura
    public static function factura(array $parametros): void
    {
        self::veterinario();

        $id = self::entero($parametros, 'id');
        $factura = $id === null ? null : self::repositorio()->factura($id);

        // Si la consulta no existe o no está finalizada, se devuelve 404. No se devuelve 409 porque el cliente no puede finalizarla.
        if ($factura === null) {
            Response::error('Factura no encontrada', 404);
            return;
        }

        Response::json($factura);
    }

    // GET /api/pacientes/mascotas/{id}/historial
    // El cliente solo ve sus mascotas; si pide la de otro recibe 404, igual que si no existiera.
    public static function historial(array $parametros): void
    {
        // Se exige sesión de cliente o veterinario, pero no CSRF porque es GET.
        $sesion = Auth::requerirRol(['CLIENTE', 'VETERINARIO']);

        // Se valida que la mascota exista y, si es cliente, que sea suya, antes de consultar el historial, para no procesar datos que no se usarán.
        $repositorio = self::repositorio();
        $id = self::entero($parametros, 'id');
        $duenio = $id === null ? null : $repositorio->duenioDeMascota($id);

        // Si la mascota no existe o el cliente no es su dueño, se devuelve 404. El veterinario puede ver todas las mascotas.
        if ($duenio === null || ($sesion['rol'] === 'CLIENTE' && $duenio !== (int) $sesion['id'])) {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        Response::json(['consultas' => $repositorio->historial($id)]);
    }

    // ---------- Ayudas ----------

    private static function agregarItem(array $parametros, string $tipo, string $campoId, string $noEncontrado): void
    {
        self::veterinario(true);

        $repositorio = self::repositorio();
        $id = self::entero($parametros, 'id');

        if ($id === null) {
            Response::error(self::CONSULTA_NO_ENCONTRADA, 404);
            return;
        }

        $datos = Request::json();
        $errores = ValidadorConsulta::validarItem($datos, $campoId);

        if ($errores === [] && $datos['cantidad'] > self::CANTIDAD_MAXIMA) {
            $errores[] = 'La cantidad no puede superar ' . self::CANTIDAD_MAXIMA . '.';
        }

        if ($errores !== []) {
            Response::error(implode(' ', $errores), 400);
            return;
        }

        $resultado = $repositorio->agregarItem($id, $tipo, $datos[$campoId], $datos['cantidad']);

        if (!self::responderError($resultado, $noEncontrado)) {
            Response::json($repositorio->detalle($id), 201);
        }
    }

    private static function quitarItem(array $parametros, string $tipo): void
    {
        self::veterinario(true);

        $repositorio = self::repositorio();
        $id = self::entero($parametros, 'id');
        $itemId = self::entero($parametros, 'itemId');

        if ($id === null || $itemId === null) {
            Response::error('Ítem no encontrado', 404);
            return;
        }

        $resultado = $repositorio->quitarItem($id, $tipo, $itemId);

        if (!self::responderError($resultado, 'Ítem no encontrado')) {
            Response::json($repositorio->detalle($id));
        }
    }

    // Responde el error que corresponde a un resultado del repositorio. Devuelve false si fue 'ok'.
    private static function responderError(string $resultado, string $itemNoEncontrado): bool
    {
        switch ($resultado) {
            case 'ok':
                return false;
            case 'consulta_inexistente':
                Response::error(self::CONSULTA_NO_ENCONTRADA, 404);
                return true;
            case 'finalizada':
                Response::error(self::CONSULTA_FINALIZADA, 409);
                return true;
            default:
                Response::error($itemNoEncontrado, 404);
                return true;
        }
    }

    // Exige sesión de veterinario (401 o 403 si no) y, en POST, PUT y DELETE, el token CSRF (403).
    // Devuelve los datos del JWT (id, rol, csrf). El id del veterinario sale de acá, nunca del cuerpo.
    private static function veterinario(bool $conCsrf = false): array
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);

        if ($conCsrf) {
            Auth::verificarCsrf($sesion);
        }

        return $sesion;
    }

    // Un parámetro de la ruta como entero positivo, o null si no lo es (y entonces se responde 404).
    private static function entero(array $parametros, string $clave): ?int
    {
        $valor = $parametros[$clave] ?? '';

        // Se acepta un string de dígitos, no un número, para que no se rompa la ruta si el cliente manda algo raro.
        if (!is_string($valor) || !ctype_digit($valor) || strlen($valor) > 10) {
            return null;
        }

        $valor = (int) $valor;

        return $valor > 0 && $valor <= 4294967295 ? $valor : null; // INT UNSIGNED
    }

    private static function repositorio(): ConsultaRepositorio
    {
        return new ConsultaRepositorio(Database::conectar());
    }
}