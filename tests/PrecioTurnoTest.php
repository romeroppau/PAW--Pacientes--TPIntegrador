<?php

use PHPUnit\Framework\TestCase;

// Pruebas de las reglas del precio del turno y de las franjas promocionadas (src/PrecioTurno.php).
// No usan la base de datos. "Hoy" es el lunes 23/11/2026.
class PrecioTurnoTest extends TestCase
{
    private function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-23', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    public function testLaFranjaEsElDiaYMananaOTarde(): void
    {
        // Martes 24/11: hasta las 12:59 es la mañana; desde las 13:00, la tarde (como el motor de Reportes).
        $this->assertSame(['dia_semana' => 2, 'franja' => 'MANIANA'], PrecioTurno::franjaDe('2026-11-24', '12:59'));
        $this->assertSame(['dia_semana' => 2, 'franja' => 'TARDE'], PrecioTurno::franjaDe('2026-11-24', '13:00'));
        $this->assertSame(6, PrecioTurno::franjaDe('2026-11-28', '09:00')['dia_semana']);   // sábado
        $this->assertSame(7, PrecioTurno::franjaDe('2026-11-29', '09:00')['dia_semana']);   // domingo: sin franja
    }

    public function testElTurnoGuardaElPrecioBaseYElDescuentoDeSuPromocion(): void
    {
        $this->assertSame(
            ['precio_consulta' => 15000.0, 'descuento' => 20, 'promocion_franja_id' => 3],
            PrecioTurno::paraTurno(15000, ['id' => 3, 'porcentaje' => 20])
        );
        $this->assertSame(
            ['precio_consulta' => 15000.0, 'descuento' => null, 'promocion_franja_id' => null],
            PrecioTurno::paraTurno(15000, null)
        );
    }

    public function testElValorDeLaConsultaEsElPrecioConElDescuento(): void
    {
        $this->assertSame(12000.0, PrecioTurno::conDescuento(15000, 20));
        $this->assertSame(15000.0, PrecioTurno::conDescuento(15000, null));
        $this->assertSame(11733.33, PrecioTurno::conDescuento(13333.33, 12));   // redondeado a centavos
    }

    public function testElNombreDeLaFranja(): void
    {
        $this->assertSame('Martes a la tarde', PrecioTurno::nombreDeFranja(2, 'TARDE'));
        $this->assertSame('Sábado a la mañana', PrecioTurno::nombreDeFranja(6, 'MANIANA'));
    }

    public function testUnaPromocionValida(): void
    {
        [$limpios, $errores] = PrecioTurno::validarPromocion(
            ['dia_semana' => 2, 'franja' => 'TARDE', 'porcentaje' => '20', 'hasta' => '2026-12-31'],
            $this->hoy()
        );

        $this->assertSame([], $errores);
        $this->assertSame(['dia_semana' => 2, 'franja' => 'TARDE', 'porcentaje' => 20, 'hasta' => '2026-12-31'], $limpios);
    }

    public function testLosErroresDeUnaPromocion(): void
    {
        [, $errores] = PrecioTurno::validarPromocion(
            ['dia_semana' => 7, 'franja' => 'NOCHE', 'porcentaje' => 95, 'hasta' => '2026-11-22'],
            $this->hoy()
        );

        $this->assertSame('Elegí un día de lunes a sábado', $errores['dia_semana']);
        $this->assertSame('Elegí mañana o tarde', $errores['franja']);
        $this->assertSame('El descuento tiene que ser un número entero entre 5 y 90 (por ciento)', $errores['porcentaje']);
        $this->assertSame('La promoción no puede terminar antes de hoy', $errores['hasta']);

        [, $errores] = PrecioTurno::validarPromocion(['dia_semana' => 1, 'franja' => 'MANIANA', 'porcentaje' => 10, 'hasta' => '2027-02-22'], $this->hoy());
        $this->assertSame('La promoción puede durar como mucho 90 días (hasta el 21/02/2027)', $errores['hasta']);

        [, $errores] = PrecioTurno::validarPromocion('no es un objeto', $this->hoy());
        $this->assertSame(['dia_semana', 'franja', 'porcentaje', 'hasta'], array_keys($errores));
    }
}
