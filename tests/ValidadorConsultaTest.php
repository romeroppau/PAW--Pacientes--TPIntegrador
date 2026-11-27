<?php

use PHPUnit\Framework\TestCase;

class ValidadorConsultaTest extends TestCase
{
    public function testAceptaLosTresMediosDePago(): void
    {
        foreach (['EFECTIVO', 'TRANSFERENCIA', 'TARJETA'] as $medio) {
            $this->assertSame([], ValidadorConsulta::validarPago(['medio_pago' => $medio]));
        }
    }

    public function testRechazaMedioDePagoFaltanteODesconocido(): void
    {
        $this->assertArrayHasKey('medio_pago', ValidadorConsulta::validarPago([]));
        $this->assertArrayHasKey('medio_pago', ValidadorConsulta::validarPago(['medio_pago' => 'efectivo']));
        $this->assertArrayHasKey('medio_pago', ValidadorConsulta::validarPago(['medio_pago' => 'CHEQUE']));
        $this->assertArrayHasKey('medio_pago', ValidadorConsulta::validarPago(['medio_pago' => 1]));
    }

    public function testCambiosVaciosSonValidos(): void
    {
        $this->assertSame([], ValidadorConsulta::validarCambios([]));
    }

    public function testCambiosCompletosSonValidos(): void
    {
        $datos = ['diagnostico' => 'Otitis', 'observaciones' => 'Control en 7 días', 'valor_base' => '8000.00'];

        $this->assertSame([], ValidadorConsulta::validarCambios($datos));
    }

    public function testRechazaValorBaseNegativoOTexto(): void
    {
        $this->assertNotEmpty(ValidadorConsulta::validarCambios(['valor_base' => -5]));
        $this->assertNotEmpty(ValidadorConsulta::validarCambios(['valor_base' => 'abc']));
    }

    public function testRechazaDiagnosticoQueNoEsTexto(): void
    {
        $this->assertNotEmpty(ValidadorConsulta::validarCambios(['diagnostico' => 123]));
    }

    public function testRechazaTextoDemasiadoLargo(): void
    {
        $largo = str_repeat('a', 5001);

        $this->assertNotEmpty(ValidadorConsulta::validarCambios(['observaciones' => $largo]));
    }

    public function testItemValido(): void
    {
        $this->assertSame([], ValidadorConsulta::validarItem(['insumo_id' => 3, 'cantidad' => 2], 'insumo_id'));
    }

    public function testItemRechazaCantidadInvalida(): void
    {
        $this->assertNotEmpty(ValidadorConsulta::validarItem(['insumo_id' => 3, 'cantidad' => 0], 'insumo_id'));
        $this->assertNotEmpty(ValidadorConsulta::validarItem(['insumo_id' => 3, 'cantidad' => '2'], 'insumo_id'));
        $this->assertNotEmpty(ValidadorConsulta::validarItem(['insumo_id' => 3], 'insumo_id'));
    }

    public function testItemRechazaIdInvalido(): void
    {
        $this->assertNotEmpty(ValidadorConsulta::validarItem(['producto_id' => 0, 'cantidad' => 1], 'producto_id'));
        $this->assertNotEmpty(ValidadorConsulta::validarItem(['cantidad' => 1], 'producto_id'));
    }

    public function testFinalizarExigeDiagnosticoYValorBase(): void
    {
        $this->assertCount(2, ValidadorConsulta::validarParaFinalizar(['diagnostico' => '  ', 'valor_base' => null]));
        $this->assertCount(1, ValidadorConsulta::validarParaFinalizar(['diagnostico' => 'Otitis', 'valor_base' => null]));
        $this->assertSame([], ValidadorConsulta::validarParaFinalizar(['diagnostico' => 'Otitis', 'valor_base' => '0.00']));
    }

    public function testFinalizarRechazaUnTotalQueNoEntraEnLaFactura(): void
    {
        $consulta = ['diagnostico' => 'Otitis', 'valor_base' => '99999999.99'];

        $this->assertSame([], ValidadorConsulta::validarParaFinalizar($consulta + ['total' => '99999999.99']));
        $this->assertCount(1, ValidadorConsulta::validarParaFinalizar($consulta + ['total' => '100000149.99']));
    }

    // Migraciones 025 y 026: cada vacuna o desparasitación del turno lleva su insumo.
    public function testFinalizarExigeElInsumoDeCadaVacuna(): void
    {
        $consulta = [
            'diagnostico' => 'Sano', 'valor_base' => '15000.00',
            'eventos_sanitarios' => [
                ['id' => 9, 'nombre' => 'Antirrábica', 'aplicacion' => 'ANTIRRABICA'],
                ['id' => 10, 'nombre' => 'Séxtuple (2.ª dosis)', 'aplicacion' => 'SEXTUPLE'],
            ],
            'insumos' => [['insumo_id' => 101, 'aplicacion' => null]],
        ];

        $this->assertSame(
            ['Falta cargar en los insumos la dosis de: Antirrábica, Séxtuple (2.ª dosis).'],
            ValidadorConsulta::validarParaFinalizar($consulta)
        );

        $consulta['insumos'][] = ['insumo_id' => 104, 'aplicacion' => 'SEXTUPLE'];
        $this->assertSame(
            ['Falta cargar en los insumos la dosis de: Antirrábica.'],
            ValidadorConsulta::validarParaFinalizar($consulta)
        );

        $consulta['insumos'][] = ['insumo_id' => 106, 'aplicacion' => 'ANTIRRABICA'];
        $this->assertSame([], ValidadorConsulta::validarParaFinalizar($consulta));
    }
}
