# Contrato: consultas, factura e historial

Autor: rol 4 (Cristian). Prefijo de todas las rutas: `/api/pacientes`.

Reglas generales:
- Requieren sesión: sin ella, 401.
- Los métodos POST, PUT y DELETE exigen el token CSRF: sin él, 403.
- El `usuario_id` del veterinario sale de la sesión, nunca del cuerpo.
- Los errores devuelven `{"error": "mensaje"}` sin detalles internos.
- Dinero: números con 2 decimales.

## Estados
- Turno: `PROGRAMADO` → `EN_ATENCION` (al iniciar la atención) → `ATENDIDO` (al finalizar).
- Consulta: `ABIERTA` → `FINALIZADA`. Una consulta finalizada no se modifica.
- Cancelar o marcar ausente un turno lo hace el rol 3, solo desde `PROGRAMADO`.

## Reglas de negocio
- Un turno tiene una sola consulta.
- Cada insumo o producto aparece una vez por consulta: agregarlo de nuevo suma la cantidad.
- El costo o precio unitario se copia al agregar el ítem (de `insumo.costo` y `producto.precio`) y no cambia después.
- El costo del insumo se cobra sin recargo.
- El diagnóstico puede estar vacío mientras la consulta está abierta; es obligatorio para finalizar.
- `valor_base` lo carga el veterinario en cada consulta (pendiente de confirmar con el grupo).
- Número de factura: `C-` más el id de la factura con 6 dígitos (`C-000102`).
- Total = `valor_base` + subtotal de insumos + subtotal de productos.

## Iniciar atención
`POST /api/pacientes/turnos/{turnoId}/atencion` — Veterinario. Sin cuerpo.
- 201: `{"consulta": {"id", "turno_id", "estado": "ABIERTA", "diagnostico": null, "observaciones": null, "valor_base": null}}`
- 404: el turno no existe.
- 409: el turno no está `PROGRAMADO` o ya tiene consulta.

## Ver consulta
`GET /api/pacientes/consultas/{id}` — Veterinario.
- 200: la consulta con `insumos` (`id`, `insumo_id`, `nombre`, `cantidad`, `costo_unitario`, `subtotal`), `productos` (igual, con `precio_unitario`) y `total` calculado.
- 404: no existe.

## Guardar diagnóstico, observaciones y valor base
`PUT /api/pacientes/consultas/{id}` — Veterinario.
Cuerpo: `{"diagnostico": "texto", "observaciones": "texto", "valor_base": 8000.00}`. Todos opcionales.
- 200: la consulta actualizada.
- 400: `valor_base` negativo o no numérico.
- 404: no existe. 409: la consulta está `FINALIZADA`.

## Agregar ítem
`POST /api/pacientes/consultas/{id}/insumos` — cuerpo `{"insumo_id": 3, "cantidad": 2}`.
`POST /api/pacientes/consultas/{id}/productos` — cuerpo `{"producto_id": 7, "cantidad": 1}`.
Veterinario.
- 201: la consulta con sus ítems y total.
- 400: cantidad no entera o menor o igual a 0.
- 404: la consulta o el insumo/producto no existen, o están dados de baja.
- 409: la consulta está `FINALIZADA`.

## Quitar ítem
`DELETE /api/pacientes/consultas/{id}/insumos/{itemId}` y `/productos/{itemId}` — Veterinario.
- 200: la consulta actualizada.
- 404: no existe el ítem en esa consulta. 409: la consulta está `FINALIZADA`.

## Finalizar consulta
`POST /api/pacientes/consultas/{id}/finalizar` — Veterinario. Sin cuerpo.

Orden interno:
1. Valida diagnóstico y `valor_base`.
2. Llama a `POST /internal/stock/descontar` de Inventario, un `id_operacion` por ítem armado siempre igual a partir de consulta e ítem.
3. En una transacción: crea la factura, deja la consulta `FINALIZADA` (`WHERE estado = 'ABIERTA'`), el turno `ATENDIDO` y, si el motivo es `VACUNACION`, marca el evento sanitario como aplicado.

- 200: `{"consulta": {...}, "factura": {"numero": "C-000102", "subtotal_base", "subtotal_insumos", "subtotal_productos", "total", "estado": "PENDIENTE_PAGO"}}`
- Segunda llamada (doble clic): 200 con la misma factura, sin descontar de nuevo.
- 400: falta diagnóstico o `valor_base`.
- 404: no existe.
- 409 por stock: `{"error": "Stock insuficiente", "items": [{"tipo": "INSUMO", "id": 3, "stock": 0}]}`. No se finaliza ni se descuenta nada.
- 502: Inventario no responde. La consulta sigue `ABIERTA`; reintentar es seguro.

## Ver factura
`GET /api/pacientes/consultas/{id}/factura` — Veterinario.
- 200: la factura. 404: no existe o la consulta no está finalizada.

## Historial clínico
`GET /api/pacientes/mascotas/{mascotaId}/historial` — Cliente (solo sus mascotas) o Veterinario (cualquiera).
- 200: lista de consultas `FINALIZADAS`, la más reciente primero, con fecha, diagnóstico, observaciones, ítems y factura.
- 404: la mascota no existe o es de otro cliente (no se revela que existe).