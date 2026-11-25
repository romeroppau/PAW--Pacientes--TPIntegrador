<?php

use PHPUnit\Framework\TestCase;

class CalendarioSanitarioTest extends TestCase
{
    private const HOY = '2026-11-05';

    public function testEstados(): void
    {
        $this->assertSame('APLICADO', CalendarioSanitario::estadoDe('2026-10-01', '2026-10-02', self::HOY));
        $this->assertSame('VENCIDO', CalendarioSanitario::estadoDe('2026-11-04', null, self::HOY));
        $this->assertSame('PROXIMO', CalendarioSanitario::estadoDe(self::HOY, null, self::HOY));
        $this->assertSame('PROXIMO', CalendarioSanitario::estadoDe('2026-12-05', null, self::HOY));
        $this->assertSame('PENDIENTE', CalendarioSanitario::estadoDe('2026-12-06', null, self::HOY));
    }

    public function testVacunasAlDiaIgnoraLasDesparasitaciones(): void
    {
        $alDia = [
            ['tipo' => 'VACUNA', 'estado' => 'APLICADO'],
            ['tipo' => 'DESPARASITACION', 'estado' => 'VENCIDO'],
            ['tipo' => 'VACUNA', 'estado' => 'PROXIMO']
        ];
        $this->assertTrue(CalendarioSanitario::vacunasAlDia($alDia));

        $alDia[] = ['tipo' => 'VACUNA', 'estado' => 'VENCIDO'];
        $this->assertFalse(CalendarioSanitario::vacunasAlDia($alDia));
    }

    public function testSumarMesesNoSePasaDeMes(): void
    {
        $this->assertSame('2027-02-28', CalendarioSanitario::sumarMeses('2027-01-31', 1));
        $this->assertSame('2028-02-29', CalendarioSanitario::sumarMeses('2028-01-31', 1));
        $this->assertSame('2029-02-28', CalendarioSanitario::sumarMeses('2028-02-29', 12));
        $this->assertSame('2027-11-05', CalendarioSanitario::sumarMeses(self::HOY, 12));
        $this->assertSame('2027-02-05', CalendarioSanitario::sumarMeses(self::HOY, 3));
    }
}
