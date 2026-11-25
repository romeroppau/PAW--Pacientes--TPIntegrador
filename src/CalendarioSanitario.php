<?php

// Calendario sanitario de cada mascota (plan_sanitario y evento_sanitario).
// Contrato y reglas en docs/api/contrato-calendario.md. La consulta llama a marcarAplicado() al
// finalizar un turno de vacunación o desparasitación.
// Desde la migración 025 (25/11) el calendario manda: un turno de vacunación o desparasitación solo se
// saca para un evento del calendario que todavía no se dio (eventoParaTurno), respetando el orden de
// las dosis, y el veterinario edita el calendario (agregar, marcarDada, cambiarFecha, quitar).
// El calendario no se arma solo: lo carga el veterinario, de a una, y una dosis se agrega recién con
// las anteriores de la misma vacuna dadas (25/11).
// Las reglas de fechas y estados (los métodos static) no usan la base: se prueban con PHPUnit.
class CalendarioSanitario
{
    // Un evento sin aplicar es PROXIMO si le toca dentro de estos días.
    public const DIAS_PROXIMO = 30;

    public function __construct(private PDO $db)
    {
    }

    // Marca un evento como aplicado hoy en una consulta. Si su ítem del plan se repite, agrega el
    // siguiente. Devuelve false si el evento no existe o ya estaba aplicado.
    // No abre una transacción: la consulta lo llama dentro de la suya.
    public function marcarAplicado(int $eventoId, int $consultaId, ?string $hoy = null): bool
    {
        return $this->aplicar($eventoId, $hoy ?? date('Y-m-d'), $consultaId);
    }

    // La vacuna sin aplicar más vieja de la mascota (la que se aplica en un turno de vacunación),
    // o null si no tiene ninguna.
    public function vacunaPendiente(int $mascotaId): ?int
    {
        $fila = $this->fila(
            "SELECT id FROM evento_sanitario
             WHERE mascota_id = ? AND tipo = 'VACUNA' AND fecha_aplicada IS NULL
             ORDER BY fecha_programada, id LIMIT 1",
            [$mascotaId]
        );

        return $fila === null ? null : (int) $fila['id'];
    }

    // Los eventos de la mascota ordenados por fecha, cada uno con su estado al día $hoy.
    public function eventos(int $mascotaId, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        // Con su ítem del plan, si se dio en una consulta y el turno programado o en atención que tiene.
        $eventos = $this->filas(
            "SELECT e.id, e.tipo, e.nombre, e.fecha_programada, e.fecha_aplicada, e.consulta_id,
                    e.plan_sanitario_id, p.aplicacion,
                    (SELECT t.id FROM turno_motivo tm JOIN turno t ON t.id = tm.turno_id
                     WHERE tm.evento_sanitario_id = e.id AND t.estado IN ('PROGRAMADO', 'EN_ATENCION')
                     ORDER BY t.fecha, t.hora LIMIT 1) AS turno_id,
                    (SELECT t.fecha FROM turno_motivo tm JOIN turno t ON t.id = tm.turno_id
                     WHERE tm.evento_sanitario_id = e.id AND t.estado IN ('PROGRAMADO', 'EN_ATENCION')
                     ORDER BY t.fecha, t.hora LIMIT 1) AS turno_fecha
             FROM evento_sanitario e LEFT JOIN plan_sanitario p ON p.id = e.plan_sanitario_id
             WHERE e.mascota_id = ? ORDER BY e.fecha_programada, e.id",
            [$mascotaId]
        );

        return array_map(fn (array $e): array => [
            'id' => (int) $e['id'],
            'tipo' => $e['tipo'],
            'nombre' => $e['nombre'],
            'plan_sanitario_id' => $e['plan_sanitario_id'] === null ? null : (int) $e['plan_sanitario_id'],
            'aplicacion' => $e['aplicacion'],
            'fecha_programada' => $e['fecha_programada'],
            'fecha_aplicada' => $e['fecha_aplicada'],
            // Dada en una consulta de HuellaVet (true) o marcada a mano, por ejemplo en otra veterinaria (false).
            'en_consulta' => $e['consulta_id'] !== null,
            'turno' => $e['turno_id'] === null ? null : ['id' => (int) $e['turno_id'], 'fecha' => $e['turno_fecha']],
            'estado' => self::estadoDe($e['fecha_programada'], $e['fecha_aplicada'], $hoy)
        ], $eventos);
    }

    // ---------- Lo que edita el veterinario (migración 025) ----------
    // Cada método devuelve 'ok' o el motivo por el que no se hizo; el controlador arma el mensaje.

