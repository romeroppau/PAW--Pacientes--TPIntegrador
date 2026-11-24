<?php

// Los endpoints de mascotas (contrato en docs/api/contrato-mascotas-turnos.md).
// "Dueño" es el cliente o, mientras la mascota está en adopción, el rescatista que la tiene a cargo
// (Entrega 5): los dos usan las mismas rutas y solo ven y tocan sus mascotas.
// Los métodos son static para conectarlos al router así: [MascotaControlador::class, 'ver'].
class MascotaControlador
{
    // GET /api/pacientes/mascotas
    // Veterinario: todas las activas (?buscar= por nombre de la mascota o del dueño). Cliente o rescatista: las suyas.
    public static function listar(): void
    {
        // Se exige sesión, pero no CSRF porque es GET.
        $sesion = Auth::requerirRol(self::ROLES_LECTURA);
        $repositorio = self::repositorio();

        if ($sesion['rol'] !== 'VETERINARIO') {
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
    // El dueño solo ve sus mascotas; si pide la de otro recibe 404, igual que si no existiera.
    public static function ver(array $parametros): void
    {
        $sesion = Auth::requerirRol(self::ROLES_LECTURA);

        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : self::repositorio()->detalle($id, date('Y-m-d'));

        if ($mascota === null || !self::puedeVer($sesion, $mascota)) {
            Response::error('Mascota no encontrada', 404);
            return;
        }

        Response::json($mascota);
    }

    // GET /api/pacientes/mascotas/{id}/foto — la imagen, para usarla directo en un <img>.
    // Las mismas reglas que ver(): el dueño solo ve las fotos de sus mascotas.
    public static function foto(array $parametros): void
    {
        $sesion = Auth::requerirRol(self::ROLES_LECTURA);
        $repositorio = self::repositorio();

        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : $repositorio->detalle($id, date('Y-m-d'));
        $foto = $mascota === null ? null : $repositorio->foto($id);

        if (
            $foto === null || !in_array($foto[1], Imagen::TIPOS, true)
            || !self::puedeVer($sesion, $mascota)
        ) {
            Response::error('Foto no encontrada', 404);
            return;
        }

        // El tipo sale de la lista fija (Imagen::TIPOS), nunca de lo que se guardó sin revisar.
        // nosniff: el navegador no intenta adivinar otro tipo. private: no la guarda ningún proxy.
        header('Content-Type: ' . $foto[1]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        echo $foto[0];
    }

    // POST /api/pacientes/mascotas — el cliente o el rescatista da de alta una mascota suya (el dueño sale de la sesión).
    // Al crearla se arma su calendario sanitario.
    public static function crear(): void
    {
        $sesion = self::duenio();
        $hoy = date('Y-m-d');

        [$datos, $errores] = ValidadorMascota::validar(Request::json(), $hoy);

        if ($errores !== []) {
            Response::error('Revisá los datos de la mascota', 400, $errores);
            return;
        }

        $repositorio = self::repositorio();
        $id = $repositorio->crear((int) $sesion['id'], $datos, $hoy);

        Response::json($repositorio->detalle($id, $hoy), 201);
    }

    // PUT /api/pacientes/mascotas/{id} — el dueño edita una mascota suya y activa.
    public static function actualizar(array $parametros): void
    {
        $sesion = self::duenio();
        $hoy = date('Y-m-d');
        $repositorio = self::repositorio();
        $mascota = self::propia($repositorio, $sesion, $parametros, $hoy);

        if ($mascota === null) {
            return;
        }

        [$datos, $errores] = ValidadorMascota::validar(Request::json(), $hoy);

        if ($errores !== []) {
            Response::error('Revisá los datos de la mascota', 400, $errores);
            return;
        }

        $repositorio->actualizar($mascota['id'], $datos, $hoy);

        Response::json($repositorio->detalle($mascota['id'], $hoy));
    }

    // DELETE /api/pacientes/mascotas/{id} — baja lógica de una mascota del dueño.
    // Si tiene turnos programados, primero hay que cancelarlos (los cancela el veterinario).
    public static function darDeBaja(array $parametros): void
    {
        $sesion = self::duenio();
        $hoy = date('Y-m-d');
        $repositorio = self::repositorio();
        $mascota = self::propia($repositorio, $sesion, $parametros, $hoy);

        if ($mascota === null) {
            return;
        }

        if ($repositorio->turnosProgramados($mascota['id'], $hoy) > 0) {
            Response::error('La mascota tiene turnos programados: pedile a la veterinaria que los cancele', 409);
            return;
        }

        $repositorio->darDeBaja($mascota['id']);
        http_response_code(204);
    }

    // ---------- Ayudas ----------

    // Quién puede leer mascotas. El rescatista, como el cliente, solo las suyas.
    private const ROLES_LECTURA = ['VETERINARIO', 'CLIENTE', 'RESCATISTA'];

    // El veterinario ve todas; el cliente y el rescatista, solo las suyas.
    private static function puedeVer(array $sesion, array $mascota): bool
    {
        return $sesion['rol'] === 'VETERINARIO' || $mascota['duenio']['id'] === (int) $sesion['id'];
    }

    // Exige sesión de dueño (cliente o rescatista) y el token CSRF (las altas, ediciones y bajas
    // son POST, PUT y DELETE). El veterinario no carga mascotas: lo hace cada dueño.
    private static function duenio(): array
    {
        $sesion = Auth::requerirRol(['CLIENTE', 'RESCATISTA']);
        Auth::verificarCsrf($sesion);

        return $sesion;
    }

    // La mascota activa del {id}, si es del dueño de la sesión. Si no, responde 404 y devuelve null:
    // la de otro dueño da 404, igual que si no existiera. Una dada de baja ya no se edita (409).
    private static function propia(MascotaRepositorio $repositorio, array $sesion, array $parametros, string $hoy): ?array
    {
        $id = ParametroRuta::entero($parametros, 'id');
        $mascota = $id === null ? null : $repositorio->detalle($id, $hoy);

        if ($mascota === null || $mascota['duenio']['id'] !== (int) $sesion['id']) {
            Response::error('Mascota no encontrada', 404);
            return null;
        }

        if (!$mascota['activo']) {
            Response::error('La mascota está dada de baja', 409);
            return null;
        }

        return $mascota;
    }

    private static function repositorio(): MascotaRepositorio
    {
        return new MascotaRepositorio(Database::conectar());
    }
}
