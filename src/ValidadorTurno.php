<?php

// Validación del alta y de la reprogramación de un turno. No usa la base de datos.
// Devuelve [$limpios, $errores], con un error por campo (igual que ValidadorMascota).
// El horario de atención lo pasa quien llama (HorarioAtencion::actual(), que la veterinaria cambia
// desde su panel); si no lo pasa, es el de siempre: de HORA_APERTURA a HORA_CIERRE.
class ValidadorTurno
{
    // Migración 025: más motivos. CHEQUEO_ADOPCION lo usan los chequeos de adopción.
    public const MOTIVOS = ['CONSULTA', 'VACUNACION', 'DESPARASITACION', 'CASTRACION', 'CIRUGIA', 'CONTROL', 'URGENCIA', 'CHEQUEO_ADOPCION'];
    // Los motivos que dan algo del calendario sanitario: el turno dice cuál (plan_sanitario_id).
    public const MOTIVOS_DEL_CALENDARIO = ['VACUNACION', 'DESPARASITACION'];
    // Horario por defecto (el mismo que figura en el catálogo de configuración de Backend).
    public const HORA_APERTURA = '08:00';
    public const HORA_CIERRE = '20:00';
    private const ANIOS_HACIA_ADELANTE = 1;

    public static function validarAlta(array $datos, string $hoy, ?array $horario = null): array
    {
        $limpios = [];
        $errores = [];

        $mascota = $datos['mascota_id'] ?? null;
        if (!is_int($mascota) || $mascota <= 0) {
            $errores['mascota_id'] = 'Elegí la mascota';
        } else {
            $limpios['mascota_id'] = $mascota;
        }

        self::validarFechaYHora($datos, $hoy, $horario ?? self::horarioPorDefecto(), $limpios, $errores);

        $motivo = $datos['motivo'] ?? null;
        if (!is_string($motivo) || !in_array($motivo, self::MOTIVOS, true)) {
            $errores['motivo'] = 'Elegí el motivo';
        } else {
            $limpios['motivo'] = $motivo;
        }

        // Vacunación o desparasitación: cuál (un ítem del plan sanitario). Que esté en el calendario de
        // la mascota lo revisa CalendarioSanitario::eventoParaTurno, con la base.
        if (isset($limpios['motivo']) && in_array($limpios['motivo'], self::MOTIVOS_DEL_CALENDARIO, true)) {
            $plan = $datos['plan_sanitario_id'] ?? null;
            if (!is_int($plan) || $plan <= 0) {
                $errores['plan_sanitario_id'] = $limpios['motivo'] === 'VACUNACION' ? 'Elegí la vacuna' : 'Elegí la desparasitación';
            } else {
                $limpios['plan_sanitario_id'] = $plan;
            }
        }

        return [$limpios, $errores];
    }

    // Reprogramar: solo cambian la fecha y la hora, con las mismas reglas del alta.
    public static function validarReprogramacion(array $datos, string $hoy, ?array $horario = null): array
    {
        $limpios = [];
        $errores = [];
        self::validarFechaYHora($datos, $hoy, $horario ?? self::horarioPorDefecto(), $limpios, $errores);

        return [$limpios, $errores];
    }

    // El mismo formato que HorarioAtencion::actual(), con una sola franja.
    public static function horarioPorDefecto(): array
    {
        $franja = ['apertura' => self::HORA_APERTURA, 'cierre' => self::HORA_CIERRE];

        return [...$franja, 'franjas' => [$franja]];
    }

    // "de 09:00 a 13:00 y de 16:00 a 19:00"
    public static function textoHorario(array $franjas): string
    {
        return implode(' y ', array_map(fn ($f) => 'de ' . $f['apertura'] . ' a ' . $f['cierre'], $franjas));
    }

    private static function validarFechaYHora(array $datos, string $hoy, array $horario, array &$limpios, array &$errores): void
    {
        // Fecha: de hoy hasta un año adelante.
        $fecha = $datos['fecha'] ?? null;
        if (ValidadorFiltros::validarFecha($fecha) !== []) {
            $errores['fecha'] = 'La fecha no es válida';
        } elseif ($fecha < $hoy) {
            $errores['fecha'] = 'La fecha no puede ser anterior a hoy';
        } elseif ($fecha > (new DateTimeImmutable($hoy))->modify('+' . self::ANIOS_HACIA_ADELANTE . ' year')->format('Y-m-d')) {
            $errores['fecha'] = 'La fecha no puede ser de más de un año adelante';
        } else {
            $limpios['fecha'] = $fecha;
        }

        // Hora: HH:MM de 24 horas, dentro de alguna franja del horario de atención (el último turno de
        // cada una empieza antes de su cierre). Sin 'franjas', una sola: de apertura a cierre.
        $franjas = $horario['franjas'] ?? [['apertura' => $horario['apertura'], 'cierre' => $horario['cierre']]];
        $dentro = static fn (string $hora): bool => array_filter(
            $franjas,
            fn ($f) => $hora >= $f['apertura'] && $hora < $f['cierre']
        ) !== [];
        $hora = $datos['hora'] ?? null;
        if (!is_string($hora) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            $errores['hora'] = 'La hora debe tener el formato HH:MM';
        } elseif (!$dentro($hora)) {
            $errores['hora'] = 'El horario de atención es ' . self::textoHorario($franjas);
        } else {
            $limpios['hora'] = $hora;
        }
    }
}
