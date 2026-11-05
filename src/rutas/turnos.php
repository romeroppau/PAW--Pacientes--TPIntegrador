<?php

// Rutas de la agenda y del cambio de estado de los turnos (rol 3).
// Contrato en docs/api/contrato-mascotas-turnos.md. Iniciar la atención está en consultas.php (rol 4).

$router->get('/api/pacientes/turnos', [TurnoControlador::class, 'listar']);
$router->post('/api/pacientes/turnos/{id}/cancelar', [TurnoControlador::class, 'cancelar']);
$router->post('/api/pacientes/turnos/{id}/ausente', [TurnoControlador::class, 'marcarAusente']);
