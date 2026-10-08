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

## Formulario Crear Venta (boleta), solo lectura

Se observó un formulario de venta abierto por el usuario en el navegador integrado. No se editó ni se guardó nada desde esta sesión.

- Encabezado: número de documento (formato 2026/00XXXX), fecha y hora de creación, indicador "Puede editar", selector de Cliente (con búsqueda).
- Campos de cabecera: Agente, Tarifa dtos., Motivo vis., Cond. pago (todos desplegables).
- Detalle de artículos: columnas #, Concepto (con búsqueda por texto), Referencia, C, Base, PVP, Unidad, Bruto, %Dto, Neto. Botón "Crear OT" a la derecha.
- Sección Cobros: columnas Fecha, F. Pago, Importe; acción "Añadir nuevo cobro"; indicador "Pdt. pago" calculado.
- Pie de totales: Base, Cuota IVA y Total.
- Barra inferior: Cobros, Doc. Asociados, Stock, Vistas buenos, Observaciones, Óptica, y los botones de acción "Cerrar" y "Cobrar".

No se han revisado reglas de validación ni campos obligatorios: requieren interactuar con el formulario, lo que no se hizo en solo lectura.

## Pendiente

- Revisar formularios de creación de cobro y cierre de caja, sin ejecutar acciones de guardado.
- Revisar reglas de validación y campos obligatorios en modo lectura (no es posible sin interactuar con el formulario).
- La localización de oftaplus por SSH en el servidor cPanel no se hizo: no está en las instrucciones del usuario y requiere autorización explícita.
