# Contrato: mascotas y turnos

Autor: rol 3 (Valentino). Prefijo de todas las rutas: `/api/pacientes`.

Etapa 1 (lo que necesita la consulta): la agenda, el cambio de estado de los turnos y la lectura de
mascotas. Etapa 3: el alta de turnos (veterinario) y el alta, la edición y la baja de mascotas (cliente).

## Reglas generales
- Sin sesión: 401. Con una sesión de otro rol: 403.
- **Rescatistas (Entrega 5):** usan las rutas de mascotas igual que un cliente, para los animales que
  tienen a cargo mientras están en adopción (listar, ver, foto, alta, edición y baja; solo las suyas).
  Los turnos, el historial y el calendario siguen siendo de la veterinaria y del cliente: el
  rescatista recibe 403. Al completar la adopción, la mascota pasa al adoptante con su ficha
  (`POST /internal/mascotas/{id}/transferir`, al final de este documento).
- POST, PUT y DELETE exigen el token CSRF: sin él, 403.
- Los datos inválidos dan 400 con un error por campo: `{"error": "Revisá los datos…", "campos": {"nombre": "El nombre es obligatorio"}}`.
- El id del usuario sale de la sesión, nunca del pedido.
- Un cliente solo ve sus mascotas y los turnos de sus mascotas. La mascota de otro cliente da 404,
  igual que si no existiera (no se revela que existe).
- Los errores devuelven `{"error": "mensaje", "campos": {}}`, sin detalles internos.
- Fechas `AAAA-MM-DD`, horas `HH:MM`. "Hoy" es la fecha de Argentina.

## Estados del turno
- `PROGRAMADO` → `CANCELADO` (cancelar) o `AUSENTE` (marcar ausente), acá.
- `PROGRAMADO` → `EN_ATENCION` → `ATENDIDO`, en la consulta (rol 4, `contrato-consultas.md`).
- Solo se cancela o se marca ausente un turno `PROGRAMADO`. Un turno cancelado libera su horario: el
  veterinario puede cargar otro a la misma hora.

## Forma del turno

```json
{
  "id": 101, "fecha": "2026-11-05", "hora": "08:00", "motivo": "CONSULTA", "estado": "PROGRAMADO",
  "mascota": { "id": 101, "nombre": "Firulais", "especie": "PERRO" },
  "duenio": { "id": 102, "nombre": "Cliente de Prueba", "telefono": "1155550102" },
  "veterinario": { "id": 101, "nombre": "Veterinaria de Prueba" },
  "consulta_id": null,
  "precio": { "base": 15000.00, "descuento": 20, "final": 12000.00 }
}
```
`consulta_id` es la consulta del turno, si ya se inició la atención (para abrirla desde la agenda).

`precio` (Entrega 5): lo que guardó el turno al sacarse (ver "Precio del turno y franjas promocionadas"): el precio base, el descuento de su franja (o `null`) y el valor final de la consulta. `null` en los turnos de antes de la migración 020.

## Agenda y turnos del cliente
`GET /api/pacientes/turnos` — Veterinario o cliente.

**Veterinario:** la agenda de un día, ordenada por hora. Parámetro opcional `fecha` (por defecto, hoy).
- 200: `{"fecha": "2026-11-05", "turnos": [ ... ]}`, con los turnos en todos los estados.
- 400: `fecha` no es una fecha válida.

**Cliente:** los turnos de sus mascotas desde hace 30 días en adelante, ordenados por fecha y hora
(hasta 100). Ignora `fecha`.
- 200: `{"turnos": [ ... ]}`

## Cancelar un turno
`POST /api/pacientes/turnos/{id}/cancelar` — Veterinario. Sin cuerpo.
- 200: `{"turno": { ... }}` con el estado `CANCELADO`.
- 404: el turno no existe.
- 409: el turno no está `PROGRAMADO`.

## Marcar ausente
`POST /api/pacientes/turnos/{id}/ausente` — Veterinario. Sin cuerpo.
- 200: `{"turno": { ... }}` con el estado `AUSENTE`.
- 404: el turno no existe.
- 409: el turno no está `PROGRAMADO`, o es de un día que todavía no llegó.

## Forma de la mascota

