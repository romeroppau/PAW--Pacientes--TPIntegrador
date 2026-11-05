# Contrato: mascotas y turnos

Autor: rol 3 (Valentino). Prefijo de todas las rutas: `/api/pacientes`.

Esta es la etapa 1 (lo que necesita la consulta): la agenda, el cambio de estado de los turnos y la
lectura de mascotas. El alta de turnos y el ABM de mascotas son de la etapa 3 y se suman a este contrato.

## Reglas generales
- Sin sesión: 401. Con una sesión de otro rol: 403. Los rescatistas reciben 403 en todas estas rutas.
- POST exige el token CSRF: sin él, 403.
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
