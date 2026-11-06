# HuellaVet — Pacientes

Repositorio del módulo **Pacientes** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Es la **ficha clínica**: mascotas, turnos, consultas con su factura, historial clínico y calendario sanitario.
Todas sus rutas empiezan con `/api/pacientes/`.

## Autores

| Integrante | Legajo | Qué hizo en este módulo |
| --- | --- | --- |
| Ana Paula Romero | 195388 | Esqueleto del módulo y contratos iniciales |
| Maria Trinidad Lopez | 197958 | — |
| Valentino Aimale | 197961 | Mascotas, turnos y calendario sanitario |
| Cristian Tomás Anito | 158887 | Consultas, factura e historial clínico |

## Documentación

- [Contrato de mascotas y turnos](./docs/api/contrato-mascotas-turnos.md)
- [Contrato del calendario sanitario](./docs/api/contrato-calendario.md)
- [Contrato de consultas, factura e historial](./docs/api/contrato-consultas.md)
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/dev/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.
- DER y UML: en el repo [Backend](https://github.com/romeroppau/PAW--Backend--TPIntegrador/tree/dev/docs).

## Versión 0.2.0 (Entrega 4) — qué tiene

### Funcionalidades

**Turnos** (los turnos se coordinan por WhatsApp y los carga el veterinario):
- Agenda del veterinario de un día, con todos los turnos y su estado.
- Cargar un turno. Un veterinario no puede tener dos turnos a la misma hora; un turno cancelado libera su horario.
- Cancelar un turno, o marcarlo ausente (solo si ya llegó su día).
- El cliente ve los turnos de sus mascotas.

**Mascotas:**
- El cliente da de alta, edita y da de baja (baja lógica) sus mascotas. No puede dar de baja una con turnos programados.
- El veterinario ve todas, con su dueño, y las busca por nombre de la mascota o del dueño.

**Consulta y factura** (el corazón de la entrega):
- El veterinario inicia la atención de un turno, carga diagnóstico, observaciones y valor base, y agrega o quita insumos y productos.
- Al finalizar, Inventario descuenta el stock (`POST /internal/stock/descontar`), se genera la factura `C-000123`, la consulta queda finalizada y el turno atendido. Si falta stock, no se finaliza y se informa qué falta. Repetir el pedido (doble clic) no descuenta dos veces.
- Si el turno es de vacunación, se marca en el calendario la vacuna que le tocaba a la mascota.
- Historial clínico de cada mascota, para el cliente (las suyas) y el veterinario.

**Calendario sanitario:**
- Al dar de alta una mascota se arma su calendario con el plan de vacunas y desparasitaciones de su especie y su edad.
- Cada vacuna figura como aplicada, vencida, próxima (en los próximos 30 días) o pendiente, y se indica si tiene las vacunas al día.

### Seguridad

- Sesión con JWT en cookie `HttpOnly`, `Secure` y `SameSite=Strict`, y token CSRF en todos los POST, PUT y DELETE.
- El usuario (dueño o veterinario) sale siempre de la sesión, nunca del cuerpo del pedido.
- Un cliente solo ve sus mascotas, sus turnos, su historial y su calendario. La mascota de otro cliente responde 404, igual que si no existiera, para no revelar que existe.
- El módulo se conecta a MySQL con su propio usuario (`pacientes`), que solo puede escribir sus tablas y leer `usuario`, `producto` e `insumo`. No puede borrar mascotas, turnos ni consultas.
- La llamada a Inventario va por `/internal/` con un token interno; Nginx no la expone afuera.
- Todas las consultas a la base usan parámetros (PDO). Los errores no muestran detalles internos.
- Las fechas y horas se calculan con la zona de Argentina, no con la de MySQL (que está en UTC).

### Base de datos

Las tablas se crean con las migraciones 004, 005 y 006 del repo **ApiGateway** (`base/`):

| Tabla | Para qué |
| --- | --- |
| `mascota` | La ficha del animal y su dueño. Baja lógica (`activo`) |
| `turno` | La agenda: fecha, hora, motivo y estado (`PROGRAMADO`, `EN_ATENCION`, `ATENDIDO`, `CANCELADO`, `AUSENTE`) |
| `consulta` | La atención de un turno: diagnóstico, observaciones y valor base |
| `item_insumo_consulta`, `item_producto_consulta` | Insumos usados y productos vendidos en la consulta, con el costo o precio de ese momento |
| `factura_consulta` | La factura de la consulta (`C-` y el número) |
| `plan_sanitario` | El plan de vacunas y desparasitaciones por especie y edad (catálogo fijo) |
| `evento_sanitario` | El calendario de cada mascota: cada vacuna con su fecha programada y la de aplicación |

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código común, registra las rutas y responde 500 si algo falla de forma inesperada |
| `src/rutas/*.php` | Las rutas de cada tema (`turnos`, `mascotas`, `consultas`, `calendario`, `salud`) |
| `src/TurnoControlador.php`, `TurnoRepositorio.php` | Agenda, alta y cambio de estado de los turnos |
| `src/MascotaControlador.php`, `MascotaRepositorio.php` | Lectura, alta, edición y baja de mascotas |
| `src/ConsultaControlador.php`, `ConsultaRepositorio.php` | Consulta, ítems, finalizar, factura e historial |
| `src/CalendarioSanitario.php`, `CalendarioControlador.php` | Armado del calendario, vacuna aplicada y estado de cada evento |
| `src/ClienteInventario.php` | La llamada interna a Inventario para descontar stock |
| `src/FacturaConsulta.php` | Cálculo de los importes y número de la factura |
| `src/Validador*.php` | Validación de los datos de cada pedido (sin base de datos) |
| `src/ParametroRuta.php` | Lectura del `{id}` de la ruta |

Los controladores controlan la sesión, el rol y el CSRF y arman la respuesta; los repositorios tienen todas las consultas SQL y las transacciones; los validadores y las reglas (fechas del calendario, importes de la factura) no usan la base, para poder probarlos con PHPUnit.

## Cómo correrlo

Como los demás módulos, no se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway**, junto con Nginx, el código común (`comun/`) y los demás módulos. Nginx deriva `/api/pacientes/` a este módulo.

Los pasos para levantar todo están en el README de [Backend](https://github.com/romeroppau/PAW--Backend--TPIntegrador#cómo-correrlo). Una vez levantado:

```bash
curl http://localhost:10000/api/pacientes/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Pacientes"}`.

Para tener datos de prueba (cuentas, mascotas, turnos de hoy, insumos y una consulta facturada), correr el
sembrador del ApiGateway. Las cuentas y los datos están en `base/semillas/README.md` de ese repo.

## Pruebas

- **PHPUnit** (`tests/`): validadores, cálculo de la factura y reglas del calendario. Necesita PHP 8.4 y la carpeta `comun/` del ApiGateway:

  ```bash
  composer install
  COMUN_DIR=../PAW--ApiGateway--TPIntegrador/comun vendor/bin/phpunit --testdox
  ```

- **Pruebas de humo** (repo ApiGateway, `pruebas/casos/`): `05_mascotas_turnos.sh`, `06_calendario.sh` y `07_consultas.sh` prueban estos endpoints contra el sistema levantado.
- **GitHub Actions** corre la sintaxis, que cada archivo empiece con `<?php` y PHPUnit en cada push y en cada pull request.

## Pendiente

- **Foto de la mascota**: la columna existe (`foto` y `foto_tipo`), pero la API todavía no la recibe.
- **Chequeo de adopción** (`CHEQUEO_ADOPCION`): el motivo de turno existe, pero el circuito de adopciones es de la Entrega 5.
- **Huellitas**: la factura tiene `huellitas_usadas`, que se usa cuando exista el programa de puntos (Entrega 5).
- **Notificaciones** de vacunas próximas o vencidas (Entrega 5).
