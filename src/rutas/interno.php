<?php

// Comunicación interna entre módulos (Nginx bloquea /internal/ desde afuera).
// Adopciones pasa la mascota al adoptante al completar la adopción, y cancela el turno del chequeo
// cuando la rescatista da de baja una publicación con adoptante elegido (contrato-adopciones.md).
$router->post('/internal/mascotas/{id}/transferir', [MascotaInternoControlador::class, 'transferir']);
$router->post('/internal/turnos/{id}/cancelar-chequeo', [TurnoInternoControlador::class, 'cancelarChequeo']);
