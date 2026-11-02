

<?php

// Localizar las herramientas compartidas del proyecto.
$comun = getenv('COMUN_DIR') ?: dirname(__DIR__, 2) . '/comun';

// Cargar las clases compartidas.
require_once $comun . '/config.php';
require_once $comun . '/response.php';
require_once $comun . '/request.php';
require_once $comun . '/router.php';
require_once $comun . '/logger.php';
require_once $comun . '/database.php';

// Crear el router.
$router = new Router();

// Cargar automáticamente las rutas del módulo.
foreach (glob(__DIR__ . '/rutas/*.php') as $archivoRuta) {
    require_once $archivoRuta;
}

// Ejecutar la ruta solicitada y manejar errores inesperados.
try {
    $router->dispatch(
        Request::method(),
        Request::path()
    );
} catch (Throwable $error) {
    Logger::error(
        'Error inesperado en Pacientes: '
        . get_class($error)
        . ' - '
        . $error->getMessage()
    );

    Response::error(
        'Ocurrió un error interno del servidor',
        500
    );
}
