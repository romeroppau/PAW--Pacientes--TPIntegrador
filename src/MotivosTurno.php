<?php

// Los motivos de cada turno (migración 026): un turno, y su consulta, puede tener más de uno. Cada
// motivo es una fila de turno_motivo; los de vacunación y desparasitación dicen qué evento del
// calendario se da. turno.motivo queda como el motivo principal (principal()), que siguen leyendo
// Adopciones y Reportes.
class MotivosTurno
{
    // Los motivos de cada turno, por id: [turnoId => [['motivo' => ..., 'evento_sanitario' => null|{id, nombre, aplicacion}], ...]].
    // $principales: [turnoId => turno.motivo]. Un turno sin filas en turno_motivo (las semillas, por
    // ejemplo) tiene un solo motivo: el principal.
    public static function deTurnos(PDO $db, array $principales): array
    {
        $resultado = [];
        foreach ($principales as $id => $motivo) {
            $resultado[$id] = [];
        }

        if ($principales !== []) {
            $marcas = implode(', ', array_fill(0, count($principales), '?'));
            $sentencia = $db->prepare(
                "SELECT tm.turno_id, tm.motivo, es.id AS evento_id, es.nombre AS evento_nombre, ps.aplicacion AS evento_aplicacion
                 FROM turno_motivo tm
                 LEFT JOIN evento_sanitario es ON es.id = tm.evento_sanitario_id
                 LEFT JOIN plan_sanitario ps ON ps.id = es.plan_sanitario_id
                 WHERE tm.turno_id IN ($marcas) ORDER BY tm.id"
            );
            $sentencia->execute(array_map('intval', array_keys($principales)));

            foreach ($sentencia->fetchAll() as $fila) {
                $resultado[(int) $fila['turno_id']][] = [
                    'motivo' => $fila['motivo'],
                    'evento_sanitario' => $fila['evento_id'] === null ? null : [
                        'id' => (int) $fila['evento_id'],
                        'nombre' => $fila['evento_nombre'],
                        'aplicacion' => $fila['evento_aplicacion'],
                    ],
                ];
            }
        }

        foreach ($resultado as $id => $motivos) {
            if ($motivos === []) {
                $resultado[$id] = [['motivo' => $principales[$id], 'evento_sanitario' => null]];
            }
        }

        return $resultado;
    }

    // Los eventos del calendario que se dan en un turno (los de sus motivos de vacunación y desparasitación).
    public static function eventos(array $motivos): array
    {
        return array_values(array_filter(array_column($motivos, 'evento_sanitario')));
    }

    // El motivo principal, el que se guarda en turno.motivo: el chequeo de adopción si está (Adopciones
    // lo busca ahí); si no, el primero.
    public static function principal(array $motivos): string
    {
        $codigos = array_column($motivos, 'motivo');

        return in_array('CHEQUEO_ADOPCION', $codigos, true) ? 'CHEQUEO_ADOPCION' : $codigos[0];
    }
}
