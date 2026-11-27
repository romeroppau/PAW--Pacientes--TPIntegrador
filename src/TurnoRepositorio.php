<?php

// Acceso a la base de los turnos: la agenda del veterinario, los turnos del cliente y el cambio de estado.
// Todas las consultas SQL usan parámetros.
class TurnoRepositorio
{
    // Cada turno con su mascota, el dueño, el veterinario y la consulta (si ya se inició la atención).
    private const SELECT_TURNO =
        "SELECT t.id, t.fecha, TIME_FORMAT(t.hora, '%H:%i') AS hora, t.motivo, t.estado,
                t.precio_consulta, t.descuento,
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

    // La adopción que tiene este turno como chequeo (27/11): {adoptante_id, adoptante}, o null si el
    // turno no está asignado a ninguna adopción en curso. Lee la tabla adopcion (SELECT, permisos de
    // la migración 007). Si no se puede leer, null: el aviso sale como el de cualquier turno.
    public function adopcionDelChequeo(int $turnoId): ?array
    {
        try {
            $consulta = $this->db->prepare(
                "SELECT a.adoptante_id, u.nombre AS adoptante
                 FROM adopcion a JOIN usuario u ON u.id = a.adoptante_id
                 WHERE a.turno_chequeo_id = ? AND a.estado = 'CHEQUEO_PENDIENTE'"
            );
            $consulta->execute([$turnoId]);
            $fila = $consulta->fetch();
        } catch (PDOException $error) {
            Logger::error('Pacientes: no se pudo leer la adopción del chequeo ' . $turnoId . ': ' . $error->getMessage());
            return null;
        }

        return $fila === false ? null : ['adoptante_id' => (int) $fila['adoptante_id'], 'adoptante' => $fila['adoptante']];
    }

    // Carga un turno PROGRAMADO. $datos viene de ValidadorTurno; $precio, de PrecioTurno::paraTurno
    // (el precio base y el descuento de su franja, que el turno guarda: migración 020).
    // Devuelve el id nuevo, 'mascota_inexistente' (no existe o está dada de baja) u 'ocupado'
    // (el veterinario ya tiene un turno a esa hora; los cancelados no cuentan).
    public function crear(array $datos, int $veterinarioId, array $precio): int|string
    {
        $activa = $this->db->prepare('SELECT 1 FROM mascota WHERE id = ? AND activo = 1');
        $activa->execute([$datos['mascota_id']]);

        if ($activa->fetchColumn() === false) {
            return 'mascota_inexistente';
        }

        // El turno y sus motivos (migración 026) en una transacción: o se guardan todos, o ninguno.
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO turno (mascota_id, veterinario_id, fecha, hora, motivo, precio_consulta, descuento, promocion_franja_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $datos['mascota_id'], $veterinarioId, $datos['fecha'], $datos['hora'], $datos['motivo'],
                $precio['precio_consulta'], $precio['descuento'], $precio['promocion_franja_id'],
            ]);
            $id = (int) $this->db->lastInsertId();

            $motivo = $this->db->prepare('INSERT INTO turno_motivo (turno_id, motivo, evento_sanitario_id) VALUES (?, ?, ?)');
            foreach ($datos['motivos'] ?? [['motivo' => $datos['motivo']]] as $m) {
                $motivo->execute([$id, $m['motivo'], $m['evento_sanitario_id'] ?? null]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            // 1062: choca con uq_turno_veterinario_horario (migración 004).
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                return 'ocupado';
            }
            throw $error;
        }

        return $id;
    }

    // Cambia la fecha y la hora de un turno PROGRAMADO, con el precio de la franja nueva (reprogramar es
    // como sacarlo de nuevo: PrecioTurno::paraTurno). Devuelve 'ok', 'inexistente', 'no_programado'
    // u 'ocupado' (el veterinario ya tiene otro turno a esa hora; los cancelados no cuentan).
    // Igual que cambiarEstado, el UPDATE comprueba el estado en la misma sentencia.
    public function reprogramar(int $id, string $fecha, string $hora, array $precio): string
    {
        try {
            $sentencia = $this->db->prepare(
                "UPDATE turno SET fecha = ?, hora = ?, precio_consulta = ?, descuento = ?, promocion_franja_id = ?
                 WHERE id = ? AND estado = 'PROGRAMADO'"
            );
            $sentencia->execute([$fecha, $hora, $precio['precio_consulta'], $precio['descuento'], $precio['promocion_franja_id'], $id]);
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
        $filas = $sentencia->fetchAll();
        // Todos los motivos de cada turno (migración 026), con una sola consulta más.
        $motivos = MotivosTurno::deTurnos($this->db, array_column($filas, 'motivo', 'id'));

        return array_map(fn (array $fila): array => [
            'id' => (int) $fila['id'],
            'fecha' => $fila['fecha'],
            'hora' => $fila['hora'],
            // El motivo principal (MotivosTurno::principal); todos los motivos van en 'motivos', al final.
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
            'consulta_id' => $fila['consulta_id'] === null ? null : (int) $fila['consulta_id'],
            // El precio que guardó el turno al sacarse (null en los turnos de antes de la migración 020).
            'precio' => $fila['precio_consulta'] === null ? null : [
                'base' => (float) $fila['precio_consulta'],
                'descuento' => $fila['descuento'] === null ? null : (int) $fila['descuento'],
                'final' => PrecioTurno::conDescuento((float) $fila['precio_consulta'], $fila['descuento'] === null ? null : (int) $fila['descuento']),
            ],
            // Todos los motivos (migración 026), cada uno con el evento del calendario que se da
            // (vacunación o desparasitación) o null.
            'motivos' => $motivos[(int) $fila['id']],
        ], $filas);
    }
}
