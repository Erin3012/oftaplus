# Importación del catálogo desde Gesvision

Fuentes revisadas (solo estructura, sin copiarlas al repo):

| Archivo | Hoja | Filas | Columnas |
|---|---|---|---|
| Listado de modelos | Sheet1 | ~1.940 | Nombre, Marca, Categoría, Fecha de creación, Fecha modificación, PVP |
| Listado de stock | Report | ~1.270 | Cod.Barras, Descripción, Modelo, Categoría, Marca, Cantidad, Stock mínimo, Última entrada, Precio Ult. Compra, PVP ficha |
| Listado de marcas | Report | ~96 | Nombre, Descripción |

## Mapeo a oftaplus

| Campo Gesvision | Destino oftaplus | Notas |
|---|---|---|
| Cod.Barras (stock) | `products.sku` | Único por empresa. Si no hay código de barras en el modelo, usar el nombre normalizado |
| Nombre / Descripción (stock) | `products.name` / `products.description` | Gesvision repite el modelo en "Modelo" y el color en "Descripción" |
| Categoría | `products.attributes_json.category` | El archivo trae la columna escrita "Ctegoría" (typo en el origen) |
| Marca | `products.attributes_json.brand` | Lista de marcas en el archivo de marcas |
| Precio Ult. Compra | `products.cost_amount` | Costo de compra |
| PVP ficha | `product_prices.amount` (lista `general`) | Precio de venta, con IVA incluido |
| Fecha de creación / modificación | Se ignoran | Son seriales de Excel; no se importan |
| Cantidad, Última entrada | Movimiento de stock inicial | Ver pendiente |
| Stock mínimo | No hay campo | Ver pendiente |

## Endpoints disponibles

- `POST /products` crea un producto con `sku`, `name`, `description`, `taxRate`, `costAmount`, `priceAmount` y `active`. Devuelve 409 si el código ya existe.
- `PUT /products/{id}` actualiza estos campos (no toca el precio).

Falta un endpoint de importación masiva. Hacerlo fila por fila con `POST /products` es viable para ~1.900 filas.

## Pendiente

1. Importar el stock inicial: requiere elegir ubicación (`stock_locations`) y generar un movimiento `in` por producto. No está hecho.
2. Stock mínimo: la tabla `products` no tiene campo. Decidir si se agrega.
3. Marcas: no hay tabla de marcas; hoy se guardan como atributo.
4. Antes de importar a la base de producción, probar el PHP y hacer copia de respaldo.
