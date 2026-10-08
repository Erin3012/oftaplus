# Oftaplus — Proyecto Local Claude Code

**Repo:** https://github.com/Erin3012/oftaplus  
**Descripción:** Aplicación web PHP/JavaScript que emula Gesvision (ERP chileno) con módulos de Ventas, Cobros, Cierres, Catálogo e Inventarios.

---

## Arquitectura

### Frontend
- **Archivo principal:** `app.js` (36KB, lógica monolítica de UI)
- **Estilos:** `styles.css` (28KB)
- **Entrada HTML:** `index.html`
- **Carga:** `app-loader.php` (PHP loader para la aplicación)

### Backend
- **Carpeta:** `api/` (endpoints PHP)
- **Entrada:** `index.php` (router)
- **Base de datos:** MySQL/MariaDB (ver `database/schema.sql`)

### Datos
- **Database README:** `database/README.md` (modelo de datos, flujos, instalación cPanel)
- **Módulos de referencia:** `reference-assets/module-map.md`

---

## Ramas Activas

| Rama | Estado | Propósito |
|------|--------|----------|
| `main` | Producción | Ventas, Cobros, Cierres, Catálogo, Inventarios (backend completo) |
| `claude/cobros-cierres-api` | WIP | Desarrollo: API de Cobros y Cierres, inventarios |
| `claude/ciclo-venta-estructura` | Obsoleta | Estructura de ciclo de venta |
| `claude/project-thread-*` | Threads | Trabajo anterior en threads de Claude Code |

**Rama default:** `main`  
**Rama activa de trabajo:** `claude/cobros-cierres-api` (checkout local: `git checkout -b claude/cobros-cierres-api origin/claude/cobros-cierres-api`)

---

## Trabajo Pendiente (Roadmap)

### Documentación (por completar)
- [ ] Formularios de Facturas
- [ ] Formularios de Presupuestos
- [ ] Formularios de Guías
- [ ] Formularios de Gastos (Gesvision)

### Desarrollo
- [ ] Conectar inventarios con stock_movements
- [ ] Completar APIs de Cobros y Cierres
- [ ] Mapas de documentos relacionados (presupuesto → pedido → guía → factura)
- [ ] Cierre de caja y reportes

---

## Configuración Local

### Base de Datos
Ver `database/README.md` para:
- Instalación MySQL en cPanel (futuro)
- Estructura de `schema.sql`
- Privacidad de datos (no incluye RUTs, clientes reales, credenciales)

**Variables de entorno necesarias (backend):**
```bash
DB_HOST=localhost
DB_USER=oftaplus_user
DB_PASS=<password>
DB_NAME=oftaplus
```

### Desarrollo PHP
- **Servidor local:** `php -S localhost:8000 index.php`
- **Frontend:** Servido por PHP loader (app-loader.php)
- **Módulos:** Accedidos vía menú de creación en UI

---

## Reglas de Desarrollo

1. **Frontend:** Mantener lógica en `app.js`, estilos en `styles.css`
2. **Backend:** Endpoints en `api/`, routeados por `index.php`
3. **Database:** Cambios en `schema.sql`, documentados en `database/README.md`
4. **Commits:** Descriptivos en español o inglés, incluir módulo afectado
5. **Privacidad:** Nunca comitear datos reales, credenciales o información sensible

---

## Contactos y Contexto

- **Proyecto:** Emula flujos de Gesvision sin copiar datos internos
- **Usuarios:** PYMES chilenas, necesitan control de Ventas, Cobros, Cierres
- **Producción:** main (vendedor, tarifa, líneas de artículos, impuestos, descuentos, cobros)
- **Status:** Prototipo avanzado, listo para deploy en cPanel con MySQL

---

## Próximas Sesiones

Al retomar:
1. Checkout: `git checkout claude/cobros-cierres-api` (rama activa)
2. Verificar: `git fetch origin` (traer cambios remotos)
3. Desarrollar: Nuevas funciones o documentación
4. Commit & Push: A la rama de trabajo

**Nota:** Los threads anteriores (`claude/project-thread-*`) son históricos. Continuar solo con `claude/cobros-cierres-api` y `main`.
