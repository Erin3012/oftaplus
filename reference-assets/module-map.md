# Mapa visual de Gesvision

Recorrido realizado sobre la sesión autenticada. Este mapa contiene únicamente rutas y controles de interfaz; no incluye registros, clientes, importes ni datos operativos.

## Estructura global

- Barra superior: logo, sucursal, nuevo documento, configuración, usuario, control horario y calendario.
- Menú lateral: General, Ingresos, Gastos, CRM y Análisis.
- Módulos de gestión: encabezado con breadcrumb, ayuda, acción principal, filtros, buscador, tabla paginada y exportación.
- Módulos de informes: filtros, generación de PDF/Excel, descarga y navegación atrás.
- Análisis: biblioteca de informes con búsqueda, favoritos, vista de cuadrícula/lista y acciones abrir, duplicar y eliminar.

## Rutas observadas

### Ingresos

- Boletas — `/gesmo/ventas/tickets`: Nueva boleta, filtros por estado, período, buscador, tabla y exportación.
- Guías emitidas — `/gesmo/ventas/albaranes`: Nueva guía, facturar, abono/devolución, filtros y tabla.
- Facturas emitidas — `/gesmo/ventas/facturas-emitidas`: Nueva factura, nota de crédito, rectificación, filtros y tabla.
- Cobros — `/gesmo/ventas/cobros`: Nuevo cobro, filtros y tabla.
- Vales de descuento — `/gesmo/ventas/vales-descuento`: Nuevo vale, filtros y tabla.
- Pedidos de clientes — `/gesmo/ventas/pedidos`: Carga Excel, nuevo pedido, facturar, entregar e informes a medida.
- Presupuestos y proformas — `/gesmo/ventas/presupuestos`: Factura proforma, nuevo presupuesto, facturar y generar pedido.
- Clientes — `/gesmo/ventas/clientes`: Nuevo cliente, edición y acciones por registro.
- Resumen diario — `/gesmo/ventas/resumen-diario`: Informe de comisiones, generar, descargar y volver.
- Cierres de caja — `/gesmo/ventas/cajas`: Nuevo cierre de caja, edición y acciones por registro.
- Informes — `/gesmo/ventas/informes`: Generar PDF, generar Excel, descargar y volver.

### Gastos

- Guías recibidas — `/gesmo/compras/albaranes`: Carga Excel, recepción rápida, nueva guía, crear factura completa y abono/devolución.
- Pagos — `/gesmo/compras/pagos`: Nuevo pago, filtros, generación y descarga.

### CRM

- Envío — `/gesmo/CRM/envio`: Flujo por pasos con siguiente, generación, descarga y volver.
- Dashboard — `/gesmo/CRM/campanias`: Vistas periódicas y manuales, generación y descarga.
- Histórico SMS — `/gesmo/CRM/sms`: Consulta histórica, generación, descarga y volver.
- Plantillas SMS — `/gesmo/CRM/configuracion/plantillasSMS`: Nueva plantilla, edición y acciones por registro.
- Recargas — `/gesmo/CRM/configuracion/recargasSMS`: Flujo de recarga con generación, descarga y navegación.
- Ajustes — `/gesmo/CRM/configuracion/ajustes`: Edición de parámetros y remitente.

### Análisis

- Biblioteca — `/gesmo/intelligence/biblioteca`: Nuevo informe, favoritos, vistas cuadrícula/lista, abrir, duplicar y eliminar.

## Recursos descargados

- `boletas/`: paquete de estilos, imágenes, fuentes e inventario de recursos observado en módulos de gestión.
- `analisis/`: paquete equivalente observado en la biblioteca de análisis.
- Cada paquete contiene `manifest.json` y 39 recursos descargados. Algunos archivos mantienen la extensión original `.xhtml` del servidor aunque su contenido sea CSS.
