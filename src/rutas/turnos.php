<?php

// Rutas de la agenda, del alta y del cambio de estado de los turnos (rol 3).
// Contrato en docs/api/contrato-mascotas-turnos.md. Iniciar la atención está en consultas.php (rol 4).

$router->get('/api/pacientes/turnos', [TurnoControlador::class, 'listar']);
$router->get('/api/pacientes/horario', [TurnoControlador::class, 'horario']);
$router->post('/api/pacientes/turnos/{id}/cancelar', [TurnoControlador::class, 'cancelar']);
$router->post('/api/pacientes/turnos/{id}/ausente', [TurnoControlador::class, 'marcarAusente']);
$router->post('/api/pacientes/turnos', [TurnoControlador::class, 'crear']);
$router->post('/api/pacientes/turnos/{id}/reprogramar', [TurnoControlador::class, 'reprogramar']);

// Franjas promocionadas (precio de la consulta con descuento): públicas para ver; la veterinaria las
// publica y las termina desde Reportes.
$router->get('/api/pacientes/promociones-turnos', [PromocionFranjaControlador::class, 'listar']);
$router->post('/api/pacientes/promociones-turnos', [PromocionFranjaControlador::class, 'crear']);
$router->delete('/api/pacientes/promociones-turnos/{id}', [PromocionFranjaControlador::class, 'terminar']);
