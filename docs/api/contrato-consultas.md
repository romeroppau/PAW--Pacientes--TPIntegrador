# Contrato: consultas, factura e historial

Autor: rol 4 (Cristian). Prefijo de todas las rutas: `/api/pacientes`.

## Reglas generales
- Sin sesión: 401. Con una sesión de otro rol: 403.
- POST, PUT y DELETE exigen el token CSRF: sin él, 403.
- El id del veterinario sale de la sesión, nunca del cuerpo del pedido.
- Los errores devuelven `{"error": "mensaje", "campos": {}}`, sin detalles internos. Los mensajes de validación (400) vienen unidos en `error`.
- Importes: texto con 2 decimales (`"8000.00"`). Cantidades e ids: números enteros.

## Estados
- Turno: `PROGRAMADO` → `EN_ATENCION` (al iniciar la atención) → `ATENDIDO` (al finalizar).
- Consulta: `ABIERTA` → `FINALIZADA`. Una consulta finalizada no se modifica.
- Cancelar o marcar ausente un turno lo hace el rol 3, solo desde `PROGRAMADO`.

## Reglas de negocio
- Un turno tiene una sola consulta, y una consulta una sola factura.
- Cada insumo o producto aparece una vez por consulta: agregarlo de nuevo suma la cantidad.
- El costo o precio unitario se copia al agregar el ítem (de `insumo.costo` y `producto.precio`) y no cambia después.
- El costo del insumo se cobra sin recargo.
- Solo se pueden agregar insumos y productos activos.
- `valor_base` (Entrega 5): al iniciar la atención toma el precio que guardó el turno al sacarse (el precio base de la configuración con el descuento de su franja, si estaba promocionada; ver "Precio del turno y franjas promocionadas" en `contrato-mascotas-turnos.md`). **No se edita**: si el PUT lo manda, responde `400` "El valor de la consulta no se cambia: sale del precio del turno". Un turno de antes de la migración 020 toma el precio base del momento, sin descuento. La consulta trae además `precio_lista` y `descuento` (el precio sin descuento y el porcentaje; `null` si el turno no tenía descuento), y la factura (`consulta.precio_lista` y `consulta.descuento`) también.
- El diagnóstico puede estar vacío mientras la consulta está abierta; es obligatorio para finalizar.
- Total = `valor_base` + subtotal de insumos + subtotal de productos.
- Número de factura: `C-` más el id de la factura con 6 dígitos (`C-000102`). Dentro de la transacción se inserta con un número provisorio y se actualiza al definitivo.

## Forma de la consulta
Los endpoints de abajo devuelven este objeto:

```json
{
  "id": 101, "turno_id": 104, "mascota_id": 3, "motivo": "CONSULTA",
  "estado": "ABIERTA", "diagnostico": null, "observaciones": null,
  "valor_base": "0.00", "finalizada_en": null, "total": "0.00",
  "insumos":   [ { "id": 1, "insumo_id": 3, "nombre": "Gasas", "cantidad": 2, "costo_unitario": "150.50" } ],
  "productos": [ { "id": 1, "producto_id": 7, "nombre": "Alimento", "cantidad": 1, "precio_unitario": "15999.90" } ]
}
```
El `id` de cada ítem es el que se usa para quitarlo.

**`motivos` y `eventos_sanitarios` (migraciones 025 y 026).** `motivos`: todos los del turno (como en `contrato-mascotas-turnos.md`). `eventos_sanitarios`: cada vacuna o desparasitación que se da, `{"id", "nombre", "aplicacion", "insumos": [{"id", "nombre", "stock"}]}`, con los insumos activos de esa aplicación (vacío si no hay ninguna). En la consulta (no en la factura ni en el historial) cada insumo trae además `aplicacion` y `stock`. La factura (`consulta.motivos`) y el historial (`motivos` de cada una) también traen todos los motivos.

## Iniciar atención
`POST /api/pacientes/turnos/{id}/atencion` — Veterinario. Sin cuerpo.
- 201: `{"consulta": { ... }}`
- 404: el turno no existe.
- 409: el turno no está `PROGRAMADO` o ya tiene consulta.

## Ver consulta
`GET /api/pacientes/consultas/{id}` — Veterinario.
- 200: la consulta.
- 404: no existe.

