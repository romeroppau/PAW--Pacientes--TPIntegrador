<?php

// Comunicación interna entre módulos (Nginx bloquea /internal/ desde afuera).
// Adopciones pasa la mascota al adoptante al completar la adopción (contrato-adopciones.md).
$router->post('/internal/mascotas/{id}/transferir', [MascotaInternoControlador::class, 'transferir']);