```json
{
  "id": 101, "nombre": "Firulais", "especie": "PERRO", "raza": "Mestizo",
  "fecha_nacimiento": "2023-11-05", "edad_meses": 36, "peso_kg": "18.50",
  "tiene_foto": false, "activo": true,
  "duenio": { "id": 102, "nombre": "Cliente de Prueba", "telefono": "1155550102" }
}
```
`raza`, `fecha_nacimiento`, `edad_meses` y `peso_kg` pueden ser `null`. La foto no viaja en la mascota: `tiene_foto` dice si tiene, y se pide aparte (ver "Foto de una mascota").

## Listar mascotas
`GET /api/pacientes/mascotas` — Veterinario, cliente o rescatista. Solo las activas, ordenadas por nombre (hasta 100).
- **Veterinario:** todas. Parámetro opcional `buscar`: parte del nombre de la mascota o del dueño.
- **Cliente o rescatista:** las suyas. Ignora `buscar`.
- (Entrega 5) Parámetro opcional `con_calendario=1`, para los dos: solo las mascotas que tienen vacunas o desparasitaciones registradas (lo usa el Calendario sanitario).
- 200: `{"mascotas": [ ... ]}`
- 400: `buscar` tiene más de 80 caracteres.

## Ver una mascota
`GET /api/pacientes/mascotas/{id}` — Veterinario (cualquiera), cliente o rescatista (solo las suyas).
- 200: la mascota (también si está dada de baja: su ficha y su historial se conservan).
- 404: la mascota no existe o es de otro cliente.

## Foto de una mascota (Entrega 5)
`GET /api/pacientes/mascotas/{id}/foto` — Veterinario (cualquiera), cliente o rescatista (solo las suyas). Se usa directo en un `<img src>`.
- 200: la imagen, con su `Content-Type` (`image/png`, `image/jpeg` o `image/webp`) y `X-Content-Type-Options: nosniff`.
- 404 (JSON): la mascota no existe, es de otro cliente o no tiene foto.

## Cargar un turno (etapa 3)
`POST /api/pacientes/turnos` — Veterinario. Los turnos se coordinan por WhatsApp y los carga el veterinario;
el veterinario del turno es el de la sesión.

Cuerpo: `{"mascota_id": 101, "fecha": "2026-11-10", "hora": "10:30", "motivo": "CONSULTA"}`.
`motivo`: `CONSULTA`, `VACUNACION` o `CHEQUEO_ADOPCION`. El turno queda `PROGRAMADO`.
- 201: `{"turno": { ... }}`
- 400: un error por campo (`mascota_id` no entero; fecha inválida, anterior a hoy o de más de un año adelante; hora que no es `HH:MM` o fuera del horario de atención; motivo inválido). El horario lo configura la veterinaria (ver "Horario de atención"); si no lo cambió, es de 08:00 a 20:00 y el último turno puede empezar a las 19:59.
- 404: la mascota no existe o está dada de baja.
- 409: el veterinario ya tiene un turno a esa hora (los cancelados no cuentan). Viene con `campos.hora`.

## Horario de atención (Entrega 5)
`GET /api/pacientes/horario` — **Público** (sin sesión). El horario en que se pueden dar turnos, que la
veterinaria cambia desde su panel (claves `hora_apertura` y `hora_cierre` y, para el horario cortado,
`hora_apertura_2` y `hora_cierre_2` de la configuración; ver `docs/api/contrato.md` de Backend). Lo
usan los formularios de turnos para el rango de la hora, y la portada del sitio y "Contactanos" de
los paneles para mostrar el horario de atención.
- 200: `{"apertura": "09:00", "cierre": "19:00", "franjas": [{"apertura": "09:00", "cierre": "13:00"}, {"apertura": "16:00", "cierre": "19:00"}]}`.
  `franjas` tiene una (de corrido) o dos (horario cortado). Un turno puede empezar dentro de alguna
  franja: desde su apertura y hasta antes de su cierre (en el corte, `400` en `hora` con
  "El horario de atención es de 09:00 a 13:00 y de 16:00 a 19:00"). `apertura` y `cierre` son la
  primera apertura y el último cierre. De 00:00 a 23:59 es atender las 24 horas.
