# Contrato: calendario sanitario

Autor: rol 3 (Valentino). Prefijo de todas las rutas: `/api/pacientes`.

El calendario de cada mascota sale del **plan sanitario** (tabla `plan_sanitario`, cargada en la
migración 005): vacunas y desparasitaciones por especie, con la edad a la que se dan y cada cuánto se
repiten. Cada vacuna o desparasitación de una mascota es un **evento** (`evento_sanitario`), con la
fecha en que le toca y la fecha en que se aplicó.

## Reglas generales
- Sin sesión: 401. Con una sesión de otro rol: 403 (los rescatistas reciben 403).
- Un cliente solo ve el calendario de sus mascotas. La mascota de otro cliente da 404, igual que si no existiera.
- Los errores devuelven `{"error": "mensaje", "campos": {}}`, sin detalles internos.
- Fechas `AAAA-MM-DD`. "Hoy" es la fecha de Argentina.

## Cómo se arma el calendario
- **No se arma solo** (25/11): ni al dar de alta la mascota ni al pedirlo. Lo carga la veterinaria, de a
  una vacuna o desparasitación (ver "Editar el calendario"). Hasta entonces `eventos` viene vacío y la
  página le dice al cliente (también al que adoptó) que la veterinaria todavía no lo cargó.
- El plan de la especie (`plan_sanitario`) es la lista de lo que se puede cargar. Los ítems con
  `automatico = 0` (tos de las perreras, leucemia felina) son opcionales.

## Aplicar una vacuna (`CalendarioSanitario::marcarAplicado`)
- Lo llama la consulta (rol 4) al finalizar un turno de `VACUNACION` o `DESPARASITACION`, dentro de su
  transacción. Marca el evento del turno (`turno.evento_sanitario_id`, migración 025).
- Marca el evento con la fecha de hoy y la consulta en la que se aplicó.
- Desde el 26/11 **no agrega la siguiente dosis**: si el ítem se repite, el evento dado trae
  `proxima_sugerida` y la veterinaria la agrega si corresponde (antes se agregaba sola, y con
  "Marcar como dada" se repetían dosis).
- Un evento ya aplicado no se vuelve a marcar (devuelve `false`).
- Un turno de vacunación de antes de la migración 025 no tiene evento: la consulta usa
  `CalendarioSanitario::vacunaPendiente($mascotaId)`, la vacuna sin aplicar con la fecha más vieja
  (`null` si no hay ninguna).

## Estado de cada evento
| Estado | Cuándo |
|---|---|
| `APLICADO` | Tiene fecha de aplicación |
| `VENCIDO` | No se aplicó y su fecha ya pasó |
| `PROXIMO` | No se aplicó y le toca en los próximos 30 días (hoy incluido) |
| `PENDIENTE` | No se aplicó y le toca más adelante |

Una mascota tiene las **vacunas al día** si no tiene ninguna vacuna `VENCIDO` (las desparasitaciones no cuentan).

## Ver el calendario
`GET /api/pacientes/mascotas/{id}/calendario-sanitario` — Cliente (solo sus mascotas) o Veterinario (cualquiera).
- 200:

```json
{
  "mascota": { "id": 103, "nombre": "Luna", "especie": "PERRO", "fecha_nacimiento": "2026-08-27", "duenio": { "id": 103, "nombre": "Tomás Aguirre" } },
  "vacunas_al_dia": false,
  "eventos": [
    { "id": 105, "tipo": "VACUNA", "nombre": "Séxtuple (1.ª dosis)", "plan_sanitario_id": 2, "aplicacion": "SEXTUPLE",
      "fecha_programada": "2026-10-08", "fecha_aplicada": "2026-10-08", "en_consulta": true, "turno": null, "estado": "APLICADO", "proxima_sugerida": null },
    { "id": 106, "tipo": "VACUNA", "nombre": "Séxtuple (2.ª dosis)", "plan_sanitario_id": 3, "aplicacion": "SEXTUPLE",
      "fecha_programada": "2026-10-29", "fecha_aplicada": null, "en_consulta": false,
      "turno": { "id": 102, "fecha": "2026-11-25" }, "estado": "VENCIDO", "proxima_sugerida": null }
  ],
  "plan": [
    { "id": 2, "tipo": "VACUNA", "nombre": "Séxtuple (1.ª dosis)", "aplicacion": "SEXTUPLE", "orden": 1,
      "se_repite_cada_meses": null, "automatico": true, "fecha_minima": null },
    { "id": 13, "tipo": "VACUNA", "nombre": "Tos de las perreras", "aplicacion": "TOS_PERRERAS", "orden": 1,
      "se_repite_cada_meses": 12, "automatico": false, "fecha_minima": "2027-10-01" }
  ]
}
```
Los eventos van ordenados por fecha programada. Desde la migración 025:
- `en_consulta`: `true` si se dio en una consulta de HuellaVet; `false` si se marcó a mano (por ejemplo,
  se la dieron en otra veterinaria) o si todavía no se dio.
- `turno`: el turno programado o en atención para darla (por sus motivos, `turno_motivo`), o `null`.
- `plan`: los ítems del plan de la especie, para agregarlos al calendario y elegir la vacuna de un turno.
  `aplicacion` dice qué vacuna es (la misma que `insumo.aplicacion`) y `orden`, qué dosis.
- `proxima_sugerida` (26/11): en la última dosis dada de un ítem que se repite, si no hay otra sin dar
  del mismo ítem, la fecha aplicada más `se_repite_cada_meses`. Si no, `null`.
