<?php

// Avisos al dueño cuando la veterinaria le da, le reprograma o le cancela un turno (25/11), por la
// campana del sitio y por WhatsApp. Se los pasa a Backend por POST /internal/notificaciones (tipo TURNO,
// migración 024), cada uno con un id_operacion fijo para que un reintento no lo repita. Backend manda el
// WhatsApp en el momento. Si Backend no responde, el turno queda igual: el aviso no es parte de la acción.
// Contrato: docs/api/contrato-mascotas-turnos.md ("Avisos de turnos").
class AvisosTurno
{
    private const CANALES = ['WEB' => 'web', 'WHATSAPP' => 'whatsapp'];

    private const MOTIVOS = [
        'CONSULTA' => 'consulta',
        'VACUNACION' => 'vacunación',
        'DESPARASITACION' => 'desparasitación',
        'CASTRACION' => 'castración',
        'CIRUGIA' => 'cirugía',
        'CONTROL' => 'control',
        'URGENCIA' => 'urgencia',
        'CHEQUEO_ADOPCION' => 'chequeo de adopción',
    ];

    // $turno: el detalle del turno (TurnoRepositorio::detalle).
    public static function asignado(array $turno): array
    {
        $mascota = $turno['mascota']['nombre'];
        $mensaje = "Tenés turno de " . self::motivo($turno) . " para $mascota el " . self::cuando($turno['fecha'], $turno['hora'])
            . ' en HuellaVet.' . self::precio($turno) . ' Si no podés ir, avisanos por WhatsApp.';

        return self::avisos($turno, $mensaje, "turno-{$turno['id']}-asignado");
    }

    // $momento (AAAAMMDDHHMMSS): un turno se puede reprogramar más de una vez.
    public static function reprogramado(array $turno, string $momento): array
    {
        $mascota = $turno['mascota']['nombre'];
        $mensaje = "Cambiamos el turno de $mascota: ahora es el " . self::cuando($turno['fecha'], $turno['hora'])
            . ' en HuellaVet.' . self::precio($turno) . ' Si no podés ir, avisanos por WhatsApp.';

        return self::avisos($turno, $mensaje, "turno-{$turno['id']}-reprogramado-$momento");
    }

    public static function cancelado(array $turno): array
    {
        $mascota = $turno['mascota']['nombre'];
        $mensaje = "Cancelamos el turno de " . self::motivo($turno) . " de $mascota del " . self::cuando($turno['fecha'], $turno['hora'])
            . '. Si querés otro, escribinos por WhatsApp.';

        return self::avisos($turno, $mensaje, "turno-{$turno['id']}-cancelado");
    }

    // Deja los avisos en Backend. Devuelve cuántos quedaron (el resto va al log).
    public static function enviar(array $avisos): int
    {
        $dejados = 0;
        foreach ($avisos as $aviso) {
            $codigo = self::notificar($aviso);
            if ($codigo === 200) {
                $dejados++;
            } else {
                Logger::error("Pacientes: no se pudo dejar el aviso {$aviso['id_operacion']} (Backend respondió $codigo)");
            }
        }

        return $dejados;
    }

    // "2026-11-26" y "10:00:00" → "jueves 26/11 a las 10:00".
    public static function cuando(string $fecha, string $hora): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $momento = strtotime($fecha . ' 12:00:00');

        return $dias[(int) date('w', $momento)] . ' ' . date('d/m', $momento) . ' a las ' . substr($hora, 0, 5);
    }

    // Todos los motivos del turno (migración 026): "vacunación (Antirrábica), vacunación (Séxtuple, 2.ª dosis)
    // y control". El paréntesis del nombre de la dosis pasa a ir después de una coma.
    private static function motivo(array $turno): string
    {
        $textos = array_map(function (array $m): string {
            $texto = self::MOTIVOS[$m['motivo']] ?? 'consulta';
            $evento = $m['evento_sanitario'] ?? null;

            return $evento === null ? $texto : "$texto (" . preg_replace('/\s*\((.+)\)$/u', ', $1', $evento['nombre']) . ')';
        }, $turno['motivos'] ?? [['motivo' => $turno['motivo']]]);

        $ultimo = array_pop($textos);

        return $textos === [] ? $ultimo : implode(', ', $textos) . ' y ' . $ultimo;
    }

    // " La consulta: $ 12.750 (15 % de descuento)." si el turno guardó su precio (migración 020).
    private static function precio(array $turno): string
    {
        $precio = $turno['precio'] ?? null;
        if ($precio === null || $turno['motivo'] === 'CHEQUEO_ADOPCION') {
            return '';
        }

        $texto = ' La consulta: $ ' . number_format((float) $precio['final'], 0, ',', '.');
        if ($precio['descuento'] !== null) {
            $texto .= " ({$precio['descuento']} % de descuento)";
        }

        return $texto . '.';
    }

    // Uno por canal (campana y WhatsApp), al dueño de la mascota.
    private static function avisos(array $turno, string $mensaje, string $operacion): array
    {
        $avisos = [];
        foreach (self::CANALES as $canal => $sufijo) {
            $avisos[] = [
                'usuario_id' => (int) $turno['duenio']['id'],
                'tipo' => 'TURNO',
                'canal' => $canal,
                'mensaje' => mb_substr($mensaje, 0, 500),
                'fecha_programada' => date('Y-m-d H:i:s'),
                'id_operacion' => "$operacion-$sufijo",
            ];
        }

        return $avisos;
    }

    // Llamada interna a Backend (de proceso a proceso, sin pasar por Nginx), como ClienteInventario.
    // Devuelve el código HTTP, o 0 si no se pudo comunicar.
    private static function notificar(array $aviso): int
    {
        $token = Config::get('INTERNAL_TOKEN');
        if (!is_string($token) || $token === '') {
            Logger::error('Pacientes: falta INTERNAL_TOKEN para llamar a Backend');
            return 0;
        }

        $base = Config::get('BACKEND_URL') ?: 'http://127.0.0.1:9001';
        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Servicio-Token: $token\r\n",
            'content' => json_encode($aviso, JSON_UNESCAPED_UNICODE),
            'timeout' => 4, // un aviso no puede demorar la acción de la veterinaria (incluye el WhatsApp inmediato)
            'ignore_errors' => true,
        ]]);

        $cuerpo = @file_get_contents($base . '/internal/notificaciones', false, $contexto);
        if ($cuerpo === false) {
            return 0;
        }

        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[0] ?? '', $c) ? (int) $c[1] : 0;
    }
}
