<?php

// Ruta del calendario sanitario (rol 3). Contrato en docs/api/contrato-calendario.md.

$router->get('/api/pacientes/mascotas/{id}/calendario-sanitario', [CalendarioControlador::class, 'ver']);
