<?php

// Los endpoints de lectura de mascotas (contrato en docs/api/contrato-mascotas-turnos.md).
// Los métodos son static para conectarlos al router así: [MascotaControlador::class, 'ver'].
class MascotaControlador
{
    // GET /api/pacientes/mascotas
    // Veterinario: todas las activas (?buscar= por nombre de la mascota o del dueño). Cliente: las suyas.
    public static function listar(): void
    {
        // Se exige sesión de veterinario o cliente, pero no CSRF porque es GET.
        $sesion = Auth::requerirRol(['VETERINARIO', 'CLIENTE']);
        $repositorio = self::repositorio();

        if ($sesion['rol'] === 'CLIENTE') {
            Response::json(['mascotas' => $repositorio->listar(date('Y-m-d'), (int) $sesion['id'])]);
            return;
        }

        $buscar = Request::query()['buscar'] ?? '';
        $errores = ValidadorFiltros::validarBusqueda($buscar);

        if ($errores !== []) {
            Response::error(implode(' ', $errores), 400);
            return;
        }

        Response::json(['mascotas' => $repositorio->listar(date('Y-m-d'), null, trim($buscar))]);
    }

    // GET /api/pacientes/mascotas/{id}
    // El cliente solo ve sus mascotas; si pide la de otro recibe 404, igual que si no existiera.
    public static function ver(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO', 'CLIENTE']);

        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : self::repositorio()->detalle($id, date('Y-m-d'));

        if ($mascota === null || ($sesion['rol'] === 'CLIENTE' && $mascota['duenio']['id'] !== (int) $sesion['id'])) {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        Response::json($mascota);
    }

    private static function repositorio(): MascotaRepositorio
    {
        return new MascotaRepositorio(Database::conectar());
    }
}
