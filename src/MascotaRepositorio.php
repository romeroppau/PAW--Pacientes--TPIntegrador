<?php

// Acceso a la base de las mascotas. Todas las consultas SQL usan parámetros.
class MascotaRepositorio
{
    // Cada mascota con su dueño y la edad en meses al día $hoy (el primer parámetro de cada consulta).
    private const SELECT_MASCOTA =
        'SELECT m.id, m.nombre, m.especie, m.raza, m.fecha_nacimiento,
                TIMESTAMPDIFF(MONTH, m.fecha_nacimiento, ?) AS edad_meses,
                m.peso_kg, m.foto IS NOT NULL AS tiene_foto, m.activo,
                d.id AS duenio_id, d.nombre AS duenio_nombre, d.telefono AS duenio_telefono
         FROM mascota m
         JOIN usuario d ON d.id = m.duenio_id';

    public function __construct(private PDO $db)
    {
    }

    // Mascotas activas ordenadas por nombre (hasta 100). Con $duenioId, solo las de ese cliente.
    // Con $buscar, las que tienen ese texto en su nombre o en el del dueño.
    public function listar(string $hoy, ?int $duenioId, string $buscar = ''): array
    {
        $sql = self::SELECT_MASCOTA . ' WHERE m.activo = 1';
        $parametros = [$hoy];

        if ($duenioId !== null) {
            $sql .= ' AND m.duenio_id = ?';
            $parametros[] = $duenioId;
        }

        if ($buscar !== '') {
            // Los comodines de LIKE (% y _) que escriba el usuario se buscan como texto.
            $patron = '%' . addcslashes($buscar, '%_\\') . '%';
            $sql .= ' AND (m.nombre LIKE ? OR d.nombre LIKE ?)';
            array_push($parametros, $patron, $patron);
        }

        return $this->mascotas($sql . ' ORDER BY m.nombre, m.id LIMIT 100', $parametros);
    }

    // Una mascota, activa o no, o null si no existe.
    public function detalle(int $id, string $hoy): ?array
    {
        return $this->mascotas(self::SELECT_MASCOTA . ' WHERE m.id = ?', [$hoy, $id])[0] ?? null;
    }

