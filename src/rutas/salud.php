
<?php

$router->get('/api/pacientes/salud', function (): void {
    Response::json([
        'estado' => 'funciona',
        'modulo' => 'Pacientes'
    ], 200);
});
