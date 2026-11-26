<?php

use PHPUnit\Framework\TestCase;

// Días mínimos entre dosis de la misma vacuna (src/IntervalosVacunas.php, 26/11). Sin la configuración
// cargada (como en estas pruebas) se usan los valores por defecto.
class IntervalosVacunasTest extends TestCase
{
    public function testLosValoresPorDefecto(): void
    {
        $this->assertSame(21, IntervalosVacunas::dias('SEXTUPLE', false));
        $this->assertSame(330, IntervalosVacunas::dias('SEXTUPLE', true));
        $this->assertSame(330, IntervalosVacunas::dias('ANTIRRABICA', false));
        $this->assertSame(330, IntervalosVacunas::dias('ANTIRRABICA', true));
        $this->assertSame(14, IntervalosVacunas::dias('ANTIPARASITARIO', true));
        $this->assertNull(IntervalosVacunas::dias(null, false));
        $this->assertNull(IntervalosVacunas::dias('OTRA', false));
    }

    public function testElConflictoEsLaDosisMasCercanaQueNoRespetaElMinimo(): void
    {
        // La 2.ª séxtuple 20 días después de la 1.ª: no (hacen falta 21).
        $this->assertSame('2026-10-01', IntervalosVacunas::conflicto('2026-10-21', ['2026-10-01'], 21));
        // A los 21 días, sí.
        $this->assertNull(IntervalosVacunas::conflicto('2026-10-22', ['2026-10-01'], 21));
        // También hacia atrás (marcar como dada una fecha anterior a otra dosis ya dada).
        $this->assertSame('2026-11-26', IntervalosVacunas::conflicto('2026-11-04', ['2026-11-26'], 330));
        // Con varias, la más cercana.
        $this->assertSame('2026-11-20', IntervalosVacunas::conflicto('2026-11-26', ['2025-11-26', '2026-11-20'], 330));
        $this->assertNull(IntervalosVacunas::conflicto('2026-11-26', [], 330));
    }

    public function testLaFechaMinimaEsLaUltimaDadaMasLosDias(): void
    {
        $this->assertSame('2027-10-22', IntervalosVacunas::fechaMinima(['2026-01-01', '2026-11-26'], 330));
        $this->assertSame('2026-10-22', IntervalosVacunas::fechaMinima(['2026-10-01'], 21));
        $this->assertNull(IntervalosVacunas::fechaMinima([], 21));
    }

    public function testDiasEntre(): void
    {
        $this->assertSame(21, IntervalosVacunas::diasEntre('2026-10-01', '2026-10-22'));
        $this->assertSame(-21, IntervalosVacunas::diasEntre('2026-10-22', '2026-10-01'));
        $this->assertSame(365, IntervalosVacunas::diasEntre('2026-11-26', '2027-11-26'));
    }
}
