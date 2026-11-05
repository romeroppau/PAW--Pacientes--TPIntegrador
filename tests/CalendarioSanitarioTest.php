<?php

use PHPUnit\Framework\TestCase;

class CalendarioSanitarioTest extends TestCase
{
    private const HOY = '2026-11-05';

    public function testLaPrimeraFechaEsElNacimientoMasLaEdadDeInicio(): void
    {
        // Cachorra nacida hace 2 semanas: la 1.ª dosis (6 semanas) le toca en 4 semanas.
        $this->assertSame('2026-12-03', CalendarioSanitario::fechaParaPlan('2026-10-22', 6, null, self::HOY));
    }

    public function testUnaDosisUnicaQueYaPasoNoSeAgrega(): void
    {
        // Perro de 3 años: la dosis de cachorro de las 6 semanas ya pasó.
        $this->assertNull(CalendarioSanitario::fechaParaPlan('2023-11-05', 6, null, self::HOY));
    }

    public function testUnItemQueSeRepiteVaALaProximaFechaDesdeHoy(): void
    {
        // Nacido el 2023-11-05: antirrábica a las 16 semanas (2024-02-25), cada 12 meses.
        // 2025-02-25 y 2026-02-25 ya pasaron: la próxima es 2027-02-25.
        $this->assertSame('2027-02-25', CalendarioSanitario::fechaParaPlan('2023-11-05', 16, 12, self::HOY));
    }

    public function testHoyCuentaComoFechaQueNoPaso(): void
    {
        // 2026-10-08 + 4 semanas = 2026-11-05 (hoy).
        $this->assertSame(self::HOY, CalendarioSanitario::fechaParaPlan('2026-10-08', 4, null, self::HOY));
    }

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