- Si la veterinaria no lo cambió (o la tabla `configuracion` no existe todavía), es el de siempre: 08:00 a 20:00.
  Una segunda franja incompleta o que se superpone con la primera se ignora.

## Precio del turno y franjas promocionadas (Entrega 5)

La consulta tiene un **precio base** que la veterinaria carga en Configuración (`precio_consulta`, catálogo en Backend; 15000 por defecto). Las franjas con menos turnos se pueden **promocionar** con un descuento, desde "Qué promocionar" de Reportes (como los productos por vencer). Tabla `promocion_franja` y columnas `precio_consulta`, `descuento` y `promocion_franja_id` de `turno` (migración 020).

- **Franja:** un día de lunes a sábado (`dia_semana` 1 a 6) y `MANIANA` (antes de las 13:00) o `TARDE` (desde las 13:00). Es la misma que usa el motor de Reportes.
- **El turno guarda su precio al sacarse:** el precio base de ese momento y, si su franja tiene una promoción vigente que llega hasta el día del turno, su descuento. Aunque después la promoción termine o cambie el precio base, el turno conserva lo prometido. **Reprogramar** es como sacarlo de nuevo: toma el precio y la promoción de la franja nueva.
- **La consulta** arranca con ese valor (`valor_base`) y no se edita (`contrato-consultas.md`). Un turno de antes de la migración 020 usa el precio base del momento, sin descuento.
- Descuento de 5 a 90 %, hasta el día `hasta` inclusive (desde hoy y como mucho 90 días). Una sola promoción vigente por franja; no se borran (terminar una guarda cuándo).

### GET /api/pacientes/promociones-turnos

Público (lo muestra el sitio a los clientes). El precio base y las franjas en promoción:

```json
{
  "precio_consulta": 15000,
  "datos": [
    { "id": 3, "dia_semana": 2, "franja": "TARDE", "porcentaje": 20, "hasta": "2026-12-31",
      "nombre": "Martes a la tarde", "precio": 15000, "precio_promocion": 12000 }
  ]
}
```

### POST /api/pacientes/promociones-turnos

Veterinario, con CSRF. `{ "dia_semana": 2, "franja": "TARDE", "porcentaje": 20, "hasta": "2026-12-31" }`. **201** con la promoción (forma de arriba).

**Errores:** `400` con `campos` (`dia_semana`: "Elegí un día de lunes a sábado"; `franja`: "Elegí mañana o tarde"; `porcentaje`: "El descuento tiene que ser un número entero entre 5 y 90 (por ciento)"; `hasta`: "Elegí hasta qué día dura la promoción", "La promoción no puede terminar antes de hoy" o "La promoción puede durar como mucho 90 días (hasta el dd/mm/aaaa)"); `409` si la franja ya tiene una ("Martes a la tarde ya tiene una promoción del 20 % hasta el 31/12/2026. Terminala para publicar otra.", con `promocion_id`).

### DELETE /api/pacientes/promociones-turnos/{id}

Veterinario, con CSRF. Termina la promoción ahora: los turnos nuevos de esa franja ya no tienen descuento; los que ya se sacaron lo conservan. **204.** `404` "No hay una promoción vigente con ese número".

## Reprogramar un turno (Entrega 5)
`POST /api/pacientes/turnos/{id}/reprogramar` — Veterinario, con CSRF. Cambia la fecha y la hora de un turno que el cliente pidió mover por WhatsApp. La mascota y el motivo no cambian.

Cuerpo: `{"fecha": "2026-11-12", "hora": "17:30"}`. Mismas reglas de fecha y hora que el alta.
- 200: `{"turno": { ... }}` con la fecha y la hora nuevas. Si son las mismas que tenía, también 200.
- 400: un error por campo (`fecha`, `hora`).
- 404: el turno no existe.
- 409: el turno no está `PROGRAMADO`, o el veterinario ya tiene otro turno a esa hora (con `campos.hora`). El horario anterior queda libre al reprogramar.

## Avisos de turnos (25/11)

Cuando la veterinaria **carga**, **reprograma** o **cancela** un turno, al dueño de la mascota le llega un aviso por la campana (`WEB`) y por `WHATSAPP` (tipo `TURNO`, migración 024). Pacientes los deja en Backend por `POST /internal/notificaciones` (`src/AvisosTurno.php`) y Backend manda el WhatsApp en el momento. Si Backend no responde, el turno queda igual y el error va al log. Marcar ausente no avisa. Los turnos de chequeo que crea Adopciones avisan desde Adopciones.