    // Los ítems del plan de una especie, para agregarlos al calendario y para elegir la vacuna de un turno.
    public function plan(string $especie): array
    {
        $filas = $this->filas(
            'SELECT id, tipo, nombre, aplicacion, orden, repetir_cada_meses, automatico
             FROM plan_sanitario WHERE especie = ? ORDER BY tipo DESC, aplicacion, orden, id',
            [$especie]
        );

        return array_map(fn (array $p): array => [
            'id' => (int) $p['id'],
            'tipo' => $p['tipo'],
            'nombre' => $p['nombre'],
            'aplicacion' => $p['aplicacion'],
            'orden' => (int) $p['orden'],
            'se_repite_cada_meses' => $p['repetir_cada_meses'] === null ? null : (int) $p['repetir_cada_meses'],
            'automatico' => (bool) $p['automatico'],
        ], $filas);
    }

    // Agrega a la mascota un ítem del plan de su especie, para la fecha que elige el veterinario.
    // 'ok', 'plan_invalido' (no existe o es de otra especie), 'dosis_anteriores' (con $faltan: los
    // nombres de las dosis anteriores de la misma vacuna que no están dadas en el calendario),
    // 'ya_pendiente' (ya lo tiene sin dar) o 'ya_dada' (es de una sola vez y ya se lo dieron).
    public function agregar(int $mascotaId, int $planId, string $fecha, ?array &$faltan = null): string
    {
        $item = $this->fila(
            'SELECT p.id, p.tipo, p.nombre, p.aplicacion, p.orden, p.especie, p.repetir_cada_meses
             FROM plan_sanitario p JOIN mascota m ON m.especie = p.especie
             WHERE p.id = ? AND m.id = ?',
            [$planId, $mascotaId]
        );

        if ($item === null) {
            return 'plan_invalido';
        }

        // La 3.ª séxtuple se carga con la 1.ª y la 2.ª ya cargadas y dadas (en HuellaVet o en otra veterinaria).
        $faltan = array_column($this->filas(
            'SELECT p.nombre FROM plan_sanitario p
             WHERE p.especie = ? AND p.aplicacion = ? AND p.orden < ?
               AND NOT EXISTS (SELECT 1 FROM evento_sanitario e
                               WHERE e.mascota_id = ? AND e.plan_sanitario_id = p.id AND e.fecha_aplicada IS NOT NULL)
             ORDER BY p.orden',
            [$item['especie'], $item['aplicacion'], (int) $item['orden'], $mascotaId]
        ), 'nombre');

        if ($faltan !== []) {
            return 'dosis_anteriores';
        }

        $ya = $this->fila(
            'SELECT SUM(fecha_aplicada IS NULL) AS sin_dar, COUNT(*) AS total
             FROM evento_sanitario WHERE mascota_id = ? AND plan_sanitario_id = ?',
            [$mascotaId, $planId]
        );

        if ((int) $ya['sin_dar'] > 0) {
            return 'ya_pendiente';
        }

        if ((int) $ya['total'] > 0 && $item['repetir_cada_meses'] === null) {
            return 'ya_dada';
        }

        $this->insertarEvento($mascotaId, $planId, $item['tipo'], $item['nombre'], $fecha);

        return 'ok';
    }

    // Marca como dado, en la fecha que dice el veterinario, un evento que se dio fuera de una consulta
    // (por ejemplo, en otra veterinaria). Si se repite, agrega el siguiente desde esa fecha.
    // 'ok', 'inexistente', 'ya_dada', 'con_turno' o 'dosis_anterior' (con $anterior: su nombre).
    public function marcarDada(int $mascotaId, int $eventoId, string $fecha, ?string &$anterior = null): string
    {
        $evento = $this->eventoDe($mascotaId, $eventoId);

        if ($evento === null) {
            return 'inexistente';
        }

        if ($evento['fecha_aplicada'] !== null) {
            return 'ya_dada';
        }

        $anterior = $this->dosisAnteriorSinDar($mascotaId, $evento);
        if ($anterior !== null) {
            return 'dosis_anterior';
        }

        if ($this->turnoActivo($eventoId) !== null) {
            return 'con_turno';
        }

        return $this->aplicar($eventoId, $fecha, null) ? 'ok' : 'ya_dada';
    }

    // Cambia la fecha en que le toca un evento que todavía no se dio. 'ok', 'inexistente' o 'ya_dada'.
    public function cambiarFecha(int $mascotaId, int $eventoId, string $fecha): string
    {
        $evento = $this->eventoDe($mascotaId, $eventoId);

        if ($evento === null) {
            return 'inexistente';
        }

        if ($evento['fecha_aplicada'] !== null) {
            return 'ya_dada';
        }

        $this->db->prepare('UPDATE evento_sanitario SET fecha_programada = ? WHERE id = ? AND fecha_aplicada IS NULL')
            ->execute([$fecha, $eventoId]);

        return 'ok';
    }

