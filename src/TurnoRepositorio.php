<?php

// Acceso a la base de los turnos: la agenda del veterinario, los turnos del cliente y el cambio de estado.
// Todas las consultas SQL usan parámetros.
class TurnoRepositorio
{
    // Cada turno con su mascota, el dueño, el veterinario y la consulta (si ya se inició la atención).
    private const SELECT_TURNO =
        "SELECT t.id, t.fecha, TIME_FORMAT(t.hora, '%H:%i') AS hora, t.motivo, t.estado,
                m.id AS mascota_id, m.nombre AS mascota_nombre, m.especie,
                d.id AS duenio_id, d.nombre AS duenio_nombre, d.telefono AS duenio_telefono,
                v.id AS veterinario_id, v.nombre AS veterinario_nombre,
                c.id AS consulta_id
         FROM turno t
         JOIN mascota m ON m.id = t.mascota_id
         JOIN usuario d ON d.id = m.duenio_id
         JOIN usuario v ON v.id = t.veterinario_id
         LEFT JOIN consulta c ON c.turno_id = t.id";

    public function __construct(private PDO $db)
    {
    }

    // Todos los turnos de un día, en cualquier estado, ordenados por hora.
    public function agenda(string $fecha): array
    {
        return $this->turnos(self::SELECT_TURNO . ' WHERE t.fecha = ? ORDER BY t.hora, t.id', [$fecha]);
    }

    // Los turnos de las mascotas de un cliente desde una fecha en adelante (hasta 100).
    public function delCliente(int $clienteId, string $desde): array
    {
        return $this->turnos(
            self::SELECT_TURNO . ' WHERE m.duenio_id = ? AND t.fecha >= ? ORDER BY t.fecha, t.hora, t.id LIMIT 100',
            [$clienteId, $desde]
        );
    }

    // Un turno, o null si no existe.
    public function detalle(int $id): ?array
    {
        return $this->turnos(self::SELECT_TURNO . ' WHERE t.id = ?', [$id])[0] ?? null;
    }

    // Carga un turno PROGRAMADO. $datos viene de ValidadorTurno.
    // Devuelve el id nuevo, 'mascota_inexistente' (no existe o está dada de baja) u 'ocupado'
    // (el veterinario ya tiene un turno a esa hora; los cancelados no cuentan).
    public function crear(array $datos, int $veterinarioId): int|string
    {
        $activa = $this->db->prepare('SELECT 1 FROM mascota WHERE id = ? AND activo = 1');
        $activa->execute([$datos['mascota_id']]);

        if ($activa->fetchColumn() === false) {
            return 'mascota_inexistente';
        }

        try {
            $this->db->prepare(
                'INSERT INTO turno (mascota_id, veterinario_id, fecha, hora, motivo) VALUES (?, ?, ?, ?, ?)'
            )->execute([$datos['mascota_id'], $veterinarioId, $datos['fecha'], $datos['hora'], $datos['motivo']]);
        } catch (PDOException $error) {
            // 1062: choca con uq_turno_veterinario_horario (migración 004).
            if (($error->errorInfo[1] ?? null) === 1062) {
                return 'ocupado';
            }
            throw $error;
        }

        return (int) $this->db->lastInsertId();
    }

    // Cambia la fecha y la hora de un turno PROGRAMADO. Devuelve 'ok', 'inexistente', 'no_programado'
    // u 'ocupado' (el veterinario ya tiene otro turno a esa hora; los cancelados no cuentan).
    // Igual que cambiarEstado, el UPDATE comprueba el estado en la misma sentencia.
    public function reprogramar(int $id, string $fecha, string $hora): string
    {
        try {
            $sentencia = $this->db->prepare(
                "UPDATE turno SET fecha = ?, hora = ? WHERE id = ? AND estado = 'PROGRAMADO'"
            );
            $sentencia->execute([$fecha, $hora, $id]);
        } catch (PDOException $error) {
            // 1062: choca con uq_turno_veterinario_horario (migración 004).
            if (($error->errorInfo[1] ?? null) === 1062) {
                return 'ocupado';
            }
            throw $error;
        }

        if ($sentencia->rowCount() === 1) {
            return 'ok';
        }

        // No cambió ninguna fila: no existe, no está programado, o ya tenía esa fecha y hora.
        $sentencia = $this->db->prepare('SELECT estado FROM turno WHERE id = ?');
        $sentencia->execute([$id]);
        $actual = $sentencia->fetchColumn();

        if ($actual === false) {
            return 'inexistente';
        }

        return $actual === 'PROGRAMADO' ? 'ok' : 'no_programado';
    }

    // Pasa un turno PROGRAMADO a $estado (CANCELADO o AUSENTE). Con $hastaFecha, solo si el turno es de
    // ese día o anterior. Devuelve 'ok', 'inexistente', 'no_programado' o 'futuro'.
    // El UPDATE comprueba el estado en la misma sentencia: si dos pedidos llegan juntos, cambia uno solo.
    public function cambiarEstado(int $id, string $estado, ?string $hastaFecha = null): string
    {
        $sql = "UPDATE turno SET estado = ? WHERE id = ? AND estado = 'PROGRAMADO'";
        $parametros = [$estado, $id];

        if ($hastaFecha !== null) {
            $sql .= ' AND fecha <= ?';
            $parametros[] = $hastaFecha;
        }

        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);

        if ($sentencia->rowCount() === 1) {
            return 'ok';
        }

        // No cambió: se averigua por qué, para responder el error que corresponde.
        $sentencia = $this->db->prepare('SELECT estado FROM turno WHERE id = ?');
        $sentencia->execute([$id]);
        $actual = $sentencia->fetchColumn();

        if ($actual === false) {
            return 'inexistente';
        }

        return $actual === 'PROGRAMADO' ? 'futuro' : 'no_programado';
    }

    // ---------- Ayudas ----------

    // Ejecuta la consulta y arma cada turno con la forma del contrato.
    private function turnos(string $sql, array $parametros): array
    {
        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);

        return array_map(fn (array $fila): array => [
            'id' => (int) $fila['id'],
            'fecha' => $fila['fecha'],
            'hora' => $fila['hora'],
            'motivo' => $fila['motivo'],
            'estado' => $fila['estado'],
            'mascota' => [
                'id' => (int) $fila['mascota_id'],
                'nombre' => $fila['mascota_nombre'],
                'especie' => $fila['especie']
            ],
            'duenio' => [
                'id' => (int) $fila['duenio_id'],
                'nombre' => $fila['duenio_nombre'],
                'telefono' => $fila['duenio_telefono']
            ],
            'veterinario' => [
                'id' => (int) $fila['veterinario_id'],
                'nombre' => $fila['veterinario_nombre']
            ],
            'consulta_id' => $fila['consulta_id'] === null ? null : (int) $fila['consulta_id']
        ], $sentencia->fetchAll());
    }
}
