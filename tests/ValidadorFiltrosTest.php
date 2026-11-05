<?php

use PHPUnit\Framework\TestCase;

class ValidadorFiltrosTest extends TestCase
{
    public function testAceptaUnaFechaValida(): void
    {
        $this->assertSame([], ValidadorFiltros::validarFecha('2026-11-05'));
        $this->assertSame([], ValidadorFiltros::validarFecha('2028-02-29'));
    }

    public function testRechazaFechaConOtroFormato(): void
    {
        $this->assertNotEmpty(ValidadorFiltros::validarFecha('05/11/2026'));
        $this->assertNotEmpty(ValidadorFiltros::validarFecha('2026-11-5'));
        $this->assertNotEmpty(ValidadorFiltros::validarFecha(''));
    }

    public function testRechazaFechaQueNoExiste(): void
    {
        $this->assertNotEmpty(ValidadorFiltros::validarFecha('2026-02-30'));
        $this->assertNotEmpty(ValidadorFiltros::validarFecha('2026-13-01'));
    }

    public function testRechazaFechaQueNoEsTexto(): void
    {
        // ?fecha[]=x llega como array.
        $this->assertNotEmpty(ValidadorFiltros::validarFecha(['2026-11-05']));
    }

    public function testAceptaBusquedaVaciaOCorta(): void
    {
        $this->assertSame([], ValidadorFiltros::validarBusqueda(''));
        $this->assertSame([], ValidadorFiltros::validarBusqueda('Firu'));
    }

    public function testRechazaBusquedaDemasiadoLarga(): void
    {
        $largo = str_repeat('a', ValidadorFiltros::LARGO_MAXIMO_BUSQUEDA + 1);

        $this->assertNotEmpty(ValidadorFiltros::validarBusqueda($largo));
    }

    public function testRechazaBusquedaQueNoEsTexto(): void
    {
        $this->assertNotEmpty(ValidadorFiltros::validarBusqueda(['Firu']));
    }
}
