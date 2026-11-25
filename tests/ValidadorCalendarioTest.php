<?php

use PHPUnit\Framework\TestCase;

// Lo que el veterinario carga en el calendario sanitario (migración 025): src/ValidadorCalendario.php.
class ValidadorCalendarioTest extends TestCase
{
    private const HOY = '2026-11-25';

    public function testLaFechaProgramadaVaDeHoyATresAniosAdelante(): void
    {
        $this->assertNull(ValidadorCalendario::fechaProgramada(self::HOY, self::HOY));
        $this->assertNull(ValidadorCalendario::fechaProgramada('2029-11-25', self::HOY));
        $this->assertSame('La fecha no puede ser anterior a hoy', ValidadorCalendario::fechaProgramada('2026-11-24', self::HOY));
        $this->assertSame('La fecha no puede ser de más de 3 años adelante', ValidadorCalendario::fechaProgramada('2029-11-26', self::HOY));
        $this->assertSame('La fecha no es válida', ValidadorCalendario::fechaProgramada('2026-02-30', self::HOY));
        $this->assertSame('La fecha no es válida', ValidadorCalendario::fechaProgramada(null, self::HOY));
    }

    // Marcar como dada: lo que se dio en otra veterinaria ya pasó, y no antes de nacer.
    public function testLaFechaAplicadaNoEsFuturaNiAnteriorAlNacimiento(): void
    {
        $this->assertNull(ValidadorCalendario::fechaAplicada(self::HOY, self::HOY, '2026-01-10'));
        $this->assertNull(ValidadorCalendario::fechaAplicada('2025-03-01', self::HOY, null));
        $this->assertSame('La fecha no puede ser posterior a hoy', ValidadorCalendario::fechaAplicada('2026-11-26', self::HOY, null));
        $this->assertSame(
            'La fecha no puede ser anterior al nacimiento de la mascota',
            ValidadorCalendario::fechaAplicada('2026-01-09', self::HOY, '2026-01-10')
        );
        $this->assertSame('La fecha no es válida', ValidadorCalendario::fechaAplicada('25/11/2026', self::HOY, null));
    }

    public function testElPlanEsUnEnteroPositivo(): void
    {
        $this->assertNull(ValidadorCalendario::planId(5));
        foreach (['5', 0, -1, null, 5.0] as $valor) {
            $this->assertSame('Elegí la vacuna o desparasitación', ValidadorCalendario::planId($valor));
        }
    }
}
