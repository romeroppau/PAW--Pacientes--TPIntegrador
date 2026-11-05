<?php

// Rutas de la consulta, la factura y el historial clínico (rol 4).
// Contrato en docs/api/contrato-consultas.md. index.php carga todos los archivos de src/rutas/.

$router->post('/api/pacientes/turnos/{id}/atencion', [ConsultaControlador::class, 'iniciarAtencion']);

$router->get('/api/pacientes/consultas/{id}', [ConsultaControlador::class, 'ver']);
$router->put('/api/pacientes/consultas/{id}', [ConsultaControlador::class, 'guardar']);
$router->post('/api/pacientes/consultas/{id}/insumos', [ConsultaControlador::class, 'agregarInsumo']);
$router->post('/api/pacientes/consultas/{id}/productos', [ConsultaControlador::class, 'agregarProducto']);
$router->delete('/api/pacientes/consultas/{id}/insumos/{itemId}', [ConsultaControlador::class, 'quitarInsumo']);
$router->delete('/api/pacientes/consultas/{id}/productos/{itemId}', [ConsultaControlador::class, 'quitarProducto']);
$router->post('/api/pacientes/consultas/{id}/finalizar', [ConsultaControlador::class, 'finalizar']);
$router->get('/api/pacientes/consultas/{id}/factura', [ConsultaControlador::class, 'factura']);

$router->get('/api/pacientes/mascotas/{id}/historial', [ConsultaControlador::class, 'historial']);