- `fecha_minima` (26/11): desde qué fecha se puede cargar ese ítem según las dosis ya dadas y los
  días mínimos (ver "Días mínimos entre dosis"); `null` si no hay dosis anterior.
- 404: la mascota no existe o es de otro cliente.

## Editar el calendario (migración 025)
Solo el veterinario, con CSRF. Cada una responde **200 con el calendario completo** (igual que `GET`).
Errores comunes: 401/403 (sin sesión, otro rol o sin CSRF), 404 (la mascota no existe, o el evento no
es de esa mascota: `"Esa vacuna o desparasitación no está en el calendario de la mascota"`) y 409 si
el evento ya está dado (`"Esa vacuna o desparasitación ya está dada: no se cambia"`).

### Agregar
`POST /api/pacientes/mascotas/{id}/calendario-sanitario` — `{"plan_sanitario_id": 13, "fecha_programada": "2026-12-01"}`
- 400: `campos.plan_sanitario_id` (falta, o el ítem es de otra especie) o `campos.fecha_programada`
  (fecha inválida, anterior a hoy o de más de 3 años adelante).
- 409: las dosis anteriores de la misma vacuna no están cargadas y dadas (`"Para cargarla, primero cargá
  y marcá como dadas: Séxtuple (1.ª dosis), Séxtuple (2.ª dosis)"`); ya está en el calendario sin dar
  (`"Ya está en el calendario y todavía no se dio..."`); es de una sola vez y ya se dio; o no pasaron
  los días mínimos desde la dosis anterior (`campos.fecha_programada`).

### Marcar como dada (fuera de una consulta)
`POST /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}/dada` — `{"fecha_aplicada": "2026-11-20"}`
- Para lo que se dio en otra veterinaria. Queda con `en_consulta: false`. No agrega la siguiente
  (26/11): ver `proxima_sugerida`.
- 400: la fecha es inválida, posterior a hoy o anterior al nacimiento de la mascota.
- 409: hay una dosis anterior de la misma vacuna sin dar (`"Primero marcá como dada: Séxtuple (1.ª dosis)"`),
  o tiene un turno programado para darla (hay que cancelarlo, o darla en esa consulta), o no pasaron
  los días mínimos desde otra dosis dada (`campos.fecha_aplicada`).

### Cambiar la fecha
`PUT /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}` — `{"fecha_programada": "2026-12-01"}`
- 400: fecha inválida, anterior a hoy o de más de 3 años adelante.
- 409: no pasaron los días mínimos desde la dosis anterior (`campos.fecha_programada`).

### Quitar
`DELETE /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}`
- Solo una que no se dio. 409 si tiene un turno programado o en atención: primero hay que cancelarlo.

## Orden de las dosis
- **Agregar:** una dosis se carga solo si todas las anteriores de la misma vacuna (`aplicacion` igual,
  `orden` menor) están en el calendario **y dadas** (en HuellaVet o marcadas a mano). Para la 3.ª
  séxtuple: cargar la 1.ª y la 2.ª, y marcarlas como dadas. Vale también para el refuerzo anual.
- **Turno y marcar como dada:** no se hace si la mascota tiene en el calendario una dosis anterior de
  la misma vacuna sin dar (por ejemplo, en calendarios cargados antes de esta regla).
- Para "arrancar por la segunda" (la primera se la dieron en otra veterinaria): cargar la primera,
  marcarla como dada y después cargar la segunda.

## Días mínimos entre dosis (26/11)
La veterinaria los configura en **Configuración → Vacunas** (Backend, `docs/api/contrato.md`); los
valores por defecto están en `IntervalosVacunas::CLAVES`. Entre 1 y 730 días.

| Vacuna (`aplicacion`) | Entre dosis | Entre refuerzos |
|---|---|---|
| `SEXTUPLE` | `dias_vacuna_sextuple_dosis` (21) | `dias_vacuna_sextuple_refuerzo` (330) |
| `TRIPLE_FELINA` | `dias_vacuna_triple_felina_dosis` (21) | `dias_vacuna_triple_felina_refuerzo` (330) |
| `LEUCEMIA_FELINA` | `dias_vacuna_leucemia_felina_dosis` (21) | `dias_vacuna_leucemia_felina_refuerzo` (330) |
| `ANTIRRABICA` | `dias_vacuna_antirrabica` (330) | la misma |
| `TOS_PERRERAS` | `dias_vacuna_tos_perreras` (330) | la misma |
| `ANTIPARASITARIO` | `dias_desparasitacion` (14) | la misma |

- **Entre dosis:** contra cualquier dosis dada de la misma vacuna, antes o después de la fecha.
- **Entre refuerzos:** solo contra las dosis dadas del mismo ítem que se repite (el refuerzo anual
  contra el refuerzo anterior), así el primer refuerzo no choca con la última dosis de cachorro.
- Se controla al agregar, cambiar la fecha, marcar como dada, sacar un turno de vacunación o
  desparasitación (con la fecha del turno) y reprogramarlo. Si no se cumple: 409 con
  `"Entre dos dosis tienen que pasar al menos 330 días: Antirrábica se dio el 26/11/2026. Antirrábica se
  puede dar desde el 22/10/2027. Los días se cambian en Configuración → Vacunas."`
  (en `campos.fecha_programada`, `campos.fecha_aplicada` o `campos.fecha` del turno).
