<?php

// El endpoint del calendario sanitario (contrato en docs/api/contrato-calendario.md).
// Los métodos son static para conectarlos al router así: [CalendarioControlador::class, 'ver'].
class CalendarioControlador
{
    // GET /api/pacientes/mascotas/{id}/calendario-sanitario
    // El cliente solo ve sus mascotas; si pide la de otro recibe 404, igual que si no existiera.
    public static function ver(array $parametros): void
    {
        // Se exige sesión de cliente o veterinario, pero no CSRF porque es GET.
        $sesion = Auth::requerirRol(['CLIENTE', 'VETERINARIO']);

        $db = Database::conectar();
        $hoy = date('Y-m-d');
        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : (new MascotaRepositorio($db))->detalle($id, $hoy);

        if ($mascota === null || ($sesion['rol'] === 'CLIENTE' && $mascota['duenio']['id'] !== (int) $sesion['id'])) {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        $calendario = new CalendarioSanitario($db);
        $eventos = $calendario->eventos($id, $hoy);

        // Una mascota que todavía no tiene calendario (por ejemplo, cargada antes de que existiera)
        // lo recibe la primera vez que alguien lo pide.
        if ($eventos === [] && $calendario->generarPara($id, $hoy) > 0) {
            $eventos = $calendario->eventos($id, $hoy);
        }

        Response::json([
            'mascota' => [
                'id' => $mascota['id'],
                'nombre' => $mascota['nombre'],
                'especie' => $mascota['especie'],
                'fecha_nacimiento' => $mascota['fecha_nacimiento']
            ],
            'vacunas_al_dia' => CalendarioSanitario::vacunasAlDia($eventos),
            'eventos' => $eventos
        ]);
    }
}
