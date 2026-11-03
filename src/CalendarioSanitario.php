<?php

// Calendario sanitario de cada mascota (plan_sanitario y evento_sanitario).
// Lo completa el rol 3. El rol 4 llama a marcarAplicado() al finalizar
// una consulta de vacunación.
class CalendarioSanitario
{
    public function __construct(private PDO $db)
    {
    }

    // Genera los eventos sanitarios según la especie y fecha de nacimiento.
    public function generarPara(int $mascotaId): void
    {
        // Pendiente de implementación (rol 3).
    }

    // Marca un evento como aplicado y lo vincula con la consulta.
    public function marcarAplicado(int $eventoId, int $consultaId): void
    {
        // Pendiente de implementación (rol 3).
    }
}
