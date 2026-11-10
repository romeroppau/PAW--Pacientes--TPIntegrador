<?php

// Cálculo de la factura de una consulta: valor base + insumos + productos.
// No usa la base de datos: recibe los datos ya leídos y devuelve los importes.
class FacturaConsulta
{
    // $insumos: lista de ['cantidad' => int, 'costo_unitario' => importe].
    // $productos: lista de ['cantidad' => int, 'precio_unitario' => importe].
    // Devuelve los importes como texto con 2 decimales, listos para guardar.
    public static function calcular($valorBase, array $insumos, array $productos): array
    {
        $base = self::aCentavos($valorBase, 'El valor base');
        $subtotalInsumos = self::subtotal($insumos, 'costo_unitario');
        $subtotalProductos = self::subtotal($productos, 'precio_unitario');

        return [
            'subtotal_base' => self::aImporte($base),
            'subtotal_insumos' => self::aImporte($subtotalInsumos),
            'subtotal_productos' => self::aImporte($subtotalProductos),
            'total' => self::aImporte($base + $subtotalInsumos + $subtotalProductos),
        ];
    }

    // Número de factura de una consulta: C- más el id con 6 dígitos.
    public static function numero(int $id): string
    {
        return 'C-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    private static function subtotal(array $items, string $campoPrecio): int
    {
        $suma = 0;

        foreach ($items as $item) {
            $cantidad = $item['cantidad'] ?? null;

            if (!is_int($cantidad) || $cantidad <= 0) {
                throw new InvalidArgumentException('La cantidad debe ser un entero mayor a 0');
            }

            $suma += $cantidad * self::aCentavos($item[$campoPrecio] ?? null, 'El importe del ítem');
        }

        return $suma;
    }

    private static function aCentavos($valor, string $nombre): int
    {
        if (!is_numeric($valor) || (float) $valor < 0) {
            throw new InvalidArgumentException($nombre . ' debe ser un número mayor o igual a 0');
        }

        return (int) round((float) $valor * 100);
    }

    private static function aImporte(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}