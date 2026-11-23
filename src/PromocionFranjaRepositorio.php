<?php

// El SQL de las promociones de franjas (tabla promocion_franja, migración 020). Las reglas están en
// PrecioTurno. Todas las consultas son parametrizadas ("?"). Las promociones no se borran: terminar
// una completa terminada_en. "Hoy" lo pasa el controlador (hora de Argentina; MySQL trabaja en UTC).
class PromocionFranjaRepositorio
{
    public function __construct(private PDO $db)
    {
    }

    // Las promociones vigentes (no terminadas y que llegan hasta hoy o después), por día y franja.
    public function vigentes(string $hoy): array
    {
        $consulta = $this->db->prepare(
            "SELECT id, dia_semana, franja, porcentaje, DATE_FORMAT(hasta, '%Y-%m-%d') AS hasta
             FROM promocion_franja
             WHERE terminada_en IS NULL AND hasta >= ?
             ORDER BY dia_semana, franja"
        );
        $consulta->execute([$hoy]);

        return array_map(fn (array $f) => [
            'id' => (int) $f['id'],
            'dia_semana' => (int) $f['dia_semana'],
            'franja' => $f['franja'],
            'porcentaje' => (int) $f['porcentaje'],
            'hasta' => $f['hasta'],
        ], $consulta->fetchAll());
    }

    // La promoción que le corresponde a un turno de esa franja para ese día: vigente hoy y que llega
    // hasta el día del turno. ['id', 'porcentaje'] o null.
    public function paraTurno(int $diaSemana, string $franja, string $fechaTurno, string $hoy): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT id, porcentaje FROM promocion_franja
             WHERE dia_semana = ? AND franja = ? AND terminada_en IS NULL AND hasta >= ? AND hasta >= ?'
        );
        $consulta->execute([$diaSemana, $franja, $hoy, $fechaTurno]);
        $fila = $consulta->fetch();

        return $fila === false ? null : ['id' => (int) $fila['id'], 'porcentaje' => (int) $fila['porcentaje']];
    }

    // Publica la promoción y devuelve su id, o ['ya_tiene', vigente] si la franja ya tiene una.
    // Una anterior que ya pasó su fecha se termina sola (al final de su último día).
    public function crear(array $datos, int $usuarioId, string $ahora, string $hoy): int|array
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE promocion_franja SET terminada_en = TIMESTAMP(hasta + INTERVAL 1 DAY)
                 WHERE dia_semana = ? AND franja = ? AND terminada_en IS NULL AND hasta < ?'
            )->execute([$datos['dia_semana'], $datos['franja'], $hoy]);

            $vigente = $this->vigenteDe($datos['dia_semana'], $datos['franja'], $hoy);
            if ($vigente !== null) {
                $this->db->rollBack();
                return ['ya_tiene', $vigente];
            }

            $this->db->prepare(
                'INSERT INTO promocion_franja (dia_semana, franja, porcentaje, hasta, creada_en, creada_por)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$datos['dia_semana'], $datos['franja'], $datos['porcentaje'], $datos['hasta'], $ahora, $usuarioId]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Dos "Publicitar" al mismo tiempo: la base rechaza el segundo (índice único).
            if (($error->errorInfo[1] ?? null) === 1062 && ($vigente = $this->vigenteDe($datos['dia_semana'], $datos['franja'], $hoy)) !== null) {
                return ['ya_tiene', $vigente];
            }
            throw $error;
        }

        return $id;
    }

    // Termina una promoción vigente ahora. false si no hay una vigente con ese id.
    // Los turnos que ya la tomaron la conservan.
    public function terminar(int $id, string $ahora, string $hoy): bool
    {
        $consulta = $this->db->prepare(
            'UPDATE promocion_franja SET terminada_en = ? WHERE id = ? AND terminada_en IS NULL AND hasta >= ?'
        );
        $consulta->execute([$ahora, $id, $hoy]);

        return $consulta->rowCount() > 0;
    }

    private function vigenteDe(int $diaSemana, string $franja, string $hoy): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT id, porcentaje, DATE_FORMAT(hasta, '%Y-%m-%d') AS hasta FROM promocion_franja
             WHERE dia_semana = ? AND franja = ? AND terminada_en IS NULL AND hasta >= ?"
        );
        $consulta->execute([$diaSemana, $franja, $hoy]);
        $fila = $consulta->fetch();

        return $fila === false ? null : ['id' => (int) $fila['id'], 'porcentaje' => (int) $fila['porcentaje'], 'hasta' => $fila['hasta']];
    }
}