    // Quita del calendario un evento que todavía no se dio. 'ok', 'inexistente', 'ya_dada' o 'con_turno'
    // (tiene un turno programado o en atención: primero hay que cancelarlo).
    public function quitar(int $mascotaId, int $eventoId): string
    {
        $evento = $this->eventoDe($mascotaId, $eventoId);

        if ($evento === null) {
            return 'inexistente';
        }

        if ($evento['fecha_aplicada'] !== null) {
            return 'ya_dada';
        }

        if ($this->turnoActivo($eventoId) !== null) {
            return 'con_turno';
        }

        // Los turnos cancelados o ausentes que eran para este evento lo sueltan (si no, la clave foránea no deja borrarlo).
        $this->db->prepare(
            "UPDATE turno_motivo tm JOIN turno t ON t.id = tm.turno_id SET tm.evento_sanitario_id = NULL
             WHERE tm.evento_sanitario_id = ? AND t.estado IN ('CANCELADO', 'AUSENTE')"
        )->execute([$eventoId]);
        $this->db->prepare('DELETE FROM evento_sanitario WHERE id = ? AND fecha_aplicada IS NULL')->execute([$eventoId]);

        return 'ok';
    }

    // El evento del calendario que se da en un turno de $motivo (VACUNACION o DESPARASITACION) del ítem
    // $planId. Devuelve ['estado' => 'ok', 'evento_id' => n, 'nombre' => ...] o, si no se puede sacar el
    // turno, ['estado' => ..., 'mensaje' => ...] con el texto para la veterinaria:
    // 'mascota_inexistente', 'plan_invalido', 'no_en_calendario', 'dosis_anterior' o 'con_turno'.
    public function eventoParaTurno(int $mascotaId, int $planId, string $motivo): array
    {
        $mascota = $this->fila('SELECT nombre, especie FROM mascota WHERE id = ? AND activo = 1', [$mascotaId]);

        if ($mascota === null) {
            return ['estado' => 'mascota_inexistente', 'mensaje' => 'Mascota no encontrada'];
        }

        $tipo = $motivo === 'DESPARASITACION' ? 'DESPARASITACION' : 'VACUNA';
        $item = $this->fila('SELECT nombre FROM plan_sanitario WHERE id = ? AND especie = ? AND tipo = ?', [$planId, $mascota['especie'], $tipo]);

        if ($item === null) {
            return ['estado' => 'plan_invalido', 'mensaje' => $tipo === 'VACUNA'
                ? "Esa vacuna no es para la especie de {$mascota['nombre']}."
                : "Esa desparasitación no es para la especie de {$mascota['nombre']}."];
        }

        $evento = $this->fila(
            'SELECT e.id, e.nombre, p.aplicacion, p.orden
             FROM evento_sanitario e JOIN plan_sanitario p ON p.id = e.plan_sanitario_id
             WHERE e.mascota_id = ? AND e.plan_sanitario_id = ? AND e.fecha_aplicada IS NULL
             ORDER BY e.fecha_programada, e.id LIMIT 1',
            [$mascotaId, $planId]
        );

        if ($evento === null) {
            return ['estado' => 'no_en_calendario', 'mensaje' =>
                "En el calendario de {$mascota['nombre']} no figura que se le tenga que dar: {$item['nombre']}. "
                . 'Cargala primero en el calendario.'];
        }

        $anterior = $this->dosisAnteriorSinDar($mascotaId, $evento);
        if ($anterior !== null) {
            return ['estado' => 'dosis_anterior', 'mensaje' =>
                "Antes de {$evento['nombre']} tiene que darse $anterior. "
                . 'Si ya se la dieron en otra veterinaria, marcala como dada en el calendario.'];
        }

        $turno = $this->turnoActivo((int) $evento['id']);
        if ($turno !== null) {
            $dia = date('d/m/Y', strtotime($turno['fecha']));
            return ['estado' => 'con_turno', 'mensaje' => "{$mascota['nombre']} ya tiene turno para {$evento['nombre']} el $dia."];
        }

        return ['estado' => 'ok', 'evento_id' => (int) $evento['id'], 'nombre' => $evento['nombre']];
    }

    // ---------- Reglas (sin base de datos) ----------

    // APLICADO, VENCIDO, PROXIMO (le toca en los próximos DIAS_PROXIMO días) o PENDIENTE.
    public static function estadoDe(string $programada, ?string $aplicada, string $hoy): string
    {
        if ($aplicada !== null) {
            return 'APLICADO';
        }

        if ($programada < $hoy) {
            return 'VENCIDO';
        }

        $limite = (new DateTimeImmutable($hoy))->modify('+' . self::DIAS_PROXIMO . ' days')->format('Y-m-d');

        return $programada <= $limite ? 'PROXIMO' : 'PENDIENTE';
    }

