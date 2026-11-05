<?php

// Acceso a la base de las consultas, sus ítems y su factura. Todas las consultas SQL usan parámetros.
// Los nombres de tabla y columna salen de TIPOS, nunca del pedido.
class ConsultaRepositorio
{
    private const TIPOS = [
        'insumos' => [
            'item' => 'item_insumo_consulta', 'catalogo' => 'insumo',
            'columna' => 'insumo_id', 'precio' => 'costo_unitario', 'origen' => 'costo'
        ],
        'productos' => [
            'item' => 'item_producto_consulta', 'catalogo' => 'producto',
            'columna' => 'producto_id', 'precio' => 'precio_unitario', 'origen' => 'precio'
        ]
    ];

    public function __construct(private PDO $db)
    {
    }

    // Crea la consulta de un turno PROGRAMADO y lo pasa a EN_ATENCION, en una sola transacción.
    // Devuelve ['estado' => 'ok', 'id' => n], 'turno_inexistente' o 'no_programado'.
    public function iniciarAtencion(int $turnoId): array
    {
        return $this->enTransaccion(function () use ($turnoId): array {
            $estado = $this->valor('SELECT estado FROM turno WHERE id = ? FOR UPDATE', [$turnoId]);

            if ($estado === null) {
                return ['estado' => 'turno_inexistente'];
            }

            if ($estado !== 'PROGRAMADO') {
                return ['estado' => 'no_programado'];
            }

            // valor_base no admite NULL: arranca en 0 y el veterinario lo carga después.
            $this->ejecutar('INSERT INTO consulta (turno_id, valor_base) VALUES (?, 0)', [$turnoId]);
            $id = (int) $this->db->lastInsertId();
            $this->ejecutar("UPDATE turno SET estado = 'EN_ATENCION' WHERE id = ?", [$turnoId]);

            return ['estado' => 'ok', 'id' => $id];
        });
    }

    public function estado(int $id): ?string
    {
        return $this->valor('SELECT estado FROM consulta WHERE id = ?', [$id]);
    }

    // La consulta con sus ítems y el total calculado, o null si no existe.
    public function detalle(int $id): ?array
    {
        $consulta = $this->fila(
            'SELECT c.id, c.turno_id, c.diagnostico, c.observaciones, c.valor_base, c.estado,
                    c.finalizada_en, t.mascota_id, t.motivo
             FROM consulta c JOIN turno t ON t.id = c.turno_id
             WHERE c.id = ?',
            [$id]
        );

        if ($consulta === null) {
            return null;
        }

        $consulta['insumos'] = $this->items($id, 'insumos');
        $consulta['productos'] = $this->items($id, 'productos');
        $consulta['total'] = FacturaConsulta::calcular(
            $consulta['valor_base'],
            $consulta['insumos'],
            $consulta['productos']
        )['total'];

        return $consulta;
    }

    // Ítems de la consulta. $tipo: 'insumos' o 'productos'.
    public function items(int $consultaId, string $tipo): array
    {
        $t = self::TIPOS[$tipo];

        return $this->filas(
            "SELECT i.id, i.{$t['columna']}, c.nombre, i.cantidad, i.{$t['precio']}
             FROM {$t['item']} i JOIN {$t['catalogo']} c ON c.id = i.{$t['columna']}
             WHERE i.consulta_id = ? ORDER BY i.id",
            [$consultaId]
        );
    }

    // Cambia solo los campos presentes en $cambios (diagnostico, observaciones, valor_base).
    public function actualizar(int $id, array $cambios): void
    {
        $sets = [];
        $parametros = [];

        foreach (['diagnostico', 'observaciones', 'valor_base'] as $campo) {
            if (array_key_exists($campo, $cambios)) {
                $sets[] = "$campo = ?";
                $parametros[] = $cambios[$campo];
            }
        }

        if ($sets === []) {
            return;
        }

        $parametros[] = $id;
        $this->ejecutar(
            'UPDATE consulta SET ' . implode(', ', $sets) . " WHERE id = ? AND estado = 'ABIERTA'",
            $parametros
        );
    }

