# Gesvision: Gastos, Almacén y CRM (solo lectura)

Fecha: 2026-10-08. Solo estructura: columnas, filtros y estados. Sin datos de clientes ni de proveedores.

## Gastos

| Pantalla | Ruta Gesvision | Columnas Gesvision | Filtros | oftaplus |
|---|---|---|---|---|
| Guías recibidas | /gesmo/compras/albaranes | Código, Ref.Externa, Proveedor, Fecha, Neto, Total IVA, Total, Cantidad, Nombre trabajador | Fecha; Estado: FACTURADO, PDT. FACTURAR, DEVUELTO, DEVOLUCIÓN | Parcial: lista visual con Número, Proveedor, Estado, Fecha |
| Facturas recibidas | /gesmo/compras/facturas-recibidas | Código, Ref. Interna, Ref.Externa, Proveedor, Estado, Neto, $ IVA, Total, Fecha | Fecha; pago (Pagado, Pago Parcial, Pdt. pago); Estado: RECIBIDO, PENDIENTE, ANULADO, ANULACION; pestañas Listado general e Importaciones Excel | Falta la pantalla |
| Pagos | /gesmo/compras/pagos | Código, Local, Tercero, Factura S/Nº, Forma de pago, Importe, Fecha | Fecha | Parcial: lista visual con Código, Proveedor, Medio de pago, Estado, Monto |
| Proveedores | /gesmo/compras/proveedores | Código, Nombre, RUT, Teléfono móvil, Email, Habilitado | — | Falta la pantalla y la tabla (no hay tabla de proveedores; `documents.supplier_name` es texto) |

## Almacén

| Pantalla | Ruta Gesvision | Columnas Gesvision | Filtros | oftaplus |
|---|---|---|---|---|
| Modelos | /gesmo/almacen/modelos | Nombre, Marca, Categoría, Fecha de creación, Fecha modificación, PVP | Pestañas General, Pendientes de revisar, Importar de proveedores, Configurar importador; Descatalogados, Borrador, Con foto | Falta la pantalla; la API tiene `GET /products` |
| Stock | /gesmo/almacen/stock | Cod.barras, Descripción, Modelo, Categoría, Marca, Cantidad, Stock mínimo, Última entrada, Último precio de compra, PVP Ficha | Descatalogados, Disponible, Bajo mínimo, A cero, Incidencia. Resumen: Total unidades, Valoración precio últ. compra, Valoración PVP ficha | Falta; existe la tabla `stock_movements` |
| Traspasos | /gesmo/almacen/traspasos | Código, Origen, Destino, Tipo, Estado, Fecha | Fecha; Solicitado, Enviado, Recibidos, Directo | Falta |
| Inventarios | /gesmo/almacen/inventarios | Código, Observaciones, Local, Cantidad, Fecha, Cuadrado, Stock Cuadrado | Fecha | Falta |

## CRM

| Pantalla | Ruta Gesvision | Estructura Gesvision | oftaplus |
|---|---|---|---|
| Dashboard | /gesmo/CRM/campanias | Indicadores Campañas activas y Alcance esta semana; pestañas Periódicas y Manuales; columnas Nombre, Audiencia estimada, Periodicidad, Programado, Últ. Envío, Activa | Parcial: vista de campañas visual |
| Plantillas SMS | /gesmo/CRM/configuracion/plantillasSMS | Columnas Nombre, Remitente, Texto | Parcial: vista de plantillas visual |
