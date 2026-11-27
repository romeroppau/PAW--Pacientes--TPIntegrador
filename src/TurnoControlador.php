<?php

// Los endpoints de la agenda y del cambio de estado de los turnos (contrato en docs/api/contrato-mascotas-turnos.md).
// Los métodos son static para conectarlos al router así: [TurnoControlador::class, 'listar'].
class TurnoControlador
{
    private const TURNO_NO_ENCONTRADO = 'Turno no encontrado';
    private const DIAS_HACIA_ATRAS_CLIENTE = 30;

    // GET /api/pacientes/horario — público (sin sesión). El horario de atención que configuró la
    // veterinaria: {"apertura": "09:00", "cierre": "19:00", "franjas": [{"apertura": "09:00",
    // "cierre": "13:00"}, {"apertura": "16:00", "cierre": "19:00"}]} (una franja si es de corrido). Lo usan los formularios de turnos y lo
    // muestran la portada del sitio y "Contactanos" de los paneles. No es un dato privado.
    public static function horario(): void
    {
        Response::json(HorarioAtencion::actual());
    }

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

        [$datos, $errores] = ValidadorTurno::validarAlta(Request::json(), date('Y-m-d'), HorarioAtencion::actual(), date('H:i'));

        if ($errores !== []) {
            Response::error('Revisá los datos del turno', 400, $errores);
            return;
        }

        // Cada vacunación o desparasitación (migraciones 025 y 026): tiene que estar en el calendario de la
        // mascota sin dar, sin una dosis anterior pendiente y sin otro turno para lo mismo.
        $calendario = new CalendarioSanitario(Database::conectar());
        foreach ($datos['motivos'] as $i => $motivo) {
            if (!isset($motivo['plan_sanitario_id'])) {
                continue;
            }

            // Con la fecha del turno: tiene que respetar los días mínimos desde la dosis anterior (26/11).
            $evento = $calendario->eventoParaTurno($datos['mascota_id'], $motivo['plan_sanitario_id'], $motivo['motivo'], $datos['fecha']);

            if ($evento['estado'] === 'mascota_inexistente') {
                Response::error('Mascota no encontrada', 404);
                return;
            }

            if ($evento['estado'] !== 'ok') {
                $codigo = $evento['estado'] === 'plan_invalido' ? 400 : 409;
                Response::error($evento['mensaje'], $codigo, ['plan_sanitario_id' => $evento['mensaje']]);
                return;
            }

            $datos['motivos'][$i]['evento_sanitario_id'] = $evento['evento_id'];
        }

        $repositorio = self::repositorio();
        $resultado = $repositorio->crear($datos, (int) $sesion['id'], self::precioPara($datos['fecha'], $datos['hora']));

        if ($resultado === 'mascota_inexistente') {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        if ($resultado === 'ocupado') {
            Response::error('Ya hay un turno a esa hora', 409, ['hora' => 'Ya tenés un turno a esa hora']);
            return;
        }

        $turno = $repositorio->detalle($resultado);
        // Aviso al dueño por la campana y por WhatsApp (25/11). El del chequeo de adopción no: lo
        // manda Adopciones al adoptante y a la rescatista cuando se asigna (27/11).
        if (!AvisosTurno::esChequeo($turno)) {
            AvisosTurno::enviar(AvisosTurno::asignado($turno));
        }

        Response::json(['turno' => $turno], 201);
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

        [$datos, $errores] = ValidadorTurno::validarReprogramacion(Request::json(), date('Y-m-d'), HorarioAtencion::actual(), date('H:i'));

        if ($errores !== []) {
            Response::error('Revisá la fecha y la hora', 400, $errores);
            return;
        }

        // Una vacunación o desparasitación pasada a otra fecha tiene que seguir respetando los días mínimos
        // desde la dosis anterior (26/11).
        $intervalo = (new CalendarioSanitario(Database::conectar()))->intervaloParaTurno($id, $datos['fecha']);
        if ($intervalo !== null) {
            Response::error($intervalo, 409, ['fecha' => $intervalo]);
            return;
        }

        $repositorio = self::repositorio();

        switch ($repositorio->reprogramar($id, $datos['fecha'], $datos['hora'], self::precioPara($datos['fecha'], $datos['hora']))) {
            case 'ok':
                $turno = $repositorio->detalle($id);
                // El chequeo de una adopción: al adoptante y a la rescatista (27/11).
                $adopcion = AvisosTurno::esChequeo($turno) ? $repositorio->adopcionDelChequeo($id) : null;
                AvisosTurno::enviar($adopcion !== null
                    ? AvisosTurno::chequeoReprogramado($turno, $adopcion, date('YmdHis'))
                    : AvisosTurno::reprogramado($turno, date('YmdHis')));
                Response::json(['turno' => $turno]);
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

    // El precio que guarda un turno de esa fecha y hora: el precio base de hoy y, si su franja tiene una
    // promoción vigente que llega hasta ese día, su descuento.
    private static function precioPara(string $fecha, string $hora): array
    {
        $franja = PrecioTurno::franjaDe($fecha, $hora);
        $promocion = $franja['dia_semana'] > 6 ? null
            : (new PromocionFranjaRepositorio(Database::conectar()))->paraTurno($franja['dia_semana'], $franja['franja'], $fecha, date('Y-m-d'));

        return PrecioTurno::paraTurno(PrecioTurno::precioBase(), $promocion);
    }

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
                $turno = $repositorio->detalle($id);
                // Al cancelarlo, se le avisa al dueño (25/11). Marcarlo ausente no avisa.
                // El chequeo de una adopción: al adoptante y a la rescatista (27/11).
                if ($estado === 'CANCELADO') {
                    $adopcion = AvisosTurno::esChequeo($turno) ? $repositorio->adopcionDelChequeo($id) : null;
                    AvisosTurno::enviar($adopcion !== null
                        ? AvisosTurno::chequeoCancelado($turno, $adopcion)
                        : AvisosTurno::cancelado($turno));
                }
                Response::json(['turno' => $turno]);
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