    // Las vacunas están al día si ninguna está vencida (las desparasitaciones no cuentan).
    public static function vacunasAlDia(array $eventos): bool
    {
        foreach ($eventos as $evento) {
            if ($evento['tipo'] === 'VACUNA' && $evento['estado'] === 'VENCIDO') {
                return false;
            }
        }

        return true;
    }

    // Suma meses sin pasarse de mes: 31/01 + 1 mes = 28/02 (o 29/02), no 03/03.
    public static function sumarMeses(string $fecha, int $meses): string
    {
        $inicio = new DateTimeImmutable($fecha);
        $primeroDelMes = $inicio->modify('first day of this month')->modify("+$meses months");
        $dia = min((int) $inicio->format('j'), (int) $primeroDelMes->format('t'));

        return $primeroDelMes->setDate((int) $primeroDelMes->format('Y'), (int) $primeroDelMes->format('n'), $dia)->format('Y-m-d');
    }

    // ---------- Ayudas ----------

    // Marca el evento como aplicado en $fecha (en la consulta $consultaId, o a mano si es null) y, si su
    // ítem del plan se repite, agrega el siguiente desde esa fecha. false si no existe o ya estaba aplicado.
    private function aplicar(int $eventoId, string $fecha, ?int $consultaId): bool
    {
        $sentencia = $this->db->prepare(
            'UPDATE evento_sanitario SET fecha_aplicada = ?, consulta_id = ? WHERE id = ? AND fecha_aplicada IS NULL'
        );
        $sentencia->execute([$fecha, $consultaId, $eventoId]);

        if ($sentencia->rowCount() !== 1) {
            return false;
        }

        $evento = $this->fila(
            'SELECT e.mascota_id, e.tipo, e.nombre, p.id AS plan_id, p.repetir_cada_meses
             FROM evento_sanitario e JOIN plan_sanitario p ON p.id = e.plan_sanitario_id
             WHERE e.id = ?',
            [$eventoId]
        );

        if ($evento !== null && $evento['repetir_cada_meses'] !== null) {
            $siguiente = self::sumarMeses($fecha, (int) $evento['repetir_cada_meses']);
            $this->insertarEvento((int) $evento['mascota_id'], (int) $evento['plan_id'], $evento['tipo'], $evento['nombre'], $siguiente);
        }

        return true;
    }

    // Un evento de la mascota con su aplicación y su dosis, o null si no es de ella.
    private function eventoDe(int $mascotaId, int $eventoId): ?array
    {
        return $this->fila(
            'SELECT e.id, e.nombre, e.fecha_aplicada, p.aplicacion, p.orden
             FROM evento_sanitario e LEFT JOIN plan_sanitario p ON p.id = e.plan_sanitario_id
             WHERE e.id = ? AND e.mascota_id = ?',
            [$eventoId, $mascotaId]
        );
    }

    // El nombre de una dosis anterior de la misma vacuna que la mascota tiene en el calendario sin dar
    // (la 1.ª séxtuple antes de la 2.ª), o null. Las dosis que no están en el calendario no frenan: un
    // perro adulto no tiene las de cachorro.
    private function dosisAnteriorSinDar(int $mascotaId, array $evento): ?string
    {
        if ($evento['aplicacion'] === null) {
            return null;
        }

        $fila = $this->fila(
            'SELECT e.nombre FROM evento_sanitario e JOIN plan_sanitario p ON p.id = e.plan_sanitario_id
             WHERE e.mascota_id = ? AND e.fecha_aplicada IS NULL AND p.aplicacion = ? AND p.orden < ?
             ORDER BY p.orden LIMIT 1',
            [$mascotaId, $evento['aplicacion'], (int) $evento['orden']]
        );

        return $fila['nombre'] ?? null;
    }

    // El turno programado o en atención que es para este evento: ['id', 'fecha'] o null.
    private function turnoActivo(int $eventoId): ?array
    {
        return $this->fila(
            "SELECT t.id, t.fecha FROM turno_motivo tm JOIN turno t ON t.id = tm.turno_id
             WHERE tm.evento_sanitario_id = ? AND t.estado IN ('PROGRAMADO', 'EN_ATENCION')
             ORDER BY t.fecha, t.hora LIMIT 1",
            [$eventoId]
        );
    }

    private function insertarEvento(int $mascotaId, int $planId, string $tipo, string $nombre, string $fecha): void
    {
        $sentencia = $this->db->prepare(
            'INSERT INTO evento_sanitario (mascota_id, plan_sanitario_id, tipo, nombre, fecha_programada) VALUES (?, ?, ?, ?, ?)'
        );
        $sentencia->execute([$mascotaId, $planId, $tipo, $nombre, $fecha]);
    }

    private function filas(string $sql, array $parametros): array
    {
        $sentencia = $this->db->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->fetchAll();
    }

    private function fila(string $sql, array $parametros): ?array
    {
        return $this->filas($sql, $parametros)[0] ?? null;
    }
}
