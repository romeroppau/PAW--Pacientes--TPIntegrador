<?php

// Endpoint de proceso a proceso: Adopciones lo llama al completar una adopción para pasar la
// mascota del rescatista al adoptante. No es público: Nginx bloquea /internal/ desde afuera y
// además se exige el token de servicio (mismo mecanismo que el descuento de stock de Inventario).
// Contrato: docs/api/contrato-adopciones.md del repo Adopciones, sección 7.
class MascotaInternoControlador
{
    // POST /internal/mascotas/{id}/transferir
    public static function transferir(array $parametros): void
    {
        // 1. Token de servicio, antes de leer nada más.
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

        // 2. La mascota de la ruta y el cuerpo.
        $id = ParametroRuta::entero($parametros, 'id');

        if ($id === null) {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        $duenios = ValidadorTransferencia::validar(Request::json());

        if ($duenios === null) {
            Response::error('Datos de solicitud inválidos', 400);
            return;
        }

        [$actual, $nuevo] = $duenios;

        // 3. Transferir. Repetirlo con los mismos datos es seguro (responde transferida: false).
        $resultado = (new MascotaRepositorio(Database::conectar()))->transferir($id, $actual, $nuevo);

        switch ($resultado) {
            case 'TRANSFERIDA':
                Logger::info("Mascota {$id} transferida del usuario {$actual} al {$nuevo} (adopción)");
                Response::json(['transferida' => true], 200);
                return;
            case 'YA_TRANSFERIDA':
                Response::json(['transferida' => false], 200);
                return;
            case 'NO_ES_CLIENTE':
                Response::error('Datos de solicitud inválidos', 400);
                return;
            case 'NO_EXISTE':
                Response::error('Mascota no encontrada', 404);
                return;
            case 'DUENIO_DISTINTO':
                Response::json([
                    'error' => 'La mascota no es del dueño indicado',
                    'motivo' => 'DUENIO_DISTINTO',
                ], 409);
                return;
        }
    }
}
