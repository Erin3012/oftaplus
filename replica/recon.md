# Gesvision: estructura del ciclo de venta (solo lectura)

Fecha: 2026-10-08. Solo lectura. No se creó, editó ni cobró nada. No incluye datos personales ni montos por cliente.

## Pantallas

| Paso | Pantalla | Ruta | Columnas | Filtros y acciones |
|---|---|---|---|---|
| Cliente | Clientes | /gesmo/ventas/clientes | Código, Nombre, RUT, Teléfono móvil, Email, Domicilio, Habilitado | Botón Plantillas; menú "Mas opciones" por fila; paginado 12/20/50/100/500 |
| Boleta | Boletas | /gesmo/ventas/tickets | Código, Local, Cliente, Estado, Pagado, Pdt. pago, $ Dto., Total, Fecha de creación, Nombre trabajador | Filtro de fecha; filtro Estado; Plantillas; "Mas opciones" por fila |
| Cobro | Cobros | /gesmo/ventas/cobros | Código, Tercero, Factura S/Nº, Forma de pago, Importe, Fecha, F. creación | Filtro de fecha; Plantillas; "Mas opciones" por fila |
| Cierre | Cierres de caja | /gesmo/ventas/cajas | Local, Fecha, Imp. calculado, Importe recuento, Estado | Filtro de fecha; filtro Abiertas - Cerradas; Plantillas; "Mas opciones" por fila |

## Estados

- Filtro de estado de boleta: ENTREGADO, PENDIENTE, ANULADO, ANULACION, DEVUELTO, DEVOLUCIÓN, SUSTITUIDA, RECLAMO.
- Filtro de pago: Pagado, Pago Parcial, Pdt. pago.

## Flujo observado

Cliente (ficha) → Boleta (documento de venta, con pago parcial o total) → Cobro (pago asociado a la factura S/Nº de la boleta, con forma de pago) → Cierre de caja (por local y fecha, con importe calculado y recuento).

## Creación desde Nuevo documento (solo ruta, no usada)

Crear boleta, Crear factura completa, Crear pedido cliente, Crear presupuesto (Ventas); Crear guía recibida, Crear factura recibida (Compras); Crear modelo (Almacen).

## Pendiente

- Revisar formularios de creación y las acciones de "Mas opciones" (solo estructura, sin ejecutar nada).
- La localización de oftaplus por SSH en el servidor cPanel no se hizo: no está en las instrucciones del usuario y requiere autorización explícita.
