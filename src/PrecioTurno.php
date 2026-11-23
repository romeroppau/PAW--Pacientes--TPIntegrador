<?php

// El precio de la consulta de un turno y las promociones de franjas (migración 020).
// Contrato: docs/api/contrato-mascotas-turnos.md, sección "Precio del turno y franjas promocionadas".
// No usa la base: el SQL está en PromocionFranjaRepositorio y TurnoRepositorio.
// - Franja: un día de lunes a sábado (1 a 6) y mañana (antes de las 13:00) o tarde. Es la misma que
//   usa el motor de Reportes para sugerir qué promocionar.
// - El precio base sale de la configuración (precio_consulta, catálogo en Backend). Si la franja del
//   turno tiene una promoción vigente que llega hasta el día del turno, se le aplica el descuento.
// - El turno guarda su precio al sacarse (y al reprogramarse): no le cambia si después termina la
//   promoción o cambia el precio base.
class PrecioTurno
{
    public const PRECIO_POR_DEFECTO = 15000;      // el mismo valor por defecto que el catálogo de Backend
    public const PRECIO_MAXIMO = 1000000;
    public const PORCENTAJE_MINIMO = 5;
    public const PORCENTAJE_MAXIMO = 90;
    public const DIAS_MAXIMOS = 90;
    public const HORA_TARDE = '13:00';

    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'];
    public const FRANJAS = ['MANIANA' => 'mañana', 'TARDE' => 'tarde'];

    // El precio base de la consulta (configuración), en pesos.
    public static function precioBase(): float
    {
        return (float) Configuracion::entero('precio_consulta', self::PRECIO_POR_DEFECTO, 0, self::PRECIO_MAXIMO);
    }

    // La franja de un turno: ['dia_semana' => 1..7, 'franja' => 'MANIANA'|'TARDE']. 7 = domingo (sin franja).
    public static function franjaDe(string $fecha, string $hora): array
    {
        return [
            'dia_semana' => (int) (new DateTimeImmutable($fecha))->format('N'),
            'franja' => substr($hora, 0, 5) < self::HORA_TARDE ? 'MANIANA' : 'TARDE',
        ];
    }

    // Lo que guarda el turno: el precio base y, si hay promoción, su porcentaje y su id.
    // $promocion: ['id' => int, 'porcentaje' => int] o null.
    public static function paraTurno(float $precioBase, ?array $promocion): array
    {
        return [
            'precio_consulta' => round($precioBase, 2),
            'descuento' => $promocion['porcentaje'] ?? null,
            'promocion_franja_id' => $promocion['id'] ?? null,
        ];
    }

    // El valor de la consulta: el precio base con el descuento, redondeado a centavos.
    public static function conDescuento(float $precio, ?int $descuento): float
    {
        return $descuento === null ? round($precio, 2) : round($precio * (100 - $descuento) / 100, 2);
    }

    // "Martes a la tarde"
    public static function nombreDeFranja(int $diaSemana, string $franja): string
    {
        return (self::DIAS[$diaSemana] ?? '?') . ' a la ' . (self::FRANJAS[$franja] ?? '?');
    }

    // Valida el pedido de "Publicitar" una franja: {dia_semana, franja, porcentaje, hasta}.
    // Devuelve [datos limpios, errores por campo].
    public static function validarPromocion(mixed $datos, DateTimeImmutable $hoy): array
    {
        $datos = is_array($datos) ? $datos : [];
        $limpios = [];
        $errores = [];

        $dia = $datos['dia_semana'] ?? null;
        if (is_string($dia) && ctype_digit($dia)) {
            $dia = (int) $dia;
        }
        if (!is_int($dia) || !isset(self::DIAS[$dia])) {
            $errores['dia_semana'] = 'Elegí un día de lunes a sábado';
        } else {
            $limpios['dia_semana'] = $dia;
        }

        $franja = $datos['franja'] ?? null;
        if (!is_string($franja) || !isset(self::FRANJAS[$franja])) {
            $errores['franja'] = 'Elegí mañana o tarde';
        } else {
            $limpios['franja'] = $franja;
        }

        $porcentaje = $datos['porcentaje'] ?? null;
        if (is_string($porcentaje) && ctype_digit($porcentaje)) {
            $porcentaje = (int) $porcentaje;
        }
        if (!is_int($porcentaje) || $porcentaje < self::PORCENTAJE_MINIMO || $porcentaje > self::PORCENTAJE_MAXIMO) {
            $errores['porcentaje'] = 'El descuento tiene que ser un número entero entre ' . self::PORCENTAJE_MINIMO
                . ' y ' . self::PORCENTAJE_MAXIMO . ' (por ciento)';
        } else {
            $limpios['porcentaje'] = $porcentaje;
        }

        $hoy = $hoy->setTime(0, 0);
        $maximo = $hoy->modify('+' . self::DIAS_MAXIMOS . ' days');
        $hasta = is_string($datos['hasta'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $datos['hasta'], $hoy->getTimezone()) : false;
        if ($hasta === false || $hasta->format('Y-m-d') !== $datos['hasta']) {
            $errores['hasta'] = 'Elegí hasta qué día dura la promoción';
        } elseif ($hasta < $hoy) {
            $errores['hasta'] = 'La promoción no puede terminar antes de hoy';
        } elseif ($hasta > $maximo) {
            $errores['hasta'] = 'La promoción puede durar como mucho ' . self::DIAS_MAXIMOS . ' días (hasta el '
                . $maximo->format('d/m/Y') . ')';
        } else {
            $limpios['hasta'] = $hasta->format('Y-m-d');
        }

        return [$limpios, $errores];
    }
}
