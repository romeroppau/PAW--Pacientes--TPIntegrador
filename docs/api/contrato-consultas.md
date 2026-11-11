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
- `valor_base` arranca en `0.00` al iniciar la atención (la columna no admite NULL). El veterinario lo carga con el PUT; si no lo manda, se conserva. Un valor de 0 es válido.
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
Cuerpo, todos los campos opcionales: `{"diagnostico": "texto", "observaciones": "texto", "valor_base": 8000.00}`. Un texto vacío borra el dato.
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

Si el motivo del turno es `VACUNACION`, dentro de la misma transacción se marca como aplicada la vacuna pendiente de la mascota en el calendario sanitario (`CalendarioSanitario::vacunaPendiente()` y `marcarAplicado()`). Si la mascota no tiene una vacuna pendiente, no se marca nada. Si algo falla, se deshace todo junto con la factura.

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

## Historial clínico
`GET /api/pacientes/mascotas/{id}/historial` — Cliente (solo sus mascotas) o Veterinario (cualquiera). Los rescatistas reciben 403.
- 200: `{"consultas": [ ... ]}` con las consultas finalizadas, de la más reciente a la más antigua (hasta 50). Cada una trae `id`, `fecha` (del turno), `finalizada_en`, `diagnostico`, `observaciones`, `valor_base`, `insumos`, `productos` y `factura`.
- 404: la mascota no existe o es de otro cliente (no se revela que existe).