<?php

use PHPUnit\Framework\TestCase;

class ValidadorTransferenciaTest extends TestCase
{
    public function testAceptaDosIdsEnteros(): void
    {
        $this->assertSame([104, 103], ValidadorTransferencia::validar(['duenio_actual_id' => 104, 'nuevo_duenio_id' => 103]));
    }

    public function testRechazaIdsQueNoSonEnterosDeVerdad(): void
    {
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => '104', 'nuevo_duenio_id' => 103]));
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => 104, 'nuevo_duenio_id' => 103.0]));
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => 0, 'nuevo_duenio_id' => 103]));
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => 104, 'nuevo_duenio_id' => -1]));
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => true, 'nuevo_duenio_id' => 103]));
    }

    public function testRechazaCamposFaltantesOElMismoDuenio(): void
    {
        $this->assertNull(ValidadorTransferencia::validar([]));
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => 104]));
        $this->assertNull(ValidadorTransferencia::validar(['duenio_actual_id' => 104, 'nuevo_duenio_id' => 104]));
    }
}