    // Agrega un insumo o producto; si ya estaba, suma la cantidad. Copia el costo o precio de ese momento.
    // Devuelve 'ok', 'consulta_inexistente', 'finalizada' o 'item_inexistente'.
    public function agregarItem(int $consultaId, string $tipo, int $itemId, int $cantidad): string
    {
        $t = self::TIPOS[$tipo];

        return $this->enTransaccion(function () use ($consultaId, $t, $itemId, $cantidad): string {
            $estado = $this->valor('SELECT estado FROM consulta WHERE id = ? FOR UPDATE', [$consultaId]);

            if ($estado === null) {
                return 'consulta_inexistente';
            }

            if ($estado !== 'ABIERTA') {
                return 'finalizada';
            }

            $precio = $this->valor(
                "SELECT {$t['origen']} FROM {$t['catalogo']} WHERE id = ? AND activo = 1",
                [$itemId]
            );

            if ($precio === null) {
                return 'item_inexistente';
            }

            $this->ejecutar(
                "INSERT INTO {$t['item']} (consulta_id, {$t['columna']}, cantidad, {$t['precio']})
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)",
                [$consultaId, $itemId, $cantidad, $precio]
            );

            return 'ok';
        });
    }

    // Quita un ítem de una consulta abierta. Mismos resultados que agregarItem.
    public function quitarItem(int $consultaId, string $tipo, int $itemId): string
    {
        $t = self::TIPOS[$tipo];

        return $this->enTransaccion(function () use ($consultaId, $t, $itemId): string {
            $estado = $this->valor('SELECT estado FROM consulta WHERE id = ? FOR UPDATE', [$consultaId]);

            if ($estado === null) {
                return 'consulta_inexistente';
            }

            if ($estado !== 'ABIERTA') {
                return 'finalizada';
            }

            $borradas = $this->ejecutar(
                "DELETE FROM {$t['item']} WHERE id = ? AND consulta_id = ?",
                [$itemId, $consultaId]
            );

            return $borradas > 0 ? 'ok' : 'item_inexistente';
        });
    }

    // Cierra la consulta en una transacción: factura, consulta FINALIZADA y turno ATENDIDO.
    // Devuelve la factura, o null si la consulta no existe o ya estaba finalizada.
    public function finalizar(int $consultaId): ?array
    {
        return $this->enTransaccion(function () use ($consultaId): ?array {
            $consulta = $this->fila(
                'SELECT valor_base, estado, turno_id FROM consulta WHERE id = ? FOR UPDATE',
                [$consultaId]
            );

            if ($consulta === null || $consulta['estado'] !== 'ABIERTA') {
                return null;
            }

            $importes = FacturaConsulta::calcular(
                $consulta['valor_base'],
                $this->items($consultaId, 'insumos'),
                $this->items($consultaId, 'productos')
            );

            // El número definitivo usa el id de la factura, que se conoce recién después del INSERT.
            $this->ejecutar(
                'INSERT INTO factura_consulta
                   (numero, consulta_id, subtotal_base, subtotal_insumos, subtotal_productos, total)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    'C-TMP-' . $consultaId, $consultaId, $importes['subtotal_base'],
                    $importes['subtotal_insumos'], $importes['subtotal_productos'], $importes['total']
                ]
            );
            $facturaId = (int) $this->db->lastInsertId();
            $this->ejecutar(
                'UPDATE factura_consulta SET numero = ? WHERE id = ?',
                [FacturaConsulta::numero($facturaId), $facturaId]
            );

            $this->ejecutar(
                "UPDATE consulta SET estado = 'FINALIZADA', finalizada_en = NOW()
                 WHERE id = ? AND estado = 'ABIERTA'",
                [$consultaId]
            );
            $this->ejecutar("UPDATE turno SET estado = 'ATENDIDO' WHERE id = ?", [$consulta['turno_id']]);

            return $this->factura($consultaId);
        });
    }

    public function factura(int $consultaId): ?array
    {
        return $this->fila(
            'SELECT numero, subtotal_base, subtotal_insumos, subtotal_productos, total, estado, fecha
             FROM factura_consulta WHERE consulta_id = ?',
            [$consultaId]
        );
    }

    // Id del dueño de la mascota, o null si la mascota no existe.
    public function duenioDeMascota(int $mascotaId): ?int
    {
        $duenio = $this->valor('SELECT duenio_id FROM mascota WHERE id = ?', [$mascotaId]);

        return $duenio === null ? null : (int) $duenio;
    }

    // Consultas finalizadas de una mascota, de la más reciente a la más antigua (hasta 50).
    public function historial(int $mascotaId): array
    {
        $consultas = $this->filas(
            "SELECT c.id, c.diagnostico, c.observaciones, c.valor_base, c.finalizada_en, t.fecha
             FROM consulta c JOIN turno t ON t.id = c.turno_id
             WHERE t.mascota_id = ? AND c.estado = 'FINALIZADA'
             ORDER BY c.finalizada_en DESC, c.id DESC LIMIT 50",
            [$mascotaId]
        );

        foreach ($consultas as &$consulta) {
            $consulta['insumos'] = $this->items($consulta['id'], 'insumos');
            $consulta['productos'] = $this->items($consulta['id'], 'productos');
            $consulta['factura'] = $this->factura($consulta['id']);
        }

        return $consultas;
    }

    // ---------- Ayudas ----------

    // Ejecuta la acción dentro de una transacción: confirma si termina bien y deshace si lanza un error.
    private function enTransaccion(callable $accion): mixed
    {
        $this->db->beginTransaction();

        try {
            $resultado = $accion();
            $this->db->commit();

            return $resultado;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    // Ejecuta una sentencia y devuelve las filas afectadas.
    private function ejecutar(string $sql, array $parametros): int
    {
        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->rowCount();
    }

    private function filas(string $sql, array $parametros): array
    {
        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->fetchAll();
    }

    private function fila(string $sql, array $parametros): ?array
    {
        $filas = $this->filas($sql, $parametros);

        return $filas[0] ?? null;
    }

    // Primera columna de la primera fila, o null si no hay filas.
    private function valor(string $sql, array $parametros): mixed
    {
        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);
        $valor = $sentencia->fetchColumn();

        return $valor === false ? null : $valor;
    }
}