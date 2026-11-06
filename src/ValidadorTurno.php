<?php

// Validación del alta de un turno. No usa la base de datos.
// Devuelve [$limpios, $errores], con un error por campo (igual que ValidadorMascota).
class ValidadorTurno
{
    public const MOTIVOS = ['CONSULTA', 'VACUNACION', 'CHEQUEO_ADOPCION'];

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

        // Fecha: hoy o más adelante.
        $fecha = $datos['fecha'] ?? null;
        if (ValidadorFiltros::validarFecha($fecha) !== []) {
            $errores['fecha'] = 'La fecha no es válida';
        } elseif ($fecha < $hoy) {
            $errores['fecha'] = 'La fecha no puede ser anterior a hoy';
        } else {
            $limpios['fecha'] = $fecha;
        }

        // Hora: HH:MM de 24 horas.
        $hora = $datos['hora'] ?? null;
        if (!is_string($hora) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
            $errores['hora'] = 'La hora debe tener el formato HH:MM';
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
