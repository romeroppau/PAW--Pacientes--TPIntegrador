# HuellaVet — Pacientes

Repositorio del módulo **Pacientes** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Es la **ficha clínica**: mascotas, turnos, consultas con su factura, historial clínico, calendario sanitario y turnos con descuento.
Todas sus rutas empiezan con `/api/pacientes/`.

## Autores

| Integrante | Legajo | Qué hizo en este módulo |
| --- | --- | --- |
| Ana Paula Romero | 195388 | Esqueleto del módulo y contratos iniciales |
| Maria Trinidad Lopez | 197958 | — |
| Valentino Aimale | 197961 | Mascotas, turnos y calendario sanitario |
| Cristian Tomás Anito | 158887 | Consultas, factura e historial clínico |

## Documentación

- [Contrato de mascotas y turnos](./docs/api/contrato-mascotas-turnos.md) (incluye el precio del turno y las franjas promocionadas)
- [Contrato del calendario sanitario](./docs/api/contrato-calendario.md)
- [Contrato de consultas, factura e historial](./docs/api/contrato-consultas.md)
- [Contrato de Adopciones](https://github.com/romeroppau/PAW--Adopciones--TPIntegrador/blob/dev/docs/api/contrato-adopciones.md), sección 7: la transferencia de la mascota al adoptante.
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/dev/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.
- DER y UML: en el repo [Backend](https://github.com/romeroppau/PAW--Backend--TPIntegrador/tree/dev/docs).

---

## Qué tiene

### Mascotas

| Endpoint | Quién | Qué hace |
| --- | --- | --- |
| `GET /api/pacientes/mascotas` | Cliente / veterinaria | El cliente ve las suyas; la veterinaria todas, con su dueño, y las busca por nombre de la mascota o del dueño |
| `GET`, `PUT`, `DELETE /api/pacientes/mascotas/{id}`, `POST /api/pacientes/mascotas` | Cliente | Alta, edición y baja lógica. No se puede dar de baja una mascota con turnos programados |
| `GET /api/pacientes/mascotas/{id}/foto` | Con sesión | La foto de la mascota |
| `GET /api/pacientes/mascotas/{id}/historial` | Dueño / veterinaria | Historia clínica |
| `GET /api/pacientes/mascotas/{id}/calendario-sanitario` | Dueño / veterinaria | Calendario de vacunas y desparasitaciones |

### Turnos

Los turnos se coordinan por **WhatsApp** y los carga la veterinaria en la agenda.

| Endpoint | Qué hace |
| --- | --- |
| `GET /api/pacientes/turnos` | La agenda del día para la veterinaria; los turnos de sus mascotas para el cliente (próximos y anteriores, con filtros) |
| `POST /api/pacientes/turnos` | Cargar un turno. Un veterinario no puede tener dos turnos a la misma hora; un turno cancelado libera su horario |
| `POST …/turnos/{id}/reprogramar`, `…/cancelar`, `…/ausente` | Reprogramar, cancelar o marcar ausente (solo si ya llegó su día) |
| `GET /api/pacientes/horario` | El horario de atención que configuró la veterinaria |

Motivos: `CONSULTA`, `VACUNACION` y `CHEQUEO_ADOPCION` (el turno de chequeo obligatorio de una adopción, que el adoptante ve en sus turnos).

### Consulta y factura

| Endpoint | Qué hace |
| --- | --- |
| `POST /api/pacientes/turnos/{id}/atencion` | Inicia la atención del turno |
| `GET`, `PUT /api/pacientes/consultas/{id}` | Diagnóstico, observaciones y valor base |
| `POST …/consultas/{id}/insumos`, `…/productos` y `DELETE …/{itemId}` | Insumos usados y productos vendidos en la consulta |
| `POST …/consultas/{id}/finalizar` | Inventario descuenta el stock (`POST /internal/stock/descontar`), se genera la factura `C-000123`, la consulta queda finalizada y el turno atendido. Si falta stock, no se finaliza y se informa qué falta. Repetir el pedido no descuenta dos veces |
| `GET …/consultas/{id}/factura` | La factura de la consulta |

Si el turno es de vacunación, se marca en el calendario la vacuna que le tocaba a la mascota.

### Calendario sanitario

- Al dar de alta una mascota se arma su calendario con el plan de vacunas y desparasitaciones de su especie y su edad.
- Cada vacuna figura como aplicada, vencida, próxima (en los próximos 30 días) o pendiente, y se indica si tiene las vacunas al día.
- El motor diario de **Reportes** lee este calendario para mandar los avisos de vacuna próxima y vencida.

### Precio del turno y franjas promocionadas

| Endpoint | Quién | Qué hace |
| --- | --- | --- |
| `GET /api/pacientes/promociones-turnos` | Público | El precio de la consulta y las franjas con descuento |
| `POST /api/pacientes/promociones-turnos` | Veterinaria | Publica un descuento para una franja (un día de lunes a sábado, a la mañana o a la tarde), desde "Qué promocionar" de Reportes |
| `DELETE /api/pacientes/promociones-turnos/{id}` | Veterinaria | Termina la promoción |

El precio base sale de la configuración (`precio_consulta`). El descuento es de 5 a 90 % y dura como mucho 90 días. Cada turno guarda su precio al sacarse o reprogramarse, así no le cambia si después termina la promoción.

### Interno (no es público)

- `POST /internal/mascotas/{id}/transferir`: Adopciones lo llama al completar una adopción, para pasar la ficha de la mascota del rescatista al adoptante.

---

## Seguridad

- Sesión con JWT en cookie `HttpOnly`, `Secure` y `SameSite=Strict`, y token CSRF en todos los `POST`, `PUT` y `DELETE`.
- El usuario (dueño o veterinaria) sale siempre de la sesión, nunca del cuerpo del pedido.
- Un cliente solo ve sus mascotas, sus turnos, su historial y su calendario. La mascota de otro cliente responde 404, igual que si no existiera, para no revelar que existe.
- El módulo se conecta a MySQL con su propio usuario (`pacientes`), que solo puede escribir sus tablas y leer las que necesita de los demás. No puede borrar mascotas, turnos ni consultas.
- Las llamadas con Inventario y Adopciones van por `/internal/` con el token entre servicios; Nginx no las expone afuera.
- Las fotos se validan por su contenido real (PNG, JPG o WEBP).
- Todas las consultas a la base usan parámetros (PDO). Los errores no muestran detalles internos.
- Las fechas y horas se calculan con la zona de Argentina, no con la de MySQL (que está en UTC).

---

## Base de datos

Las tablas se crean con las migraciones del repo **ApiGateway** (`base/`): `004` (mascotas y turnos), `005` (calendario), `006` (consultas) y `020` (promoción de turnos).

| Tabla | Para qué |
| --- | --- |
| `mascota` | La ficha del animal, su foto y su dueño. Baja lógica (`activo`) |
| `turno` | La agenda: fecha, hora, motivo, precio y estado (`PROGRAMADO`, `EN_ATENCION`, `ATENDIDO`, `CANCELADO`, `AUSENTE`) |
| `consulta` | La atención de un turno: diagnóstico, observaciones y valor base |
| `item_insumo_consulta`, `item_producto_consulta` | Insumos usados y productos vendidos en la consulta, con el costo o precio de ese momento |
| `factura_consulta` | La factura de la consulta (`C-` y el número) |
| `plan_sanitario` | El plan de vacunas y desparasitaciones por especie y edad (catálogo fijo) |
| `evento_sanitario` | El calendario de cada mascota: cada vacuna con su fecha programada y la de aplicación |
| `promocion_franja` | Los descuentos de las franjas de turnos |

---

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código común, registra las rutas y responde 500 si algo falla de forma inesperada |
| `src/rutas/*.php` | Las rutas de cada tema (`turnos`, `mascotas`, `consultas`, `calendario`, `interno`, `salud`) |
| `src/TurnoControlador.php`, `TurnoRepositorio.php`, `HorarioAtencion.php` | Agenda, alta, reprogramación y cambio de estado de los turnos, y el horario de atención |
| `src/MascotaControlador.php`, `MascotaRepositorio.php`, `Imagen.php` | Mascotas y su foto |
| `src/MascotaInternoControlador.php` | La transferencia de la mascota al adoptante |
| `src/ConsultaControlador.php`, `ConsultaRepositorio.php`, `FacturaConsulta.php` | Consulta, ítems, finalizar, factura e historial |
| `src/CalendarioSanitario.php`, `CalendarioControlador.php` | Armado del calendario, vacuna aplicada y estado de cada evento |
| `src/PrecioTurno.php`, `PromocionFranjaControlador.php`, `PromocionFranjaRepositorio.php` | El precio del turno y las franjas promocionadas |
| `src/ClienteInventario.php` | La llamada interna a Inventario para descontar stock |
| `src/Validador*.php`, `ParametroRuta.php` | Validación de cada pedido (sin base de datos) |

Los controladores controlan la sesión, el rol y el CSRF y arman la respuesta; los repositorios tienen todas las consultas SQL y las transacciones; los validadores y las reglas (calendario, factura, precio del turno) no usan la base, para poder probarlos con PHPUnit.

---

## Cómo correrlo

No se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway** (proceso `127.0.0.1:9003`). Nginx deriva `/api/pacientes/` a este módulo. Los pasos están en el README de [ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador#cómo-correrlo).

```bash
curl http://localhost:10000/api/pacientes/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Pacientes"}`.

### Variables propias

| Variable | Para qué |
| --- | --- |
| `DB_USER`, `DB_PASSWORD` | Se los pasa `iniciar.sh` desde `PACIENTES_DB_USER` y `PACIENTES_DB_PASSWORD`. Si faltan, el módulo no se inicia |
| `JWT_PUBLIC_KEY` | Leer la sesión (no recibe la clave privada) |
| `INTERNAL_TOKEN` | Llamar a Inventario y validar la transferencia que pide Adopciones |
| `INVENTARIO_INTERNAL_URL` | Opcional. Por defecto `http://127.0.0.1:9002` |

Para tener datos de prueba (cuentas, mascotas, turnos de hoy, insumos y una consulta facturada), correr el sembrador del ApiGateway. Las cuentas están en `base/semillas/README.md` de ese repo.

---

## Pruebas

- **PHPUnit** (`tests/`): validadores de mascota, turno, consulta, filtros y transferencia; cálculo de la factura; reglas del calendario; precio del turno con promoción; y la transferencia de la mascota. Necesita PHP 8.4 y la carpeta `comun/` del ApiGateway:

  ```bash
  composer install
  COMUN_DIR=../PAW--ApiGateway--TPIntegrador/comun vendor/bin/phpunit --testdox
  ```

- **Pruebas de humo** (repo ApiGateway, `pruebas/casos/`): `05_mascotas_turnos.sh`, `06_calendario.sh`, `07_consultas.sh` y `18_promocion_turnos.sh`.
- **GitHub Actions** corre la sintaxis, que cada archivo empiece con `<?php` y PHPUnit en cada push y en cada pull request.

---

## No incluido

- **Huellitas** (programa de puntos): la factura tiene la columna `huellitas_usadas`, pero no se usa.
- **Pedido de turnos desde la web:** los turnos se siguen coordinando por WhatsApp y los carga la veterinaria.
