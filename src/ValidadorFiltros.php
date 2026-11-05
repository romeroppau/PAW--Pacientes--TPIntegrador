<?php

// Validación de los filtros que llegan por la URL en la agenda y el listado de mascotas.
// No usa la base de datos: recibe el valor y devuelve los errores.
class ValidadorFiltros
{
    public const LARGO_MAXIMO_BUSQUEDA = 80;

    // ?fecha= de la agenda: una fecha real con el formato AAAA-MM-DD.
    public static function validarFecha(mixed $fecha): array
    {
        if (!is_string($fecha) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes)) {
            return ['La fecha debe tener el formato AAAA-MM-DD'];
        }

        // checkdate rechaza fechas que no existen, como 2026-02-30.
        if (!checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return ['La fecha no existe'];
        }

        return [];
    }

    // ?buscar= del listado de mascotas.
    public static function validarBusqueda(mixed $buscar): array
    {
        if (!is_string($buscar)) {
            return ['La búsqueda debe ser texto'];
        }

        if (mb_strlen($buscar) > self::LARGO_MAXIMO_BUSQUEDA) {
            return ['La búsqueda no puede superar los ' . self::LARGO_MAXIMO_BUSQUEDA . ' caracteres'];
        }

        return [];
    }
}
