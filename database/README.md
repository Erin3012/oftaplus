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
