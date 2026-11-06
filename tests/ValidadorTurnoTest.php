<?php

use PHPUnit\Framework\TestCase;

class ValidadorTurnoTest extends TestCase
{
    private const HOY = '2026-11-06';

    public function testAceptaUnTurnoValido(): void
    {
        $datos = ['mascota_id' => 101, 'fecha' => self::HOY, 'hora' => '17:30', 'motivo' => 'VACUNACION'];
        [$limpios, $errores] = ValidadorTurno::validarAlta($datos, self::HOY);

        $this->assertSame([], $errores);
        $this->assertSame($datos, $limpios);
    }

    public function testMarcaCadaCampoInvalido(): void
    {
        [, $errores] = ValidadorTurno::validarAlta(
            ['mascota_id' => '101', 'fecha' => '06/11/2026', 'hora' => '25:00', 'motivo' => 'PELUQUERIA'],
            self::HOY
        );

        $this->assertSame(['mascota_id', 'fecha', 'hora', 'motivo'], array_keys($errores));
    }

    public function testNoAceptaUnaFechaPasada(): void
    {
        [, $errores] = ValidadorTurno::validarAlta(
            ['mascota_id' => 101, 'fecha' => '2026-11-05', 'hora' => '10:00', 'motivo' => 'CONSULTA'],
            self::HOY
        );

        $this->assertSame(['fecha'], array_keys($errores));
    }

    public function testLaHoraTieneQueSerHHMM(): void
    {
        foreach (['9:00', '09:60', '0900', '09:00:00'] as $hora) {
            [, $errores] = ValidadorTurno::validarAlta(
                ['mascota_id' => 1, 'fecha' => self::HOY, 'hora' => $hora, 'motivo' => 'CONSULTA'],
                self::HOY
            );
            $this->assertArrayHasKey('hora', $errores, $hora);
        }
    }

    public function testFaltaTodo(): void
    {
        [, $errores] = ValidadorTurno::validarAlta([], self::HOY);

        $this->assertCount(4, $errores);
    }
}