    // Da de alta una mascota y le arma su calendario sanitario, en una sola transacción.
    // $datos viene de ValidadorMascota. Devuelve el id nuevo.
    public function crear(int $duenioId, array $datos, string $hoy): int
    {
        $this->db->beginTransaction();

        try {
            [$foto, $fotoTipo] = $datos['foto'] ?? [null, null];
            $this->db->prepare(
                'INSERT INTO mascota (duenio_id, nombre, especie, raza, fecha_nacimiento, peso_kg, foto, foto_tipo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $duenioId, $datos['nombre'], $datos['especie'], $datos['raza'], $datos['fecha_nacimiento'], $datos['peso_kg'],
                $foto, $fotoTipo
            ]);
            $id = (int) $this->db->lastInsertId();

            (new CalendarioSanitario($this->db))->generarPara($id, $hoy);
            $this->db->commit();

            return $id;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    // Cambia los datos de una mascota. Si cambian la especie o la fecha de nacimiento, el calendario
    // suma los ítems del plan que le falten (no borra los que ya tenía).
    // Pasa la mascota a otro dueño (al completar una adopción, Entrega 5). Devuelve:
    //   'TRANSFERIDA'     si se cambió el dueño;
    //   'YA_TRANSFERIDA'  si ya era del nuevo dueño (un reintento: no se cambia nada);
    //   'NO_EXISTE'       si no existe o está dada de baja;
    //   'DUENIO_DISTINTO' si no es ni del dueño actual ni del nuevo;
    //   'NO_ES_CLIENTE'   si el nuevo dueño no es un cliente.
    // Solo cambia duenio_id: la ficha, los turnos, las consultas y el calendario siguen siendo de la
    // mascota, así que pasan con ella. La fila se bloquea (FOR UPDATE) para que dos pedidos a la vez
    // no la pasen dos veces.
    public function transferir(int $id, int $duenioActualId, int $nuevoDuenioId): string
    {
        $rol = $this->db->prepare('SELECT rol FROM usuario WHERE id = ?');
        $rol->execute([$nuevoDuenioId]);

        if ($rol->fetchColumn() !== 'CLIENTE') {
            return 'NO_ES_CLIENTE';
        }

        $this->db->beginTransaction();

        try {
            $consulta = $this->db->prepare('SELECT duenio_id, activo FROM mascota WHERE id = ? FOR UPDATE');
            $consulta->execute([$id]);
            $mascota = $consulta->fetch();

            $resultado = match (true) {
                $mascota === false || !(bool) $mascota['activo'] => 'NO_EXISTE',
                (int) $mascota['duenio_id'] === $nuevoDuenioId => 'YA_TRANSFERIDA',
                (int) $mascota['duenio_id'] !== $duenioActualId => 'DUENIO_DISTINTO',
                default => 'TRANSFERIDA',
            };

            if ($resultado === 'TRANSFERIDA') {
                $this->db->prepare('UPDATE mascota SET duenio_id = ? WHERE id = ?')->execute([$nuevoDuenioId, $id]);
            }

            $this->db->commit();

            return $resultado;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function actualizar(int $id, array $datos, string $hoy): void
    {
        $this->db->prepare(
            'UPDATE mascota SET nombre = ?, especie = ?, raza = ?, fecha_nacimiento = ?, peso_kg = ? WHERE id = ?'
        )->execute([
            $datos['nombre'], $datos['especie'], $datos['raza'], $datos['fecha_nacimiento'], $datos['peso_kg'], $id
        ]);

        // La foto cambia solo si vino en el pedido (null la borra).
        if (array_key_exists('foto', $datos)) {
            [$foto, $fotoTipo] = $datos['foto'] ?? [null, null];
            $this->db->prepare('UPDATE mascota SET foto = ?, foto_tipo = ? WHERE id = ?')->execute([$foto, $fotoTipo, $id]);
        }

        (new CalendarioSanitario($this->db))->generarPara($id, $hoy);
    }

    // La foto de una mascota: [bytes, tipo], o null si no tiene.
    public function foto(int $id): ?array
    {
        $sentencia = $this->db->prepare('SELECT foto, foto_tipo FROM mascota WHERE id = ? AND foto IS NOT NULL');
        $sentencia->execute([$id]);
        $fila = $sentencia->fetch();

        return $fila === false ? null : [$fila['foto'], $fila['foto_tipo']];
    }

    // Baja lógica: la ficha, el historial y el calendario se conservan.
    public function darDeBaja(int $id): void
    {
        $this->db->prepare('UPDATE mascota SET activo = 0 WHERE id = ?')->execute([$id]);
    }

    // Cuántos turnos PROGRAMADO tiene la mascota de hoy en adelante.
    public function turnosProgramados(int $id, string $hoy): int
    {
        $sentencia = $this->db->prepare(
            "SELECT COUNT(*) FROM turno WHERE mascota_id = ? AND estado = 'PROGRAMADO' AND fecha >= ?"
        );
        $sentencia->execute([$id, $hoy]);

        return (int) $sentencia->fetchColumn();
    }

    // ---------- Ayudas ----------

    // Ejecuta la consulta y arma cada mascota con la forma del contrato.
    private function mascotas(string $sql, array $parametros): array
    {
        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);

        return array_map(fn (array $fila): array => [
            'id' => (int) $fila['id'],
            'nombre' => $fila['nombre'],
            'especie' => $fila['especie'],
            'raza' => $fila['raza'],
            'fecha_nacimiento' => $fila['fecha_nacimiento'],
            'edad_meses' => $fila['edad_meses'] === null ? null : (int) $fila['edad_meses'],
            'peso_kg' => $fila['peso_kg'],
            'tiene_foto' => (bool) $fila['tiene_foto'],
            'activo' => (bool) $fila['activo'],
            'duenio' => [
                'id' => (int) $fila['duenio_id'],
                'nombre' => $fila['duenio_nombre'],
                'telefono' => $fila['duenio_telefono']
            ]
        ], $sentencia->fetchAll());
    }
}
