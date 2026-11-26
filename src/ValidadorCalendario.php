<?php

// Validación de lo que el veterinario carga en el calendario sanitario (migración 025). No usa la base.
// Cada método devuelve el mensaje de error, o null si el dato sirve.
class ValidadorCalendario
{
    // Una fecha programada puede ser de hoy hasta tantos años adelante.
    public const ANIOS_HACIA_ADELANTE = 3;

    // Cuándo le toca (agregar al calendario o cambiar la fecha): de hoy en adelante.
    public static function fechaProgramada(mixed $fecha, string $hoy): ?string
    {
        if (ValidadorFiltros::validarFecha($fecha) !== []) {
            return 'La fecha no es válida';
        }

        if ($fecha < $hoy) {
            return 'La fecha no puede ser anterior a hoy';
        }

        $limite = (new DateTimeImmutable($hoy))->modify('+' . self::ANIOS_HACIA_ADELANTE . ' years')->format('Y-m-d');

        return $fecha > $limite ? 'La fecha no puede ser de más de ' . self::ANIOS_HACIA_ADELANTE . ' años adelante' : null;
    }

    // Cuándo se la dieron (marcar como dada): no puede ser futura ni anterior al nacimiento.
    public static function fechaAplicada(mixed $fecha, string $hoy, ?string $nacimiento): ?string
    {
        if (ValidadorFiltros::validarFecha($fecha) !== []) {
            return 'La fecha no es válida';
        }

        if ($fecha > $hoy) {
            return 'La fecha no puede ser posterior a hoy';
        }

        if ($nacimiento !== null && $fecha < $nacimiento) {
            return 'La fecha no puede ser anterior al nacimiento de la mascota';
        }

        return null;
    }

    // El id de un ítem del plan: un entero positivo.
    public static function planId(mixed $valor): ?string
    {
        return is_int($valor) && $valor > 0 ? null : 'Elegí la vacuna o desparasitación';
    }
}
