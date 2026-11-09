<?php

// Validación de los datos que llegan en los pedidos de la consulta.
// No usa la base de datos: recibe el cuerpo ya decodificado y devuelve los errores.
class ValidadorConsulta
{
    private const LARGO_MAXIMO_TEXTO = 5000;

    // Máximo de la columna DECIMAL(10,2): 99.999.999,99.
    private const VALOR_BASE_MAXIMO = 99999999.99;

    // Cuerpo de PUT /consultas/{id}: todos los campos son opcionales.
    public static function validarCambios(array $datos): array
    {
        $errores = [];

        // Validar campos de texto: diagnóstico y observaciones.
        foreach (['diagnostico', 'observaciones'] as $campo) {
            // Si el campo no está presente o es null, no hay error.
            if (!array_key_exists($campo, $datos) || $datos[$campo] === null) {
                continue;
            }

            // Validar que sea texto y no supere el largo máximo.
            if (!is_string($datos[$campo])) {
                $errores[] = "El campo $campo debe ser texto";
            } elseif (mb_strlen($datos[$campo]) > self::LARGO_MAXIMO_TEXTO) {
                $errores[] = "El campo $campo supera los " . self::LARGO_MAXIMO_TEXTO . ' caracteres';
            }
        }

        // Validar valor_base: si está presente, debe ser un número entre 0 y el máximo de la columna.
        if (array_key_exists('valor_base', $datos) && $datos['valor_base'] !== null) {
            if (!is_numeric($datos['valor_base']) || (float) $datos['valor_base'] < 0) {
                $errores[] = 'El valor base debe ser un número mayor o igual a 0';
            } elseif ((float) $datos['valor_base'] > self::VALOR_BASE_MAXIMO) {
                $errores[] = 'El valor base no puede superar ' . number_format(self::VALOR_BASE_MAXIMO, 2, ',', '.');
            }
        }

        return $errores;
    }

    // Cuerpo de POST /consultas/{id}/insumos y /productos.
    // $campoId es 'insumo_id' o 'producto_id'.
    public static function validarItem(array $datos, string $campoId): array
    {
        $errores = [];

        $id = $datos[$campoId] ?? null;
        // Validar que sea un entero mayor a 0.
        if (!is_int($id) || $id <= 0) {
            $errores[] = "El campo $campoId debe ser un entero mayor a 0";
        }

        // Validar cantidad: debe ser un entero mayor a 0.
        $cantidad = $datos['cantidad'] ?? null;
        if (!is_int($cantidad) || $cantidad <= 0) {
            $errores[] = 'La cantidad debe ser un entero mayor a 0';
        }

        return $errores;
    }

    // Requisitos para finalizar, sobre la consulta tal como está guardada:
    // diagnóstico no vacío y valor base cargado.
    public static function validarParaFinalizar(array $consulta): array
    {
        $errores = [];

        // Validar diagnóstico: debe ser un string no vacío.
        $diagnostico = $consulta['diagnostico'] ?? null;
        if (!is_string($diagnostico) || trim($diagnostico) === '') {
            $errores[] = 'El diagnóstico es obligatorio para finalizar';
        }

        // Validar valor_base: debe estar presente y ser un número mayor o igual a 0 y menor o igual al máximo.
        $valorBase = $consulta['valor_base'] ?? null;
        if ($valorBase === null || !is_numeric($valorBase) || (float) $valorBase < 0) {
            $errores[] = 'El valor base es obligatorio para finalizar';
        } elseif ((float) $valorBase > self::VALOR_BASE_MAXIMO) {
            $errores[] = 'El valor base no puede superar ' . number_format(self::VALOR_BASE_MAXIMO, 2, ',', '.');
        }

        return $errores;
    }
}