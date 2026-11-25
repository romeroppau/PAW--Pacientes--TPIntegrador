<?php

use PHPUnit\Framework\TestCase;

class ValidadorConsultaTest extends TestCase
{
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

    // Migración 025: una consulta de vacunación o desparasitación lleva el insumo de lo que se da.
    public function testFinalizarUnaVacunaExigeSuInsumo(): void
    {
        $consulta = [
            'diagnostico' => 'Sano', 'valor_base' => '15000.00',
            'evento_sanitario' => ['id' => 9, 'nombre' => 'Antirrábica', 'aplicacion' => 'ANTIRRABICA'],
            'insumos' => [['insumo_id' => 101, 'aplicacion' => null]],
        ];

        $this->assertSame(
            ['Esta consulta es para Antirrábica: cargá en los insumos la dosis que se da.'],
            ValidadorConsulta::validarParaFinalizar($consulta)
        );

        // El insumo de otra vacuna no sirve.
        $consulta['insumos'][] = ['insumo_id' => 104, 'aplicacion' => 'SEXTUPLE'];
        $this->assertCount(1, ValidadorConsulta::validarParaFinalizar($consulta));

        $consulta['insumos'][] = ['insumo_id' => 106, 'aplicacion' => 'ANTIRRABICA'];
        $this->assertSame([], ValidadorConsulta::validarParaFinalizar($consulta));
    }
}
