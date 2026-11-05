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

## Aplicar una vacuna (`CalendarioSanitario::marcarAplicado`)
- Lo llama la consulta (rol 4) al finalizar un turno de `VACUNACION`, dentro de su transacción.
- Marca el evento con la fecha de hoy y la consulta en la que se aplicó.
- Si el ítem del plan se repite (por ejemplo, la antirrábica cada 12 meses), agrega el siguiente
  evento: la fecha de aplicación más `repetir_cada_meses`.
- Un evento ya aplicado no se vuelve a marcar (devuelve `false`).
- Para saber qué evento marcar, la consulta usa `CalendarioSanitario::vacunaPendiente($mascotaId)`:
  la vacuna sin aplicar con la fecha más vieja (`null` si no hay ninguna).

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
  "mascota": { "id": 103, "nombre": "Luna", "especie": "PERRO", "fecha_nacimiento": "2026-08-27" },
  "vacunas_al_dia": false,
  "eventos": [
    { "id": 105, "tipo": "VACUNA", "nombre": "Séxtuple (1.ª dosis)", "fecha_programada": "2026-10-08",
      "fecha_aplicada": "2026-10-08", "estado": "APLICADO" },
    { "id": 106, "tipo": "VACUNA", "nombre": "Séxtuple (2.ª dosis)", "fecha_programada": "2026-10-29",
      "fecha_aplicada": null, "estado": "VENCIDO" }
  ]
}
```
Los eventos van ordenados por fecha programada.
- 404: la mascota no existe o es de otro cliente.
