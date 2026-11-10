<?php

// Validación de los datos de una mascota (alta y edición). No usa la base de datos.
// Devuelve [$limpios, $errores]: los datos listos para guardar y un error por campo, igual que
// ValidadorRegistro de Backend, así el front muestra cada error debajo de su campo.
class ValidadorMascota
{
    public const ESPECIES = ['PERRO', 'GATO', 'OTRO'];
    private const LARGO_NOMBRE = 80;
    private const LARGO_RAZA = 80;
    private const PESO_MAXIMO = 999.99; // DECIMAL(5,2)
    private const EDAD_MAXIMA_ANIOS = 40;

    public static function validar(array $datos, string $hoy): array
    {
        $limpios = [];
        $errores = [];

        // Nombre: obligatorio.
        $nombre = self::texto($datos, 'nombre');
        if ($nombre === null || $nombre === '') {
            $errores['nombre'] = 'El nombre es obligatorio';
        } elseif (mb_strlen($nombre) > self::LARGO_NOMBRE) {
            $errores['nombre'] = 'El nombre puede tener hasta ' . self::LARGO_NOMBRE . ' caracteres';
        } else {
            $limpios['nombre'] = $nombre;
        }

        // Especie: obligatoria, uno de los códigos.
        $especie = $datos['especie'] ?? null;
        if (!is_string($especie) || !in_array($especie, self::ESPECIES, true)) {
            $errores['especie'] = 'Elegí la especie';
        } else {
            $limpios['especie'] = $especie;
        }

        // Raza: opcional.
        $raza = self::texto($datos, 'raza');
        if ($raza !== null && mb_strlen($raza) > self::LARGO_RAZA) {
            $errores['raza'] = 'La raza puede tener hasta ' . self::LARGO_RAZA . ' caracteres';
        } else {
            $limpios['raza'] = $raza === '' ? null : $raza;
        }

        // Fecha de nacimiento: opcional (sin ella no se arma el calendario sanitario).
        $nacimiento = self::texto($datos, 'fecha_nacimiento');
        if ($nacimiento === null || $nacimiento === '') {
            $limpios['fecha_nacimiento'] = null;
        } elseif (ValidadorFiltros::validarFecha($nacimiento) !== []) {
            $errores['fecha_nacimiento'] = 'La fecha de nacimiento no es válida';
        } elseif ($nacimiento > $hoy) {
            $errores['fecha_nacimiento'] = 'La fecha de nacimiento no puede ser futura';
        } elseif ($nacimiento < (new DateTimeImmutable($hoy))->modify('-' . self::EDAD_MAXIMA_ANIOS . ' years')->format('Y-m-d')) {
            $errores['fecha_nacimiento'] = 'La fecha de nacimiento no puede ser de hace más de ' . self::EDAD_MAXIMA_ANIOS . ' años';
        } else {
            $limpios['fecha_nacimiento'] = $nacimiento;
        }

        // Peso: opcional, en kg, mayor a 0.
        $peso = $datos['peso_kg'] ?? null;
        if ($peso === null || $peso === '') {
            $limpios['peso_kg'] = null;
        } elseif (!is_numeric($peso) || (float) $peso <= 0 || (float) $peso > self::PESO_MAXIMO) {
            $errores['peso_kg'] = 'El peso debe ser un número mayor a 0 y de hasta ' . self::PESO_MAXIMO . ' kg';
        } else {
            $limpios['peso_kg'] = number_format((float) $peso, 2, '.', '');
        }

        // Foto: opcional. Si no viene, no cambia; null la borra; un data URL la reemplaza
        // (PNG, JPG o WEBP de hasta 1 MB, revisando el contenido real del archivo).
        if (array_key_exists('foto', $datos)) {
            if ($datos['foto'] === null) {
                $limpios['foto'] = null;
            } else {
                $foto = Imagen::desdeDataUrl($datos['foto']);
                if (is_string($foto)) {
                    $errores['foto'] = $foto;
                } else {
                    $limpios['foto'] = $foto;   // [bytes, tipo]
                }
            }
        }

        return [$limpios, $errores];
    }

    // Un campo de texto sin espacios de más, o null si no llegó o no es texto.
    private static function texto(array $datos, string $campo): ?string
    {
        return isset($datos[$campo]) && is_string($datos[$campo]) ? trim($datos[$campo]) : null;
    }
}
