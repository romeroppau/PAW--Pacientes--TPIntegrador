<?php

use PHPUnit\Framework\TestCase;

class ValidadorMascotaTest extends TestCase
{
    private const HOY = '2026-11-06';

    public function testAceptaUnaMascotaCompleta(): void
    {
        [$limpios, $errores] = ValidadorMascota::validar([
            'nombre' => '  Firulais ', 'especie' => 'PERRO', 'raza' => 'Mestizo',
            'fecha_nacimiento' => '2023-11-05', 'peso_kg' => '18.5'
        ], self::HOY);

        $this->assertSame([], $errores);
        $this->assertSame('Firulais', $limpios['nombre']);
        $this->assertSame('18.50', $limpios['peso_kg']);
    }

    public function testSoloNombreYEspecieSonObligatorios(): void
    {
        [$limpios, $errores] = ValidadorMascota::validar(['nombre' => 'Tom', 'especie' => 'GATO', 'raza' => ''], self::HOY);

        $this->assertSame([], $errores);
        $this->assertNull($limpios['raza']);
        $this->assertNull($limpios['fecha_nacimiento']);
        $this->assertNull($limpios['peso_kg']);
    }

    public function testMarcaCadaCampoInvalido(): void
    {
        [, $errores] = ValidadorMascota::validar([
            'nombre' => '', 'especie' => 'PEZ', 'raza' => str_repeat('a', 81),
            'fecha_nacimiento' => '2026-02-30', 'peso_kg' => '-3'
        ], self::HOY);

        $this->assertSame(['nombre', 'especie', 'raza', 'fecha_nacimiento', 'peso_kg'], array_keys($errores));
    }

    public function testRechazaNacimientoFuturoOMuyViejo(): void
    {
        [, $futuro] = ValidadorMascota::validar(['nombre' => 'A', 'especie' => 'OTRO', 'fecha_nacimiento' => '2026-11-07'], self::HOY);
        [, $viejo] = ValidadorMascota::validar(['nombre' => 'A', 'especie' => 'OTRO', 'fecha_nacimiento' => '1980-01-01'], self::HOY);
        [, $hoy] = ValidadorMascota::validar(['nombre' => 'A', 'especie' => 'OTRO', 'fecha_nacimiento' => self::HOY], self::HOY);

        $this->assertArrayHasKey('fecha_nacimiento', $futuro);
        $this->assertArrayHasKey('fecha_nacimiento', $viejo);
        $this->assertSame([], $hoy);
    }

    public function testRechazaPesoNoNumericoOExcesivo(): void
    {
        [, $texto] = ValidadorMascota::validar(['nombre' => 'A', 'especie' => 'PERRO', 'peso_kg' => 'diez'], self::HOY);
        [, $enorme] = ValidadorMascota::validar(['nombre' => 'A', 'especie' => 'PERRO', 'peso_kg' => 1000], self::HOY);

        $this->assertArrayHasKey('peso_kg', $texto);
        $this->assertArrayHasKey('peso_kg', $enorme);
    }

    // PNG de 1 x 1 píxel.
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function testSinFotoNoLaCambia(): void
    {
        [$limpios] = ValidadorMascota::validar(['nombre' => 'Tom', 'especie' => 'GATO'], self::HOY);

        $this->assertArrayNotHasKey('foto', $limpios);
    }

    public function testUnaFotoValidaQuedaComoBytesYTipo(): void
    {
        [$limpios, $errores] = ValidadorMascota::validar(['nombre' => 'Tom', 'especie' => 'GATO', 'foto' => self::PNG], self::HOY);

        $this->assertSame([], $errores);
        $this->assertSame('image/png', $limpios['foto'][1]);
        $this->assertSame('89504e47', bin2hex(substr($limpios['foto'][0], 0, 4)));   // firma de un PNG
    }

    public function testFotoNullLaBorra(): void
    {
        [$limpios, $errores] = ValidadorMascota::validar(['nombre' => 'Tom', 'especie' => 'GATO', 'foto' => null], self::HOY);

        $this->assertSame([], $errores);
        $this->assertNull($limpios['foto']);
    }

    public function testRechazaUnaFotoQueNoEsImagen(): void
    {
        $texto = 'data:image/png;base64,' . base64_encode('esto no es una imagen');
        [, $errores] = ValidadorMascota::validar(['nombre' => 'Tom', 'especie' => 'GATO', 'foto' => $texto], self::HOY);

        $this->assertSame(['foto'], array_keys($errores));
    }

    public function testRechazaUnaFotoDeMasDeUnMega(): void
    {
        $grande = 'data:image/png;base64,' . base64_encode(str_repeat('a', 1024 * 1024 + 1));
        [, $errores] = ValidadorMascota::validar(['nombre' => 'Tom', 'especie' => 'GATO', 'foto' => $grande], self::HOY);

        $this->assertSame('La imagen no puede superar 1 MB', $errores['foto']);
    }
}
