# Pendientes que necesitan autorización de Eri

Cosas que el clasificador de permisos bloqueó o que requieren una decisión. No se reintentaron.

1. **Emitir boleta con folio, anular documento y listar boletas (API).** La edición de `api/index.php` que agregaba `POST /documents/{id}/issue`, `POST /documents/{id}/cancel` y `GET /documents` fue bloqueada. Hace falta autorizar esa edición para que el ciclo de venta quede completo.
2. **Abrir formularios en Gesvision.** El clic en "Crear boleta" fue bloqueado. Los formularios de cobro y cierre de caja no se revisaron; tampoco las reglas de validación que solo aparecen al interactuar.
3. **`replica/features.csv` en la rama `claude/ciclo-venta-estructura`.** Su escritura fue bloqueada varias veces.
4. **Comparación de Cobros y Cierres en `replica/recon.md`.** Está en un stash local (`git stash list`, "recon comparacion cobros y cierres") de la rama `claude/ciclo-venta-estructura`; su commit fue bloqueado.
5. **Fusionar `claude/cobros-cierres-api` a `main` y desplegar.** No se hizo: requiere tu revisión. Antes de desplegar, probar el PHP en un entorno con PHP (aquí no hay intérprete).
6. **Proveedores como tabla propia.** Hoy `documents.supplier_name` es texto. Crear una tabla `suppliers` cambia el esquema de la base de producción; necesita tu visto bueno.
