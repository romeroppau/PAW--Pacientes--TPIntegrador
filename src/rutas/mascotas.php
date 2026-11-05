<?php

// Rutas de lectura de mascotas (rol 3). El alta y la edición son de la etapa 3.
// Contrato en docs/api/contrato-mascotas-turnos.md. El historial clínico está en consultas.php (rol 4).

$router->get('/api/pacientes/mascotas', [MascotaControlador::class, 'listar']);
$router->get('/api/pacientes/mascotas/{id}', [MascotaControlador::class, 'ver']);
