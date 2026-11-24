<?php

// Valida el cuerpo de POST /internal/mascotas/{id}/transferir (contrato-adopciones.md, sección 7).
class ValidadorTransferencia
{
    // Devuelve [duenio_actual_id, nuevo_duenio_id], o null si el cuerpo no es válido.
    // Los ids tienen que ser enteros de verdad: un número como texto ("104") o un decimal se rechaza,
    // igual que en el descuento de stock de Inventario.
    public static function validar(array $datos): ?array
    {
        $actual = $datos['duenio_actual_id'] ?? null;
        $nuevo = $datos['nuevo_duenio_id'] ?? null;

        if (!self::esId($actual) || !self::esId($nuevo) || $actual === $nuevo) {
            return null;
        }

        return [$actual, $nuevo];
    }

    private static function esId(mixed $valor): bool
    {
        return is_int($valor) && $valor > 0 && $valor <= 4294967295; // INT UNSIGNED
    }
}
