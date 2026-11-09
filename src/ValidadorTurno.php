<?php

// Validación del alta de un turno. No usa la base de datos.
// Devuelve [$limpios, $errores], con un error por campo (igual que ValidadorMascota).
class ValidadorTurno
{
    public const MOTIVOS = ['CONSULTA', 'VACUNACION', 'CHEQUEO_ADOPCION'];
    public const HORA_APERTURA = '08:00';
    public const HORA_CIERRE = '20:00';
    private const ANIOS_HACIA_ADELANTE = 1;

    public static function validarAlta(array $datos, string $hoy): array
    {
        $limpios = [];
        $errores = [];

        $mascota = $datos['mascota_id'] ?? null;
        if (!is_int($mascota) || $mascota <= 0) {
            $errores['mascota_id'] = 'Elegí la mascota';
        } else {
            $limpios['mascota_id'] = $mascota;
        }

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

        // Hora: HH:MM de 24 horas, dentro del horario de atención (el último turno empieza antes del cierre).
        $hora = $datos['hora'] ?? null;
        if (!is_string($hora) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            $errores['hora'] = 'La hora debe tener el formato HH:MM';
        } elseif ($hora < self::HORA_APERTURA || $hora >= self::HORA_CIERRE) {
            $errores['hora'] = 'El horario de atención es de ' . self::HORA_APERTURA . ' a ' . self::HORA_CIERRE;
        } else {
            $limpios['hora'] = $hora;
        }

        $motivo = $datos['motivo'] ?? null;
        if (!is_string($motivo) || !in_array($motivo, self::MOTIVOS, true)) {
            $errores['motivo'] = 'Elegí el motivo';
        } else {
            $limpios['motivo'] = $motivo;
        }

        return [$limpios, $errores];
    }
}
