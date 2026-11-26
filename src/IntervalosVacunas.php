<?php

// Cuántos días tienen que pasar, como mínimo, entre dos dosis de la misma vacuna o desparasitación
// (26/11). Los configura la veterinaria en Configuración → Vacunas (tabla configuracion, catálogo en
// Backend: CatalogoConfiguracion); si no los cambió, se usan los de acá. Si se cambia un valor por
// defecto, hay que cambiarlo también en el catálogo de Backend.
//
// Dos reglas por vacuna:
//   - entre dosis: contra CUALQUIER dosis dada de la misma vacuna (la 1.ª y la 2.ª séxtuple: 21 días);
//   - de refuerzo: solo entre refuerzos (el ítem del plan que se repite: el refuerzo anual de la
//     séxtuple contra el refuerzo anterior, 330 días). Así el primer refuerzo, a las 52 semanas, no
//     choca con la 3.ª dosis de cachorro (16 semanas).
// La antirrábica, la tos de las perreras y la desparasitación tienen una sola regla.
// Las reglas (static) no usan la base: se prueban con PHPUnit.
class IntervalosVacunas
{
    public const MINIMO = 1;
    public const MAXIMO = 730;

    // aplicación del plan => [clave entre dosis, días por defecto, clave de refuerzo, días por defecto]
    public const CLAVES = [
        'SEXTUPLE' => ['dias_vacuna_sextuple_dosis', 21, 'dias_vacuna_sextuple_refuerzo', 330],
        'TRIPLE_FELINA' => ['dias_vacuna_triple_felina_dosis', 21, 'dias_vacuna_triple_felina_refuerzo', 330],
        'LEUCEMIA_FELINA' => ['dias_vacuna_leucemia_felina_dosis', 21, 'dias_vacuna_leucemia_felina_refuerzo', 330],
        'ANTIRRABICA' => ['dias_vacuna_antirrabica', 330, 'dias_vacuna_antirrabica', 330],
        'TOS_PERRERAS' => ['dias_vacuna_tos_perreras', 330, 'dias_vacuna_tos_perreras', 330],
        'ANTIPARASITARIO' => ['dias_desparasitacion', 14, 'dias_desparasitacion', 14],
    ];

    // Los días mínimos de una aplicación (entre dosis o de refuerzo), o null si no tiene regla.
    public static function dias(?string $aplicacion, bool $refuerzo): ?int
    {
        $claves = $aplicacion === null ? null : (self::CLAVES[$aplicacion] ?? null);
        if ($claves === null) {
            return null;
        }

        [$clave, $defecto] = $refuerzo ? [$claves[2], $claves[3]] : [$claves[0], $claves[1]];

        return class_exists('Configuracion')
            ? Configuracion::entero($clave, $defecto, self::MINIMO, self::MAXIMO)
            : $defecto;
    }

    // La fecha dada más cercana a $fecha que está a menos de $dias de ella, o null si todas respetan
    // el mínimo. $aplicadas: fechas AAAA-MM-DD.
    public static function conflicto(string $fecha, array $aplicadas, int $dias): ?string
    {
        $mas = null;
        $distancia = null;
        foreach ($aplicadas as $aplicada) {
            $diferencia = abs(self::diasEntre($aplicada, $fecha));
            if ($diferencia < $dias && ($distancia === null || $diferencia < $distancia)) {
                $mas = $aplicada;
                $distancia = $diferencia;
            }
        }

        return $mas;
    }

    // La primera fecha posible después de las dadas: la más nueva + $dias. null si no hay dadas.
    public static function fechaMinima(array $aplicadas, int $dias): ?string
    {
        if ($aplicadas === []) {
            return null;
        }

        return (new DateTimeImmutable(max($aplicadas)))->modify("+$dias days")->format('Y-m-d');
    }

    // Días de $desde a $hasta (negativo si $hasta es anterior).
    public static function diasEntre(string $desde, string $hasta): int
    {
        return (int) (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->format('%r%a');
    }
}
