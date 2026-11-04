<?php

use PHPUnit\Framework\TestCase;

class FacturaConsultaTest extends TestCase
{
    public function testCalculaElTotalConInsumosYProductos(): void
    {
        $insumos = [['cantidad' => 2, 'costo_unitario' => '150.50']];
        $productos = [['cantidad' => 1, 'precio_unitario' => '15999.90']];

        $factura = FacturaConsulta::calcular('8000.00', $insumos, $productos);

        $this->assertSame('8000.00', $factura['subtotal_base']);
        $this->assertSame('301.00', $factura['subtotal_insumos']);
        $this->assertSame('15999.90', $factura['subtotal_productos']);
        $this->assertSame('24300.90', $factura['total']);
    }

    public function testSinItemsElTotalEsElValorBase(): void
    {
        $factura = FacturaConsulta::calcular(8000, [], []);

        $this->assertSame('0.00', $factura['subtotal_insumos']);
        $this->assertSame('0.00', $factura['subtotal_productos']);
        $this->assertSame('8000.00', $factura['total']);
    }

    public function testNoAcumulaErroresDeDecimales(): void
    {
        $insumos = [['cantidad' => 3, 'costo_unitario' => '0.10']];

        $factura = FacturaConsulta::calcular('0', $insumos, []);

        $this->assertSame('0.30', $factura['total']);
    }

    public function testRechazaCantidadCeroONegativa(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FacturaConsulta::calcular('100', [['cantidad' => 0, 'costo_unitario' => '10']], []);
    }

    public function testRechazaValorBaseNegativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FacturaConsulta::calcular('-1', [], []);
    }

    public function testArmaElNumeroDeFactura(): void
    {
        $this->assertSame('C-000102', FacturaConsulta::numero(102));
        $this->assertSame('C-000001', FacturaConsulta::numero(1));
    }
}