## Guardar diagnóstico, observaciones y valor base
`PUT /api/pacientes/consultas/{id}` — Veterinario.
Cuerpo, todos los campos opcionales: `{"diagnostico": "texto", "observaciones": "texto"}`. Un texto vacío borra el dato. `valor_base` ya no se cambia (sale del turno): si viene, `400`.
- 200: la consulta actualizada.
- 400: `valor_base` negativo o no numérico; texto de más de 5.000 caracteres.
- 404: no existe.
- 409: la consulta está `FINALIZADA`.

## Agregar ítem
`POST /api/pacientes/consultas/{id}/insumos` — cuerpo `{"insumo_id": 3, "cantidad": 2}`.
`POST /api/pacientes/consultas/{id}/productos` — cuerpo `{"producto_id": 7, "cantidad": 1}`.
Veterinario.
- 201: la consulta con sus ítems y total.
- 400: id o cantidad que no son enteros mayores a 0; cantidad mayor a 100.000. El tope es para el total del ítem en la consulta: si el insumo o producto ya estaba, se suma lo que había y el total tampoco puede pasar de 100.000 (`"La cantidad total de este ítem en la consulta no puede superar 100000."`).

- 404: la consulta no existe, o el insumo o producto no existe o está dado de baja.
- 409: la consulta está `FINALIZADA`.

## Quitar ítem
`DELETE /api/pacientes/consultas/{id}/insumos/{itemId}` y `/productos/{itemId}` — Veterinario.
- 200: la consulta actualizada.
- 404: la consulta no existe o el ítem no está en esa consulta.
- 409: la consulta está `FINALIZADA`.

## Finalizar consulta
`POST /api/pacientes/consultas/{id}/finalizar` — Veterinario. Sin cuerpo.

Orden interno:
1. Valida que haya diagnóstico.
2. Si hay ítems, llama a `POST /internal/stock/descontar` de Inventario. Cada ítem manda su `id_operacion` armado siempre igual (`consulta-<id>-insumo-<itemId>` o `consulta-<id>-producto-<itemId>`).
3. En una transacción: crea la factura, deja la consulta `FINALIZADA` y el turno `ATENDIDO`.

Respuestas:
- 200: `{"consulta": { ... }, "factura": {"numero": "C-000102", "subtotal_base", "subtotal_insumos", "subtotal_productos", "total", "estado": "PENDIENTE_PAGO", "fecha"}}`
- Repetir la llamada (doble clic) sobre una consulta ya finalizada: 200 con la misma factura, sin descontar de nuevo.
- 400: falta el diagnóstico.
- 404: no existe.
- 400 también si el total (valor base + insumos + productos) supera 99.999.999,99. No se descuenta nada.
- 409 por stock: `{"error": "Stock insuficiente", "items": [{"tipo": "INSUMO", "id": 3, "stock": 0}]}`. No se finaliza ni se descuenta nada.
- 409 por ítem dado de baja: `{"error": "…", "motivo": "DADO_DE_BAJA", "items": [{"tipo": "INSUMO", "id": 3}]}`. Un insumo o producto se dio de baja después de cargarlo: hay que quitarlo. No se finaliza ni se descuenta nada.
- 409 por cantidad cambiada entre intentos: `{"error": "…", "motivo": "OPERACION_DISTINTA", "items": [{"tipo": "INSUMO", "id": 3, "cantidad_aplicada": 2}]}`. Un intento anterior ya descontó ese ítem con otra cantidad (por ejemplo, se perdió la respuesta y después se cambió la cantidad). No se finaliza ni se descuenta nada; hay que volver a la cantidad descontada.
- 409 por ítem inexistente: Inventario no encontró un insumo o producto de la consulta (respondió 404). No se finaliza nada; hay que quitar el ítem y reintentar.
- 502: Inventario no responde o rechaza el pedido (por ejemplo, token interno inválido). La consulta sigue `ABIERTA`; reintentar es seguro.

Si el turno tiene motivos del calendario (vacunación o desparasitación), dentro de la misma transacción se marca como dado cada evento (`turno_motivo.evento_sanitario_id`, `CalendarioSanitario::marcarAplicado()`). Un turno de `VACUNACION` de antes de la migración no tiene evento: se marca la vacuna pendiente más vieja de la mascota (`vacunaPendiente()`), y si no hay ninguna no se marca nada. Si algo falla, se deshace todo junto con la factura.

**La dosis en los insumos (migración 025).** Por cada evento de `eventos_sanitarios`, para finalizar la consulta tiene que llevar en los insumos uno con la misma `aplicacion` (400: `"Falta cargar en los insumos la dosis de: Antirrábica, Séxtuple (2.ª dosis)."`). Sin stock, Inventario responde el 409 de stock insuficiente de siempre; el stock se carga con `POST /api/inventario/insumos/{id}/stock` (la pantalla lo ofrece ahí mismo).

