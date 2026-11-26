<?php

// Rutas del calendario sanitario (rol 3). Contrato en docs/api/contrato-calendario.md.

$router->get('/api/pacientes/mascotas/{id}/calendario-sanitario', [CalendarioControlador::class, 'ver']);

// El veterinario edita el calendario (migración 025).
$router->post('/api/pacientes/mascotas/{id}/calendario-sanitario', [CalendarioControlador::class, 'agregar']);
$router->put('/api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}', [CalendarioControlador::class, 'cambiarFecha']);
$router->delete('/api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}', [CalendarioControlador::class, 'quitar']);
$router->post('/api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}/dada', [CalendarioControlador::class, 'marcarDada']);
