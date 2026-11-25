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

## Cómo se arma el calendario (`CalendarioSanitario::generarPara`)
- Se usa el plan de la especie de la mascota y su fecha de nacimiento. Sin fecha de nacimiento no se
  puede armar: el calendario queda vacío.
- La primera fecha de cada ítem del plan es el nacimiento más `edad_inicio_semanas`.
- Si esa fecha ya pasó:
  - un ítem que **no se repite** (por ejemplo, las dosis de cachorro) no se agrega: la mascota ya
    pasó esa edad y no sabemos si se lo dieron;
  - un ítem que **se repite** se agrega en la próxima fecha que le toque desde hoy.
- No se repite un ítem del plan que la mascota ya tiene en su calendario. Llamarlo dos veces no duplica nada.
- Se llama al dar de alta una mascota (etapa 3) y, si una mascota todavía no tiene calendario, la
  primera vez que alguien lo pide.
- Solo usa los ítems con `automatico = 1`. Los opcionales (tos de las perreras, leucemia felina) los
  agrega el veterinario (migración 025).

## Aplicar una vacuna (`CalendarioSanitario::marcarAplicado`)
- Lo llama la consulta (rol 4) al finalizar un turno de `VACUNACION` o `DESPARASITACION`, dentro de su
  transacción. Marca el evento del turno (`turno.evento_sanitario_id`, migración 025).
- Marca el evento con la fecha de hoy y la consulta en la que se aplicó.
- Si el ítem del plan se repite (por ejemplo, la antirrábica cada 12 meses), agrega el siguiente
  evento: la fecha de aplicación más `repetir_cada_meses`.
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
      "fecha_programada": "2026-10-08", "fecha_aplicada": "2026-10-08", "en_consulta": true, "turno": null, "estado": "APLICADO" },
    { "id": 106, "tipo": "VACUNA", "nombre": "Séxtuple (2.ª dosis)", "plan_sanitario_id": 3, "aplicacion": "SEXTUPLE",
      "fecha_programada": "2026-10-29", "fecha_aplicada": null, "en_consulta": false,
      "turno": { "id": 102, "fecha": "2026-11-25" }, "estado": "VENCIDO" }
  ],
  "plan": [
    { "id": 2, "tipo": "VACUNA", "nombre": "Séxtuple (1.ª dosis)", "aplicacion": "SEXTUPLE", "orden": 1,
      "se_repite_cada_meses": null, "automatico": true },
    { "id": 13, "tipo": "VACUNA", "nombre": "Tos de las perreras", "aplicacion": "TOS_PERRERAS", "orden": 1,
      "se_repite_cada_meses": 12, "automatico": false }
  ]
}
```
Los eventos van ordenados por fecha programada. Desde la migración 025:
- `en_consulta`: `true` si se dio en una consulta de HuellaVet; `false` si se marcó a mano (por ejemplo,
  se la dieron en otra veterinaria) o si todavía no se dio.
- `turno`: el turno programado o en atención para darla, o `null`.
- `plan`: los ítems del plan de la especie, para agregarlos al calendario y elegir la vacuna de un turno.
  `aplicacion` dice qué vacuna es (la misma que `insumo.aplicacion`) y `orden`, qué dosis.
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
- 409: ya está en el calendario sin dar (`"Ya está en el calendario y todavía no se dio..."`), o es de
  una sola vez y ya se dio.

### Marcar como dada (fuera de una consulta)
`POST /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}/dada` — `{"fecha_aplicada": "2026-11-20"}`
- Para lo que se dio en otra veterinaria. Queda con `en_consulta: false`. Si se repite, se agrega la
  siguiente desde esa fecha.
- 400: la fecha es inválida, posterior a hoy o anterior al nacimiento de la mascota.
- 409: hay una dosis anterior de la misma vacuna sin dar (`"Primero marcá como dada: Séxtuple (1.ª dosis)"`),
  o tiene un turno programado para darla (hay que cancelarlo, o darla en esa consulta).

### Cambiar la fecha
`PUT /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}` — `{"fecha_programada": "2026-12-01"}`
- 400: fecha inválida, anterior a hoy o de más de 3 años adelante.

### Quitar
`DELETE /api/pacientes/mascotas/{id}/calendario-sanitario/{eventoId}`
- Solo una que no se dio. 409 si tiene un turno programado o en atención: primero hay que cancelarlo.

## Orden de las dosis
Una dosis no se agenda ni se marca como dada si la mascota tiene **en su calendario** una dosis
anterior de la misma vacuna (`aplicacion` igual, `orden` menor) sin dar. Las dosis que no están en el
calendario no frenan: un perro adulto no tiene las de cachorro. Para "arrancar por la segunda" (la
primera se la dieron en otra veterinaria), se marca la primera como dada.
