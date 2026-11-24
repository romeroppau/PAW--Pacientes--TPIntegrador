<?php

// Rutas de mascotas (rol 3): lectura (veterinario y dueño) y alta, edición y baja (dueño: cliente o
// rescatista, Entrega 5).
// Contrato en docs/api/contrato-mascotas-turnos.md. El historial clínico está en consultas.php (rol 4).

$router->get('/api/pacientes/mascotas', [MascotaControlador::class, 'listar']);
$router->get('/api/pacientes/mascotas/{id}', [MascotaControlador::class, 'ver']);
$router->get('/api/pacientes/mascotas/{id}/foto', [MascotaControlador::class, 'foto']);
$router->post('/api/pacientes/mascotas', [MascotaControlador::class, 'crear']);
$router->put('/api/pacientes/mascotas/{id}', [MascotaControlador::class, 'actualizar']);
$router->delete('/api/pacientes/mascotas/{id}', [MascotaControlador::class, 'darDeBaja']);
