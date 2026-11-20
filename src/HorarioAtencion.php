<?php

// El horario de atención de los turnos, que la veterinaria cambia desde su panel (claves
// hora_apertura y hora_cierre de la tabla configuracion; catálogo en Backend).
// Si no lo cambió, o lo guardado no sirve, es el de siempre (ValidadorTurno::HORA_APERTURA y HORA_CIERRE).
class HorarioAtencion
{
    // ['apertura' => '08:00', 'cierre' => '20:00']
    public static function actual(): array
    {
        $horario = [
            'apertura' => Configuracion::hora('hora_apertura', ValidadorTurno::HORA_APERTURA),
            'cierre' => Configuracion::hora('hora_cierre', ValidadorTurno::HORA_CIERRE),
        ];

        // Backend no deja guardar un cierre antes de la apertura; si igual pasara, el de siempre.
        return $horario['apertura'] < $horario['cierre'] ? $horario : ValidadorTurno::horarioPorDefecto();
    }
}