`finalizada_en` y la `fecha` de la factura están en hora de Argentina.

## Ver factura
`GET /api/pacientes/consultas/{id}/factura` — Veterinario o cliente. El cliente solo ve las facturas de sus mascotas.
- 200: la factura (mismos campos que en finalizar) y, además, el detalle para mostrarla:

```json
{
  "numero": "C-000102", "subtotal_base": "8000.00", "subtotal_insumos": "301.00",
  "subtotal_productos": "15999.90", "total": "24300.90", "estado": "PENDIENTE_PAGO",
  "fecha": "2026-11-10 10:42:00",
  "consulta": {
    "id": 101, "fecha": "2026-11-10", "hora": "10:00", "motivo": "CONSULTA",
    "diagnostico": "Otitis leve", "valor_base": "8000.00",
    "insumos":   [ { "id": 1, "insumo_id": 3, "nombre": "Gasas", "cantidad": 2, "costo_unitario": "150.50" } ],
    "productos": [ { "id": 1, "producto_id": 7, "nombre": "Alimento", "cantidad": 1, "precio_unitario": "15999.90" } ]
  },
  "mascota": { "id": 3, "nombre": "Firulais" },
  "cliente": { "id": 5, "nombre": "Carla Gómez" }
}
```
- 404: no existe, la consulta todavía no está finalizada o (para un cliente) la mascota no es suya.
- El pago (migración 030, 27/11): `medio_pago` (`EFECTIVO`, `TRANSFERENCIA`, `TARJETA` o `null`) y `pagada_en` (hora de Argentina, o `null`). Las facturas pagadas antes del 27/11 tienen los dos en `null`.

## Marcar la factura como pagada (27/11)
`POST /api/pacientes/consultas/{id}/factura/pago` — Veterinario, con token CSRF. La factura se abona en la veterinaria: la veterinaria registra el pago y el medio.

```json
{ "medio_pago": "EFECTIVO" }
```
- 200: la factura, como en "Ver factura", con `estado: "PAGADA"`, `medio_pago` y `pagada_en`. Queda también quién la marcó (`pagada_por`, no se devuelve).
- 400: falta el medio de pago o no es uno de los tres (`campos.medio_pago`).
- 404: la consulta no existe o no está finalizada.
- 409: la factura ya estaba pagada. Dos clics a la vez: la marca uno solo y el otro recibe 409.

## Historial clínico
`GET /api/pacientes/mascotas/{id}/historial` — Cliente (solo sus mascotas) o Veterinario (cualquiera). Los rescatistas reciben 403.
- 200: `{"consultas": [ ... ]}` con las consultas finalizadas, de la más reciente a la más antigua (hasta 50). Cada una trae `id`, `motivo` (el del turno: ver los motivos en `contrato-mascotas-turnos.md`; 25/11), `fecha` (del turno), `finalizada_en`, `diagnostico`, `observaciones`, `valor_base`, `insumos`, `productos` y `factura`.
- 404: la mascota no existe o es de otro cliente (no se revela que existe).
## Pacientes atendidos (Entrega 5)

`GET /api/pacientes/atendidos?buscar=&pagina=` — Veterinario. Lo usa "Historial clínico" de "Mis pacientes": las mascotas con al menos una consulta finalizada, de a 20, la de la consulta más reciente primero.

- `buscar` (opcional, hasta 80 caracteres): parte del nombre de la mascota o del dueño. `%` y `_` se buscan como texto.
- `pagina` (opcional, desde 1).
- 200:

  ```json
  {
    "datos": [
      {
        "mascota": { "id": 101, "nombre": "Firulais", "especie": "PERRO", "raza": "Mestizo", "activo": true },
        "duenio": { "id": 102, "nombre": "Cliente de Prueba" },
        "consultas": 4,
        "ultima_consulta": { "fecha": "2026-11-20", "diagnostico": "Otitis externa leve." }
      }
    ],
    "pagina": 1, "por_pagina": 20, "hay_mas": false
  }
  ```

- 400: la búsqueda es muy larga o la página no es un entero desde 1. 401 sin sesión; 403 otro rol.

Las consultas de cada una se piden con el historial clínico (`GET /api/pacientes/mascotas/{id}/historial`, arriba).
