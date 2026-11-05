<?php

// Llamada interna de Pacientes a Inventario (de proceso a proceso, sin pasar por Nginx).
// Contrato: sección 5 del plan de la Entrega 4 (POST /internal/stock/descontar).
class ClienteInventario
{
    // Devuelve ['codigo' => HTTP, 'datos' => respuesta]. Código 0 si no se pudo comunicar.
    public static function descontar(int $usuarioId, array $items): array
    {
        $token = Config::get('INTERNAL_TOKEN');

        if (!is_string($token) || $token === '') {
            Logger::error('Pacientes: falta INTERNAL_TOKEN para llamar a Inventario');
            return ['codigo' => 0, 'datos' => []];
        }

        $base = Config::get('INVENTARIO_INTERNAL_URL') ?: 'http://127.0.0.1:9002';

        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Servicio-Token: $token\r\n",
            'content' => json_encode(['usuario_id' => $usuarioId, 'items' => $items]),
            'timeout' => 10,
            'ignore_errors' => true // para leer el cuerpo también cuando responde 409 o 401
        ]]);

        $cuerpo = @file_get_contents($base . '/internal/stock/descontar', false, $contexto);

        if ($cuerpo === false) {
            Logger::error('Pacientes: Inventario no respondió al descontar stock');
            return ['codigo' => 0, 'datos' => []];
        }

        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $codigo = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[0] ?? '', $coincidencia)) {
            $codigo = (int) $coincidencia[1];
        }

        $datos = json_decode($cuerpo, true);

        return ['codigo' => $codigo, 'datos' => is_array($datos) ? $datos : []];
    }
}