<?php

// El horario de atención de los turnos, que la veterinaria cambia desde su panel (claves hora_apertura,
// hora_cierre y, para el horario cortado, hora_apertura_2 y hora_cierre_2 de la tabla configuracion;
// catálogo en Backend). Si no lo cambió, o lo guardado no sirve, es el de siempre
// (ValidadorTurno::HORA_APERTURA y HORA_CIERRE).
class HorarioAtencion
{
    // ['apertura' => '09:00', 'cierre' => '19:00',
    //  'franjas' => [['apertura' => '09:00', 'cierre' => '13:00'], ['apertura' => '16:00', 'cierre' => '19:00']]]
    // apertura es la del primer horario y cierre la del último (sirven de mínimo y máximo); los turnos
    // se pueden dar dentro de alguna de las franjas.
    public static function actual(): array
    {
        $primera = [
            'apertura' => Configuracion::hora('hora_apertura', ValidadorTurno::HORA_APERTURA),
            'cierre' => Configuracion::hora('hora_cierre', ValidadorTurno::HORA_CIERRE),
        ];
        // Backend no deja guardar un cierre antes de la apertura; si igual pasara, el de siempre.
        if ($primera['apertura'] >= $primera['cierre']) {
            return ValidadorTurno::horarioPorDefecto();
        }

        $franjas = [$primera];
        $segunda = [
            'apertura' => Configuracion::hora('hora_apertura_2', ''),
            'cierre' => Configuracion::hora('hora_cierre_2', ''),
        ];
        // La segunda, solo si está completa y después de la primera (si no, se atiende de corrido).
        if ($segunda['apertura'] !== '' && $segunda['cierre'] !== ''
            && $segunda['apertura'] >= $primera['cierre'] && $segunda['apertura'] < $segunda['cierre']) {
            $franjas[] = $segunda;
        }

        return ['apertura' => $primera['apertura'], 'cierre' => end($franjas)['cierre'], 'franjas' => $franjas];
    }
}
