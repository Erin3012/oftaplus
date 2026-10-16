# Modelo de datos de Oftaplus

`schema.sql` es una base MySQL/MariaDB propia para reproducir los flujos visibles en Gesvision sin copiar su base interna ni datos reales.

## Lo observado

- La aplicación trabaja por empresa y sucursal activa.
- El menú de creación separa ventas (`boleta`, `factura`, `pedido`, `presupuesto`), compras (`guía recibida`, `factura recibida`) y almacén (`modelo`).
- Una venta tiene cliente, vendedor, tarifa, motivo, condición de pago, líneas de artículos, impuestos, descuentos, cobros y documentos asociados.
- El catálogo necesita producto/modelo, referencia, precio, impuesto, unidad, atributos y existencias.
- Los estados visibles se modelan como borrador, emitido, pago parcial, pagado, entregado y cancelado.
- El módulo de caja requiere medios de pago, movimientos y cierres.
- Para informes y un futuro plan de cuentas se incluyen cuentas contables, asientos y líneas contables.
- CRM se separa en plantillas, campañas y mensajes.

## Límites de privacidad

No incluye clientes, RUT, teléfonos, correos, credenciales, documentos ni importes reales. `customer_clinical_data` existe solo como estructura opcional y debe permanecer desactivada hasta definir permisos y protección de datos.

## Flujo principal

`products` → `document_lines` → `documents` → `payments`.

Los documentos relacionados se conectan con `document_relations` para permitir presupuesto → pedido → guía → factura/boleta. Las salidas y entradas de inventario se registran en `stock_movements`, y la contabilidad queda vinculada mediante `journal_entries` y `journal_lines`.

## Instalación futura en cPanel

1. Crear una base MySQL vacía y un usuario dedicado desde **MySQL Databases**.
2. Asignar el usuario a la base con privilegios completos.
3. Ejecutar `schema.sql` desde phpMyAdmin o desde la terminal con el usuario de la base.
4. Configurar las credenciales solo en variables de entorno del backend; nunca en `app.js` ni en el repositorio.

La interfaz actual seguirá usando mocks hasta conectar una API. Esta migración no modifica cPanel por sí sola.

## Reportes y sugerencias

Tabla `feedback_reports`, creada por `database/migrations/2026-10-15-reportes-sugerencias.sql`. Guarda fecha y hora (`created_at`), usuario, tipo (`report` o `suggestion`), texto, estado (`new`, `in_review`, `resolved`) y metadatos del adjunto.

Los adjuntos (JPG, PNG, WebP o PDF, máximo 5 MB) se guardan en `../oftaplus-storage/reports` junto a la carpeta del proyecto, fuera de la raíz pública. Para cambiar la ruta, define `OFTAPLUS_UPLOAD_DIR`. El nombre del archivo es aleatorio y el tipo se detecta por contenido, no por el nombre enviado. Los cambios de estado quedan en `audit_logs`.

## Boletas importadas

Tabla `sales_receipts`, creada por `database/migrations/2026-10-16-boletas-importadas.sql`. Guarda el listado de boletas exportado desde Gesvision: serie y número (`2026` + `2351`), sucursal, cliente, estado, total pagado, pendiente, descuento, total, fecha de creación y trabajador. Es un registro histórico de consulta: no crea documentos, clientes ni movimientos de stock.

Importación desde el Excel (`.xlsx`, primera hoja):

```bash
php tools/importar_boletas.php ruta/Listado_de_boletas.xlsx --company-id=1 --dry-run   # solo valida
php tools/importar_boletas.php ruta/Listado_de_boletas.xlsx --company-id=1            # guarda
```

- Una boleta se identifica por empresa, serie y número. Volver a importar el mismo listado actualiza los estados e importes existentes y no duplica filas.
- Las filas sin serie (la fila de totales del Excel) se omiten.
- Se rechaza una fila si pagado + pendiente no cuadra con el total, o si falta fecha o serie válida. Los errores muestran solo la serie, no el nombre del cliente.
- Supuesto: la columna `Total -> Dto.` se guarda como `discount_total`. El encabezado está cortado en el Excel; confirmar con Gesvision.
- Los archivos Excel están en `.gitignore` (`*.xlsx`). No subas el listado ni la base con datos reales al repositorio.

Pruebas sin base de datos: `php tests/receipts_test.php`.

## Cobros importados

Tabla `imported_payments`, creada por `database/migrations/2026-10-16-cobros-importados.sql`. Guarda el listado de cobros emitidos exportado desde Gesvision: serie y número del cobro, factura, cliente, tipo de pago, importe, fecha de pago y fecha de creación. Es un registro histórico: no crea pagos, documentos, clientes ni movimientos de caja.

Importación desde el Excel (`.xlsx`, primera hoja):

```bash
php tools/importar_cobros.php ruta/Listado_de_cobros.xlsx --company-id=1 --dry-run   # solo valida
php tools/importar_cobros.php ruta/Listado_de_cobros.xlsx --company-id=1            # guarda
```

- Un cobro se identifica por empresa y serie/número (`Serie/Nº`). Volver a importar el mismo listado actualiza los importes existentes y no duplica filas.
- Las filas sin serie (la fila de totales del Excel) se omiten. Los importes negativos (devoluciones) se aceptan.
- En el módulo de Cobros aparecen junto a los cobros registrados, marcados como "Histórico", sin detalle ni acciones. Se filtran por fecha, cliente y tipo de pago (búsqueda), pero no por la lista de formas de pago de la empresa, porque el tipo viene de Gesvision como texto.
- Pruebas sin base de datos: `php tests/imported_payments_test.php`.

## Vistos buenos

Tabla `document_approvals`, creada por `database/migrations/2026-10-16-vistos-buenos.sql`. Cada solicitud queda ligada a un borrador de venta, con estado `pending`, `approved` o `rejected`, motivo, quién la pidió y quién la resolvió con su nota y fecha. No modifica el documento, los cobros, el stock ni los clientes.

- `POST /documents/{id}/approval-request`: crea una solicitud pendiente. Solo para borradores y solo si no hay otra pendiente.
- `GET /documents/{id}/approvals`: lista las solicitudes del documento, de la más reciente a la más antigua.
- `POST /document-approvals/{id}/decision` con `decision` = `approve` o `reject`: solo `owner` y `admin`. Una solicitud resuelta no se puede volver a resolver.
- En el formulario de venta, el botón "Vistos buenos" muestra las solicitudes y permite solicitar una nueva.
