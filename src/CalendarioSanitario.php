<?php

// Calendario sanitario de cada mascota (plan_sanitario y evento_sanitario).
// Contrato y reglas en docs/api/contrato-calendario.md. El rol 4 llama a vacunaPendiente() y
// marcarAplicado() al finalizar una consulta de vacunación.
// Las reglas de fechas y estados (los métodos static) no usan la base: se prueban con PHPUnit.
class CalendarioSanitario
{
    // Un evento sin aplicar es PROXIMO si le toca dentro de estos días.
    public const DIAS_PROXIMO = 30;

    public function __construct(private PDO $db)
    {
    }

    // Agrega a la mascota los ítems del plan de su especie que todavía no tiene.
    // Devuelve cuántos eventos agregó (0 si la mascota no existe o no tiene fecha de nacimiento).
    public function generarPara(int $mascotaId, ?string $hoy = null): int
    {
        $hoy ??= date('Y-m-d');
        $mascota = $this->fila('SELECT especie, fecha_nacimiento FROM mascota WHERE id = ?', [$mascotaId]);

        if ($mascota === null || $mascota['fecha_nacimiento'] === null) {
            return 0;
        }

        // Ítems del plan de su especie que la mascota no tiene, ni aplicados ni pendientes.
        $plan = $this->filas(
            'SELECT p.id, p.tipo, p.nombre, p.edad_inicio_semanas, p.repetir_cada_meses
             FROM plan_sanitario p
             WHERE p.especie = ?
               AND NOT EXISTS (SELECT 1 FROM evento_sanitario e WHERE e.mascota_id = ? AND e.plan_sanitario_id = p.id)
             ORDER BY p.edad_inicio_semanas, p.id',
            [$mascota['especie'], $mascotaId]
        );

        $agregados = 0;

        foreach ($plan as $item) {
            $fecha = self::fechaParaPlan(
                $mascota['fecha_nacimiento'],
                (int) $item['edad_inicio_semanas'],
                $item['repetir_cada_meses'] === null ? null : (int) $item['repetir_cada_meses'],
                $hoy
            );

            if ($fecha !== null) {
                $this->insertarEvento($mascotaId, (int) $item['id'], $item['tipo'], $item['nombre'], $fecha);
                $agregados++;
            }
        }

        return $agregados;
    }

    // Marca un evento como aplicado hoy en una consulta. Si su ítem del plan se repite, agrega el
    // siguiente. Devuelve false si el evento no existe o ya estaba aplicado.
    // No abre una transacción: la consulta lo llama dentro de la suya.
    public function marcarAplicado(int $eventoId, int $consultaId, ?string $hoy = null): bool
    {
        $hoy ??= date('Y-m-d');
        $sentencia = $this->db->prepare(
            'UPDATE evento_sanitario SET fecha_aplicada = ?, consulta_id = ? WHERE id = ? AND fecha_aplicada IS NULL'
        );
        $sentencia->execute([$hoy, $consultaId, $eventoId]);

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
            $siguiente = self::sumarMeses($hoy, (int) $evento['repetir_cada_meses']);
            $this->insertarEvento((int) $evento['mascota_id'], (int) $evento['plan_id'], $evento['tipo'], $evento['nombre'], $siguiente);
        }

        return true;
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
        $eventos = $this->filas(
            'SELECT id, tipo, nombre, fecha_programada, fecha_aplicada
             FROM evento_sanitario WHERE mascota_id = ? ORDER BY fecha_programada, id',
            [$mascotaId]
        );

        return array_map(fn (array $e): array => [
            'id' => (int) $e['id'],
            'tipo' => $e['tipo'],
            'nombre' => $e['nombre'],
            'fecha_programada' => $e['fecha_programada'],
            'fecha_aplicada' => $e['fecha_aplicada'],
            'estado' => self::estadoDe($e['fecha_programada'], $e['fecha_aplicada'], $hoy)
        ], $eventos);
    }

    // ---------- Reglas (sin base de datos) ----------

    // Primera fecha de un ítem del plan para una mascota: el nacimiento más la edad de inicio.
    // Si ya pasó, un ítem que se repite va a la próxima fecha que le toque desde hoy, y uno que no
    // se repite no se agrega (null).
    public static function fechaParaPlan(string $nacimiento, int $edadInicioSemanas, ?int $repetirCadaMeses, string $hoy): ?string
    {
        $fecha = (new DateTimeImmutable($nacimiento))->modify("+$edadInicioSemanas weeks")->format('Y-m-d');

        if ($fecha >= $hoy) {
            return $fecha;
        }

        if ($repetirCadaMeses === null) {
            return null;
        }

        // Se suma desde la primera fecha (no desde la anterior) para que el día del mes no se corra.
        for ($veces = 1; ; $veces++) {
            $siguiente = self::sumarMeses($fecha, $repetirCadaMeses * $veces);

            if ($siguiente >= $hoy) {
                return $siguiente;
            }
        }
    }

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
