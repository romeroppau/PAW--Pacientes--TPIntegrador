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

    public function testLaFechaNoPuedeSerDeMasDeUnAnioAdelante(): void
    {
        $turno = ['mascota_id' => 101, 'hora' => '10:00', 'motivo' => 'CONSULTA'];

        [, $alLimite] = ValidadorTurno::validarAlta($turno + ['fecha' => '2027-11-06'], self::HOY);
        [, $pasado] = ValidadorTurno::validarAlta($turno + ['fecha' => '2027-11-07'], self::HOY);
        [, $lejano] = ValidadorTurno::validarAlta($turno + ['fecha' => '2999-01-01'], self::HOY);

        $this->assertSame([], $alLimite);
        $this->assertSame(['fecha'], array_keys($pasado));
        $this->assertSame(['fecha'], array_keys($lejano));
    }

    public function testLaHoraTieneQueEstarDentroDelHorarioDeAtencion(): void
    {
        foreach (['08:00' => [], '19:59' => [], '07:59' => ['hora'], '20:00' => ['hora'], '03:00' => ['hora']] as $hora => $esperado) {
            [, $errores] = ValidadorTurno::validarAlta(
                ['mascota_id' => 1, 'fecha' => self::HOY, 'hora' => $hora, 'motivo' => 'CONSULTA'],
                self::HOY
            );
            $this->assertSame($esperado, array_keys($errores), $hora);
        }
    }

    public function testFaltaTodo(): void
    {
        [, $errores] = ValidadorTurno::validarAlta([], self::HOY);

        $this->assertCount(4, $errores);
    }

    public function testReprogramarSoloPideFechaYHora(): void
    {
        [$limpios, $errores] = ValidadorTurno::validarReprogramacion(['fecha' => self::HOY, 'hora' => '19:30'], self::HOY);

        $this->assertSame([], $errores);
        $this->assertSame(['fecha' => self::HOY, 'hora' => '19:30'], $limpios);
    }

    public function testReprogramarUsaLasMismasReglasQueElAlta(): void
    {
        [, $errores] = ValidadorTurno::validarReprogramacion(['fecha' => '2026-11-05', 'hora' => '20:00'], self::HOY);

        $this->assertSame(['fecha', 'hora'], array_keys($errores));
    }
}
