# Recon: Gesvision (gesmo) → Oftaplus

Fuente: `reference-assets/module-map.md` (recorrido de la cuenta propia) y el estado actual del código. Estado de acceso (2026-10-08): `https://app.gesvision.com/gesmo/login` responde HTTP 200 desde esta sesión. Las rutas protegidas (p. ej. `/gesmo/ventas/tickets`) responden 302 a `/gesmo/login`, así que el recorrido autenticado requiere credenciales. No hay variables de entorno de Gesvision configuradas en esta sesión; el recorrido queda pendiente de ellas.

## Alcance

- App: Gesvision Gesmo, web. Software de gestión para ópticas.
- Porción: el ciclo de venta de la óptica (cliente → boleta → cobro → cierre de caja), luego documentos relacionados, compras, CRM y análisis.
- Para quién: la propia óptica de Eri.

## Estado del clon

Oftaplus ya tiene el shell y casi todas las pantallas del mapa como maquetas. Solo dos módulos hablan con la base: Nueva boleta (guarda borrador) y Clientes (listado). La tabla de funcionalidades está en `features.csv`.

## Flujo principal (a hacer funcional primero)

```
F01 Venta en mostrador
    Clientes (buscar o crear) -> Nueva boleta (líneas, descuento) -> Emitir -> Cobrar -> aparece en Boletas y en Resumen diario
F02 Cierre de caja
    Cierres de caja -> Nuevo cierre (cobros del día por medio de pago) -> Confirmar
```

## Lo que falta para un clon funcional

1. Login y sesión (hoy la API no tiene autenticación).
2. Emitir boleta con folio, cobro y movimiento de stock.
3. CRUD de clientes y productos.
4. Listados de boletas, cobros y cierres leyendo de la base.

## Fuera de alcance

- Recargas SMS de Gesvision: es su red de proveedor; usar un proveedor propio.
- Datos reales de Gesvision.

## Tamaño

M: unas pocas semanas para que el ciclo de venta y caja sea real; el resto de módulos se suma por partes.
