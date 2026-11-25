<?php

use PHPUnit\Framework\TestCase;

// Los avisos al dueño cuando la veterinaria da, reprograma o cancela un turno (src/AvisosTurno.php).
// Solo arman los avisos: no llaman a Backend.
class AvisosTurnoTest extends TestCase
{
    private function turno(array $extra = []): array
    {
        return array_merge([
            'id' => 57, 'fecha' => '2026-11-26', 'hora' => '10:30:00', 'motivo' => 'VACUNACION', 'estado' => 'PROGRAMADO',
            'mascota' => ['id' => 102, 'nombre' => 'Michi', 'especie' => 'GATO'],
            'duenio' => ['id' => 102, 'nombre' => 'Sofía Benítez', 'telefono' => '1155550102'],
            'precio' => ['base' => 15000.0, 'descuento' => 15, 'final' => 12750.0],
        ], $extra);
    }

    public function testAlDarElTurnoSaleUnAvisoPorCampanaYOtroPorWhatsAppAlDuenio(): void
    {
        $avisos = AvisosTurno::asignado($this->turno());

        $this->assertCount(2, $avisos);
        $this->assertSame(['WEB', 'WHATSAPP'], array_column($avisos, 'canal'));
        $this->assertSame([102, 102], array_column($avisos, 'usuario_id'));
        $this->assertSame(['TURNO', 'TURNO'], array_column($avisos, 'tipo'));
        $this->assertSame(['turno-57-asignado-web', 'turno-57-asignado-whatsapp'], array_column($avisos, 'id_operacion'));
        $this->assertSame(
            'Tenés turno de vacunación para Michi el jueves 26/11 a las 10:30 en HuellaVet. La consulta: $ 12.750 (15 % de descuento). Si no podés ir, avisanos por WhatsApp.',
            $avisos[0]['mensaje']
        );
    }

    public function testReprogramarYCancelarTienenSuPropioIdOperacion(): void
    {
        $reprogramado = AvisosTurno::reprogramado($this->turno(['fecha' => '2026-11-27']), '20261125103000');
        $this->assertSame('turno-57-reprogramado-20261125103000-whatsapp', $reprogramado[1]['id_operacion']);
        $this->assertStringStartsWith('Cambiamos el turno de Michi: ahora es el viernes 27/11 a las 10:30', $reprogramado[0]['mensaje']);

        $cancelado = AvisosTurno::cancelado($this->turno());
        $this->assertSame('turno-57-cancelado-web', $cancelado[0]['id_operacion']);
        $this->assertSame('Cancelamos el turno de vacunación de Michi del jueves 26/11 a las 10:30. Si querés otro, escribinos por WhatsApp.', $cancelado[0]['mensaje']);
    }

    public function testSinPrecioGuardadoNoLoMenciona(): void
    {
        $avisos = AvisosTurno::asignado($this->turno(['precio' => null, 'motivo' => 'CONSULTA']));
        $this->assertStringNotContainsString('$', $avisos[0]['mensaje']);
        $this->assertStringContainsString('Tenés turno de consulta para Michi', $avisos[0]['mensaje']);
    }

    // Migración 025: si el turno es para algo del calendario, el aviso dice qué.
    public function testElAvisoDiceQueVacunaSeDa(): void
    {
        $avisos = AvisosTurno::asignado($this->turno(['evento_sanitario' => ['id' => 9, 'nombre' => 'Antirrábica', 'aplicacion' => 'ANTIRRABICA']]));
        $this->assertStringStartsWith('Tenés turno de vacunación (Antirrábica) para Michi', $avisos[0]['mensaje']);

        $avisos = AvisosTurno::asignado($this->turno(['evento_sanitario' => ['id' => 9, 'nombre' => 'Leucemia felina (2.ª dosis)', 'aplicacion' => 'LEUCEMIA_FELINA']]));
        $this->assertStringStartsWith('Tenés turno de vacunación (Leucemia felina, 2.ª dosis) para Michi', $avisos[0]['mensaje']);

        $avisos = AvisosTurno::asignado($this->turno(['motivo' => 'CASTRACION']));
        $this->assertStringStartsWith('Tenés turno de castración para Michi', $avisos[0]['mensaje']);
    }
}
