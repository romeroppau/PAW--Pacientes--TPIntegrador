<?php

// Endpoint de proceso a proceso: Adopciones lo llama cuando la rescatista da de baja una publicación
// con el chequeo de adopción programado, para cancelar ese turno en la agenda. No es público: Nginx
// bloquea /internal/ desde afuera y además se exige el token de servicio (como la transferencia).
// Contrato: docs/api/contrato-adopciones.md del repo Adopciones, sección 7.
class TurnoInternoControlador
{
    // POST /internal/turnos/{id}/cancelar-chequeo
    // Solo cancela un turno de CHEQUEO_ADOPCION que siga PROGRAMADO. Repetirlo es seguro: si ya no
    // está programado responde cancelado: false con su estado.
    public static function cancelarChequeo(array $parametros): void
    {
        $tokenConfigurado = Config::get('INTERNAL_TOKEN');
        $tokenRecibido = $_SERVER['HTTP_X_SERVICIO_TOKEN'] ?? '';

        if (
            $tokenConfigurado === null || $tokenConfigurado === ''
            || !is_string($tokenRecibido) || $tokenRecibido === ''
            || !hash_equals($tokenConfigurado, $tokenRecibido)
        ) {
            Response::error('No autorizado', 401);
            return;
        }

        $id = ParametroRuta::entero($parametros, 'id');
        $db = Database::conectar();
        $turno = null;

        if ($id !== null) {
            $consulta = $db->prepare('SELECT estado, motivo FROM turno WHERE id = ?');
            $consulta->execute([$id]);
            $turno = $consulta->fetch() ?: null;
        }

        if ($turno === null) {
            Response::error('Turno no encontrado', 404);
            return;
        }

        if ($turno['motivo'] !== 'CHEQUEO_ADOPCION') {
            Response::error('El turno no es un chequeo de adopción', 409);
            return;
        }

        $resultado = (new TurnoRepositorio($db))->cambiarEstado($id, 'CANCELADO');

        if ($resultado === 'ok') {
            Logger::info("Turno {$id} (chequeo de adopción) cancelado: la rescatista dio de baja la publicación");
            Response::json(['cancelado' => true, 'estado' => 'CANCELADO'], 200);
            return;
        }

        // Ya estaba atendido, cancelado o ausente: no se toca.
        Response::json(['cancelado' => false, 'estado' => $turno['estado']], 200);
    }
}
