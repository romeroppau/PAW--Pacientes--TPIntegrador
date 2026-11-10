<?php

// Los endpoints de la agenda y del cambio de estado de los turnos (contrato en docs/api/contrato-mascotas-turnos.md).
// Los métodos son static para conectarlos al router así: [TurnoControlador::class, 'listar'].
class TurnoControlador
{
    private const TURNO_NO_ENCONTRADO = 'Turno no encontrado';
    private const DIAS_HACIA_ATRAS_CLIENTE = 30;

    // GET /api/pacientes/turnos
    // Veterinario: la agenda de un día (?fecha=, por defecto hoy). Cliente: los turnos de sus mascotas.
    public static function listar(): void
    {
        // Se exige sesión de veterinario o cliente, pero no CSRF porque es GET.
        $sesion = Auth::requerirRol(['VETERINARIO', 'CLIENTE']);
        $repositorio = self::repositorio();

        if ($sesion['rol'] === 'CLIENTE') {
            $desde = date('Y-m-d', strtotime('-' . self::DIAS_HACIA_ATRAS_CLIENTE . ' days'));
            Response::json(['turnos' => $repositorio->delCliente((int) $sesion['id'], $desde)]);
            return;
        }

        $fecha = Request::query()['fecha'] ?? date('Y-m-d');
        $errores = ValidadorFiltros::validarFecha($fecha);

        if ($errores !== []) {
            Response::error(implode(' ', $errores), 400);
            return;
        }

        Response::json(['fecha' => $fecha, 'turnos' => $repositorio->agenda($fecha)]);
    }

    // POST /api/pacientes/turnos/{id}/cancelar
    public static function cancelar(array $parametros): void
    {
        self::cambiarEstado($parametros, 'CANCELADO', null);
    }

    // POST /api/pacientes/turnos/{id}/ausente
    // Solo un turno de hoy o de un día anterior: no se puede faltar a un turno que todavía no llegó.
    public static function marcarAusente(array $parametros): void
    {
        self::cambiarEstado($parametros, 'AUSENTE', date('Y-m-d'));
    }

    // POST /api/pacientes/turnos — el veterinario carga un turno (se coordinan por WhatsApp).
    // El veterinario del turno es el de la sesión, nunca uno que venga en el cuerpo.
    public static function crear(): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        [$datos, $errores] = ValidadorTurno::validarAlta(Request::json(), date('Y-m-d'));

        if ($errores !== []) {
            Response::error('Revisá los datos del turno', 400, $errores);
            return;
        }

        $repositorio = self::repositorio();
        $resultado = $repositorio->crear($datos, (int) $sesion['id']);

        if ($resultado === 'mascota_inexistente') {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        if ($resultado === 'ocupado') {
            Response::error('Ya hay un turno a esa hora', 409, ['hora' => 'Ya tenés un turno a esa hora']);
            return;
        }

        Response::json(['turno' => $repositorio->detalle($resultado)], 201);
    }

    // POST /api/pacientes/turnos/{id}/reprogramar — el veterinario cambia la fecha y la hora de un turno
    // programado (el cliente lo pidió por WhatsApp). La mascota y el motivo no cambian.
    public static function reprogramar(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = ParametroRuta::entero($parametros, 'id');
        if ($id === null) {
            Response::error(self::TURNO_NO_ENCONTRADO, 404);
            return;
        }

        [$datos, $errores] = ValidadorTurno::validarReprogramacion(Request::json(), date('Y-m-d'));

        if ($errores !== []) {
            Response::error('Revisá la fecha y la hora', 400, $errores);
            return;
        }

        $repositorio = self::repositorio();

        switch ($repositorio->reprogramar($id, $datos['fecha'], $datos['hora'])) {
            case 'ok':
                Response::json(['turno' => $repositorio->detalle($id)]);
                return;
            case 'inexistente':
                Response::error(self::TURNO_NO_ENCONTRADO, 404);
                return;
            case 'ocupado':
                Response::error('Ya hay un turno a esa hora', 409, ['hora' => 'Ya tenés un turno a esa hora']);
                return;
            default:
                Response::error('Solo se puede reprogramar un turno programado', 409);
        }
    }

    // ---------- Ayudas ----------

    private static function cambiarEstado(array $parametros, string $estado, ?string $hastaFecha): void
    {
        // Se exige sesión de veterinario y token CSRF.
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $repositorio = self::repositorio();
        $id = ParametroRuta::entero($parametros, 'id');
        $resultado = $id === null ? 'inexistente' : $repositorio->cambiarEstado($id, $estado, $hastaFecha);

        switch ($resultado) {
            case 'ok':
                Response::json(['turno' => $repositorio->detalle($id)]);
                return;
            case 'inexistente':
                Response::error(self::TURNO_NO_ENCONTRADO, 404);
                return;
            case 'futuro':
                Response::error('No se puede marcar ausente un turno de un día que todavía no llegó', 409);
                return;
            default:
                Response::error('El turno no está programado', 409);
        }
    }

    private static function repositorio(): TurnoRepositorio
    {
        return new TurnoRepositorio(Database::conectar());
    }
}