| Cuándo | `id_operacion` |
|---|---|
| Carga | `turno-{id}-asignado-{web\|whatsapp}` |
| Reprograma | `turno-{id}-reprogramado-{AAAAMMDDHHMMSS}-{web\|whatsapp}` |
| Cancela | `turno-{id}-cancelado-{web\|whatsapp}` |

## Dar de alta una mascota (etapa 3)
`POST /api/pacientes/mascotas` — Cliente o rescatista. El dueño es el usuario de la sesión.

Cuerpo: `{"nombre": "Firulais", "especie": "PERRO", "raza": "Mestizo", "fecha_nacimiento": "2023-11-05", "peso_kg": 18.5}`.
- `nombre` (hasta 80) y `especie` (`PERRO`, `GATO` u `OTRO`) son obligatorios.
- `raza` (hasta 80), `fecha_nacimiento` (no futura ni de hace más de 40 años) y `peso_kg` (mayor a 0, hasta 999.99) son opcionales.
- `foto` (Entrega 5), opcional: data URL (`data:image/png;base64,...`) de una imagen PNG, JPG o WEBP de hasta 1 MB. Se revisa el contenido real del archivo, no solo lo que dice el texto.
- Al crearla se arma su **calendario sanitario** (`CalendarioSanitario::generarPara`), en la misma transacción. Sin fecha de nacimiento, el calendario queda vacío.
- 201: la mascota.
- 400: un error por campo.

## Editar una mascota (etapa 3)
`PUT /api/pacientes/mascotas/{id}` — Cliente o rescatista, solo las suyas. Mismo cuerpo y mismas reglas que el alta (se mandan todos los campos).
- `foto`: si no viene, la foto no cambia; `null` la borra; un data URL la reemplaza.
- Si cambia la especie o la fecha de nacimiento, el calendario suma los ítems del plan que le falten; no borra los que ya tenía.
- 200: la mascota actualizada.
- 400: un error por campo.
- 404: no existe o es de otro cliente.
- 409: está dada de baja.

## Dar de baja una mascota (etapa 3)
`DELETE /api/pacientes/mascotas/{id}` — Cliente o rescatista, solo las suyas. Baja lógica: su ficha, su historial y su calendario se conservan.
- 204: sin cuerpo.
- 404: no existe o es de otro cliente.
- 409: ya está dada de baja, o tiene turnos programados de hoy en adelante (primero los cancela el veterinario).

## Transferir una mascota al adoptante (interno, Entrega 5)
`POST http://127.0.0.1:9003/internal/mascotas/{id}/transferir` — **De proceso a proceso**: lo llama
Adopciones al completar una adopción. Nginx bloquea `/internal/` desde afuera (404) y además se exige
el header `X-Servicio-Token: <INTERNAL_TOKEN>`, igual que el descuento de stock de Inventario.

Cuerpo: `{"duenio_actual_id": 104, "nuevo_duenio_id": 103}` (enteros de verdad, distintos, mayores que 0).
Solo cambia el dueño: la ficha, los turnos, las consultas, las facturas y el calendario pasan con la mascota.

| Código | Cuerpo | Cuándo |
|---|---|---|
| 200 | `{"transferida": true}` | Se pasó la mascota al nuevo dueño |
| 200 | `{"transferida": false}` | Ya era del nuevo dueño (un reintento): no cambia nada |
| 400 | `{"error": "Datos de solicitud inválidos"}` | Cuerpo mal formado, o el nuevo dueño no es un cliente |
| 401 | `{"error": "No autorizado"}` | Falta el token o no coincide |
| 404 | `{"error": "Mascota no encontrada"}` | No existe o está dada de baja |
| 409 | `{"error": "La mascota no es del dueño indicado", "motivo": "DUENIO_DISTINTO"}` | No es ni del dueño actual ni del nuevo |

Reintentar es seguro sin `id_operacion`: el estado de la mascota ya dice si la transferencia se hizo.
El circuito completo está en `docs/api/contrato-adopciones.md` del repo Adopciones.
