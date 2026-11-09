# Contrato: mascotas y turnos

Autor: rol 3 (Valentino). Prefijo de todas las rutas: `/api/pacientes`.

Etapa 1 (lo que necesita la consulta): la agenda, el cambio de estado de los turnos y la lectura de
mascotas. Etapa 3: el alta de turnos (veterinario) y el alta, la edición y la baja de mascotas (cliente).

## Reglas generales
- Sin sesión: 401. Con una sesión de otro rol: 403. Los rescatistas reciben 403 en todas estas rutas.
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
  "consulta_id": null
}
```
`consulta_id` es la consulta del turno, si ya se inició la atención (para abrirla desde la agenda).

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
`raza`, `fecha_nacimiento`, `edad_meses` y `peso_kg` pueden ser `null`. La foto se pide aparte (etapa 3).

## Listar mascotas
`GET /api/pacientes/mascotas` — Veterinario o cliente. Solo las activas, ordenadas por nombre (hasta 100).
- **Veterinario:** todas. Parámetro opcional `buscar`: parte del nombre de la mascota o del dueño.
- **Cliente:** las suyas. Ignora `buscar`.
- 200: `{"mascotas": [ ... ]}`
- 400: `buscar` tiene más de 80 caracteres.

## Ver una mascota
`GET /api/pacientes/mascotas/{id}` — Veterinario (cualquiera) o cliente (solo las suyas).
- 200: la mascota (también si está dada de baja: su ficha y su historial se conservan).
- 404: la mascota no existe o es de otro cliente.

## Cargar un turno (etapa 3)
`POST /api/pacientes/turnos` — Veterinario. Los turnos se coordinan por WhatsApp y los carga el veterinario;
el veterinario del turno es el de la sesión.

Cuerpo: `{"mascota_id": 101, "fecha": "2026-11-10", "hora": "10:30", "motivo": "CONSULTA"}`.
`motivo`: `CONSULTA`, `VACUNACION` o `CHEQUEO_ADOPCION`. El turno queda `PROGRAMADO`.
- 201: `{"turno": { ... }}`
- 400: un error por campo (`mascota_id` no entero; fecha inválida, anterior a hoy o de más de un año adelante; hora que no es `HH:MM` o fuera del horario de atención, de 08:00 a 20:00; motivo inválido). El último turno puede empezar a las 19:59.
- 404: la mascota no existe o está dada de baja.
- 409: el veterinario ya tiene un turno a esa hora (los cancelados no cuentan). Viene con `campos.hora`.

## Dar de alta una mascota (etapa 3)
`POST /api/pacientes/mascotas` — Cliente. El dueño es el cliente de la sesión.

Cuerpo: `{"nombre": "Firulais", "especie": "PERRO", "raza": "Mestizo", "fecha_nacimiento": "2023-11-05", "peso_kg": 18.5}`.
- `nombre` (hasta 80) y `especie` (`PERRO`, `GATO` u `OTRO`) son obligatorios.
- `raza` (hasta 80), `fecha_nacimiento` (no futura ni de hace más de 40 años) y `peso_kg` (mayor a 0, hasta 999.99) son opcionales.
- Al crearla se arma su **calendario sanitario** (`CalendarioSanitario::generarPara`), en la misma transacción. Sin fecha de nacimiento, el calendario queda vacío.
- 201: la mascota.
- 400: un error por campo.

## Editar una mascota (etapa 3)
`PUT /api/pacientes/mascotas/{id}` — Cliente, solo las suyas. Mismo cuerpo y mismas reglas que el alta (se mandan todos los campos).
- Si cambia la especie o la fecha de nacimiento, el calendario suma los ítems del plan que le falten; no borra los que ya tenía.
- 200: la mascota actualizada.
- 400: un error por campo.
- 404: no existe o es de otro cliente.
- 409: está dada de baja.

## Dar de baja una mascota (etapa 3)
`DELETE /api/pacientes/mascotas/{id}` — Cliente, solo las suyas. Baja lógica: su ficha, su historial y su calendario se conservan.
- 204: sin cuerpo.
- 404: no existe o es de otro cliente.
- 409: ya está dada de baja, o tiene turnos programados de hoy en adelante (primero los cancela el veterinario).
