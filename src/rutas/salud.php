<?php

$router->get('/api/pacientes/salud', function (): void {
    try {
        Database::conectar()->query('SELECT 1');
    } catch (Throwable $error) {
        Logger::error(
            'Salud de Pacientes: no se pudo conectar a la base: '
            . $error->getMessage()
        );

        Response::json([
            'estado' => 'sin conexión a la base',
            'modulo' => 'Pacientes'
        ], 503);
        return;
    }

    Response::json([
        'estado' => 'funciona',
        'modulo' => 'Pacientes'
    ], 200);
});
