(() => {
  const content = document.querySelector('#app-content');
  if (!content) return;
  const state = { route: '', rows: [], locations: [], products: [], suppliers: [], filter: '', query: '', page: 1, pageSize: 20, sortColumn: -1, sortDirection: 1 };
  const configs = {
    'facturas-recibidas': { title: 'Facturas recibidas', group: 'Gastos', action: 'Nueva factura', columns: ['Código', 'Ref. externa', 'Proveedor', 'Estado', 'Neto', '$ IVA', 'Total', 'Fecha'] },
    traspasos: { title: 'Traspasos', group: 'Almacen', action: 'Nuevo traspaso', columns: ['Código', 'Origen', 'Destino', 'Tipo', 'Estado', 'Fecha', 'Cantidad'] },
    inventarios: { title: 'Inventarios', group: 'Almacen', action: 'Nuevo inventario', columns: ['Código', 'Observaciones', 'Local', 'Cantidad', 'Fecha', 'Cuadrado', 'Estado'] },
    'cierres-caja': { title: 'Cierres de caja', group: 'Ingresos', action: 'Cerrar caja', columns: ['Local', 'Fecha', 'Imp. calculado', 'Importe recuento', 'Diferencia', 'Estado'] }
  };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const money = value => '$ ' + Math.round(Number(value || 0)).toLocaleString('es-CL');
  const date = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleDateString('es-CL') : '—';
  async function api(path, options = {}) {
    const response = await fetch('./api' + path, { headers: { 'Content-Type': 'application/json', ...(options.headers || {}) }, ...options });
    const result = await response.json();
    if (!response.ok || result.ok === false) throw new Error(result.error || 'No se pudo completar la operación.');
    return result;
  }
  function notifyOps(message) {
    if (typeof window.notify === 'function') window.notify(message);
    else { const toast = document.querySelector('#toast'); if (toast) { toast.textContent = message; toast.classList.add('is-visible'); setTimeout(() => toast.classList.remove('is-visible'), 3000); } }
  }
  function modal(id, title, body) {
    let layer = document.querySelector(`[data-ops-layer="${id}"]`);
    if (!layer) { document.body.insertAdjacentHTML('beforeend', `<div class="modal-backdrop ops-backdrop" data-ops-layer="${id}" hidden><section class="modal-card ops-modal" role="dialog" aria-modal="true" aria-labelledby="${id}-title"><div class="modal-head"><h2 id="${id}-title">${title}</h2><button type="button" class="icon-button" data-ops-close aria-label="Cerrar">×</button></div><div class="ops-modal-content">${body}</div></section></div>`); layer = document.querySelector(`[data-ops-layer="${id}"]`); }
    layer.querySelector('.modal-head h2').textContent = title;
    layer.querySelector('.ops-modal-content').innerHTML = body;
    layer.hidden = false; layer.querySelector('input,select,button')?.focus();
    const form = layer.querySelector('form[data-ops-form]'); if (form) updateLines(form);
    return layer;
  }
  function frame(cfg) {
    return `<section class="ops-page" data-ops-route="${state.route}"><div class="ops-heading"><div class="ops-breadcrumb"><span>${cfg.group}</span><b>›</b><strong>${cfg.title}</strong></div><div class="ops-heading-actions">${state.route === 'traspasos' ? '<button type="button" class="secondary-button" data-ops-create="location">＋ Nueva ubicación</button>' : ''}<button type="button" class="primary-button" data-ops-create="${state.route}">＋ ${cfg.action}</button></div></div><section class="panel ops-list-panel"><div class="ops-period"><label>Desde<input type="date" data-ops-date="from"></label><label>Hasta<input type="date" data-ops-date="to"></label><button class="secondary-button" type="button" data-ops-refresh>Actualizar</button></div><div class="ops-toolbar"><label class="ops-search"><span>⌕</span><input type="search" placeholder="Buscar en el listado" aria-label="Buscar" data-ops-search></label><div class="ops-filters" data-ops-filters></div><button class="icon-button" type="button" data-ops-export aria-label="Exportar listado" title="Exportar CSV">⇩</button></div><div class="ops-table-scroll"><table class="ops-table"><thead><tr><th class="ops-select"><input type="checkbox" aria-label="Seleccionar todo" data-ops-select-all></th>${cfg.columns.map((c, i) => `<th data-ops-sort="${i}">${c}<span>↕</span></th>`).join('')}<th class="ops-actions-head">Acciones</th></tr></thead><tbody data-ops-rows><tr><td colspan="${cfg.columns.length + 2}" class="ops-empty">Cargando datos…</td></tr></tbody></table></div><footer class="ops-table-foot"><span data-ops-count>0 registros</span><label>Filas por página <select data-ops-page-size><option>20</option><option>50</option><option>100</option></select></label><div class="ops-pagination"><button type="button" data-ops-prev aria-label="Página anterior">‹</button><span data-ops-page>1</span><button type="button" data-ops-next aria-label="Página siguiente">›</button></div></footer></section></section>`;
  }
  function rowCells(item) {
    if (state.route === 'facturas-recibidas') return [item.number ? String(item.number).padStart(6, '0') : `FAR-${item.id}`, item.external_ref || '—', item.supplier_name, 'Recibida', money(item.subtotal), money(item.tax_total), money(item.total), date(item.issue_date)];
    if (state.route === 'traspasos') return [`TRA-${String(item.id).padStart(5, '0')}`, item.origin, item.destination, item.transfer_type === 'request' ? 'Solicitud' : 'Directo', ({ requested: 'Solicitado', sent: 'Enviado', received: 'Recibido' })[item.status] || item.status, date(item.created_at), Number(item.quantity || 0).toLocaleString('es-CL')];
    if (state.route === 'inventarios') return [`INV-${String(item.id).padStart(5, '0')}`, item.notes || '—', item.location, Number(item.counted_quantity || 0).toLocaleString('es-CL'), date(item.created_at), Number(item.line_count) > 0 && Number(item.matched_lines) === Number(item.line_count) ? 'Sí' : 'No', item.status === 'closed' ? 'Cuadrado' : 'Abierto'];
    const diff = Number(item.closing_amount || 0) - Number(item.calculated_amount || 0);
    return [item.branch || item.cash_register, date(item.closed_at || item.opened_at), money(item.calculated_amount), item.closing_amount == null ? '—' : money(item.closing_amount), item.closing_amount == null ? '—' : money(diff), item.status === 'closed' ? 'Cerrada' : 'Abierta'];
  }
  function actions(item) {
    if (state.route === 'traspasos') return `${item.status === 'requested' ? `<button class="ops-row-action" data-ops-transfer="${item.id}" data-transition="send">Enviar</button>` : ''}${item.status === 'sent' ? `<button class="ops-row-action" data-ops-transfer="${item.id}" data-transition="receive">Recibir</button>` : ''}<button class="ops-row-action" data-ops-view-transfer="${item.id}">Ver</button>`;
    if (state.route === 'inventarios') return `<button class="ops-row-action" data-ops-view-inventory="${item.id}">Ver detalle</button>${item.status === 'open' ? `<button class="ops-row-action" data-ops-close-inventory="${item.id}">Cuadrar</button>` : ''}`;
    return '<span class="ops-no-action">—</span>';
  }
  function renderRows() {
    const tbody = content.querySelector('[data-ops-rows]'); if (!tbody) return;
    const cfg = configs[state.route];
    const q = state.query.toLocaleLowerCase('es-CL');
    const rows = state.rows.filter(item => {
      const cells = rowCells(item).join(' ').toLocaleLowerCase('es-CL');
      const statusOk = !state.filter || cells.includes(state.filter.toLocaleLowerCase('es-CL'));
      const dateValue = item.issue_date || item.created_at || item.closed_at || item.opened_at || '';
      const dateOnly = String(dateValue).slice(0, 10);
      const from = content.querySelector('[data-ops-date="from"]')?.value || '';
      const to = content.querySelector('[data-ops-date="to"]')?.value || '';
      return statusOk && (!q || cells.includes(q)) && (!from || dateOnly >= from) && (!to || !dateOnly || dateOnly <= to);
    });
    if (state.sortColumn >= 0) rows.sort((a, b) => String(rowCells(a)[state.sortColumn]).localeCompare(String(rowCells(b)[state.sortColumn]), 'es-CL', { numeric: true, sensitivity: 'base' }) * state.sortDirection);
    const pages = Math.max(1, Math.ceil(rows.length / state.pageSize)); state.page = Math.min(state.page, pages);
    const pageRows = rows.slice((state.page - 1) * state.pageSize, state.page * state.pageSize);
    tbody.innerHTML = pageRows.length ? pageRows.map(item => `<tr>${'<td class="ops-select"><input type="checkbox" aria-label="Seleccionar fila"></td>'}${rowCells(item).map((c, i) => `<td>${i === rowCells(item).length - 1 && state.route !== 'traspasos' ? `<span class="ops-status ${String(c).toLowerCase().replace(/[^a-z]+/g, '-')}">${esc(c)}</span>` : esc(c)}</td>`).join('')}<td class="ops-row-actions">${actions(item)}</td></tr>`).join('') : `<tr><td colspan="${cfg.columns.length + 2}" class="ops-empty">No hay ningún resultado</td></tr>`;
    content.querySelector('[data-ops-count]').textContent = `${rows.length ? (state.page - 1) * state.pageSize + 1 : 0}–${Math.min(state.page * state.pageSize, rows.length)} de ${rows.length} registros`;
    content.querySelector('[data-ops-page]').textContent = `${state.page} / ${pages}`;
    content.querySelector('[data-ops-prev]').disabled = state.page <= 1; content.querySelector('[data-ops-next]').disabled = state.page >= pages;
  }
  async function load() {
    const paths = { 'facturas-recibidas': '/purchase-invoices', traspasos: '/transfers', inventarios: '/inventories', 'cierres-caja': '/cash-closures' };
    try { const result = await api(paths[state.route]); state.rows = result.items || []; }
    catch (error) { state.rows = []; notifyOps(error.message); }
    renderRows();
  }
  async function mount() {
    const route = location.hash.slice(1); if (!configs[route]) return;
    if (state.route && state.route !== route) document.querySelectorAll('[data-ops-layer]').forEach(layer => { layer.hidden = true; });
    state.route = route; state.query = ''; state.filter = ''; state.page = 1; state.sortColumn = -1; state.sortDirection = 1;
    content.innerHTML = frame(configs[route]);
    const filters = content.querySelector('[data-ops-filters]');
    filters.innerHTML = ['Todos', ...(route === 'traspasos' ? ['Solicitado', 'Enviado', 'Recibido', 'Directo'] : route === 'cierres-caja' ? ['Abierta', 'Cerrada'] : route === 'inventarios' ? ['Abierto', 'Cuadrado'] : ['Recibida'])].map((f, i) => `<button type="button" class="ops-filter ${i === 0 ? 'is-active' : ''}" data-ops-filter="${i === 0 ? '' : esc(f)}">${esc(f)}</button>`).join('');
    const today = new Date(); const since = new Date(today); since.setMonth(since.getMonth() - 6);
    content.querySelector('[data-ops-date="to"]').value = today.toISOString().slice(0, 10);
    content.querySelector('[data-ops-date="from"]').value = since.toISOString().slice(0, 10);
    await load();
  }
  function field(label, name, type = 'text', attrs = '') { return `<label class="ops-field">${label}<input name="${name}" type="${type}" ${attrs}></label>`; }
  function productLine({ mode = 'purchase' } = {}) {
    const counted = mode === 'inventory';
    return `<tr data-ops-line data-line-mode="${mode}"><td><button class="ops-remove-line" type="button" aria-label="Eliminar artículo">×</button></td><td class="ops-line-no">1</td><td class="ops-product-cell"><input type="search" placeholder="Buscar por código o descripción" autocomplete="off" data-ops-product-search><input type="hidden" name="productId"><div class="ops-product-results" hidden></div></td><td class="ops-selected-name">—</td><td>${counted || mode === 'transfer' ? '<span class="ops-expected">—</span>' : '<input name="unitCost" type="number" min="0" step="1" value="0">'}</td><td><input name="quantity" type="number" min="0" step="1" value="${counted ? 0 : 1}" ${counted ? 'data-counted-quantity' : ''}></td><td class="ops-line-total">—</td></tr>`;
  }
  function lineTable(mode = 'purchase') {
    const counted = mode === 'inventory';
    const expectedLabel = counted ? 'Stock sistema' : mode === 'transfer' ? 'Stock origen' : 'Precio compra';
    const quantityLabel = counted ? 'Unidades contadas' : 'Unidades';
    const totalLabel = counted ? 'Diferencia' : mode === 'transfer' ? 'Movimiento' : 'Importe neto';
    return `<div class="ops-lines-scroll"><table class="ops-lines"><thead><tr><th></th><th>#</th><th>Concepto / producto</th><th>Referencia</th><th>${expectedLabel}</th><th>${quantityLabel}</th><th>${totalLabel}</th></tr></thead><tbody>${productLine({ mode })}</tbody></table></div><button type="button" class="ops-add-line" data-ops-add-line>＋ Añadir artículo</button>`;
  }
  async function openCreate(route) {
    if (route === 'location') return openLocationModal();
    if (route === 'cierres-caja') return openCashModal();
    const locations = await api('/locations'); state.locations = locations.items || [];
    if (route === 'facturas-recibidas') {
      const suppliers = await api('/suppliers'); state.suppliers = suppliers.items || [];
      modal('purchase', 'Nueva factura recibida', `<form class="ops-form" data-ops-form="purchase"><div class="ops-form-grid">${field('Fecha de factura', 'issueDate', 'date', `value="${new Date().toISOString().slice(0, 10)}" required`)}${field('Referencia externa', 'externalRef', 'text', 'placeholder="N.º de factura del proveedor" required')}<label class="ops-field">Proveedor<select name="supplierId" required><option value="">Seleccionar proveedor</option>${state.suppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('')}</select></label><label class="ops-field">Ubicación de recepción<select name="locationId" required>${state.locations.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select></label>${field('Observaciones', 'notes', 'text', 'placeholder="Observaciones de recepción"')}</div>${state.suppliers.length ? '' : '<div class="ops-inline-note">No hay proveedores activos. <a href="#proveedores" data-ops-close>Registra un proveedor y vuelve a crear la factura.</a></div>'}<div class="ops-section-title"><strong>Detalle de artículos</strong><span>Los productos recibidos aumentarán el stock de la ubicación seleccionada.</span></div>${lineTable('purchase')}<div class="ops-totals"><span>Neto <strong data-ops-net>$ 0</strong></span><span>IVA <strong data-ops-tax>$ 0</strong></span><span>Total <strong data-ops-total>$ 0</strong></span></div><p class="ops-error" data-ops-error hidden></p><div class="ops-form-actions"><button type="button" class="secondary-button" data-ops-close>Cancelar</button><button class="primary-button" type="submit" ${state.suppliers.length ? '' : 'disabled'}>Guardar y recibir</button></div></form>`);
    } else if (route === 'traspasos') {
      modal('transfer', 'Nuevo traspaso', `<form class="ops-form" data-ops-form="transfer"><div class="ops-form-grid"><label class="ops-field">Tipo de traspaso<select name="type"><option value="direct">Directo</option><option value="request">Solicitud</option></select></label><label class="ops-field">Origen<select name="originLocationId" required>${state.locations.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select></label><label class="ops-field">Destino<select name="destinationLocationId" required>${state.locations.filter((_, i) => i > 0).map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select></label>${field('Observaciones', 'notes', 'text', 'placeholder="Motivo del movimiento"')}</div>${state.locations.length < 2 ? '<div class="ops-inline-note">Para crear un traspaso, registra primero una segunda ubicación con «Nueva ubicación».</div>' : ''}<div class="ops-section-title"><strong>Detalle de artículos</strong><span>Al enviar se validará el stock disponible en origen.</span></div>${lineTable('transfer')}<p class="ops-error" data-ops-error hidden></p><div class="ops-form-actions"><button type="button" class="secondary-button" data-ops-close>Cancelar</button><button class="primary-button" type="submit" ${state.locations.length < 2 ? 'disabled' : ''}>Crear traspaso</button></div></form>`);
    } else if (route === 'inventarios') {
      modal('inventory', 'Nuevo inventario', `<form class="ops-form" data-ops-form="inventory"><div class="ops-form-grid"><label class="ops-field">Local<select name="locationId" required>${state.locations.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('')}</select></label>${field('Observaciones', 'notes', 'text', 'placeholder="Ej. Recuento mensual"')}</div><div class="ops-inline-note">Compara el stock del sistema con el conteo físico. Al cuadrar se registran los ajustes.</div><div class="ops-section-title"><strong>Detalle de artículos</strong><span>Agrega cada producto y registra su cantidad contada.</span></div>${lineTable('inventory')}<p class="ops-error" data-ops-error hidden></p><div class="ops-form-actions"><button type="button" class="secondary-button" data-ops-close>Cancelar</button><button class="primary-button" type="submit">Guardar recuento</button></div></form>`);
    }
  }
  async function openLocationModal() {
    const branches = await api('/company');
    modal('location', 'Nueva ubicación de stock', `<form class="ops-form" data-ops-form="location">${field('Nombre de ubicación', 'name', 'text', 'placeholder="Ej. Local centro" required')}<label class="ops-field">Sucursal<select name="branchId"><option value="">Sin sucursal asignada</option>${branches.branch ? `<option value="${branches.branch.id}">${esc(branches.branch.name)}</option>` : ''}</select></label><p class="ops-error" data-ops-error hidden></p><div class="ops-form-actions"><button type="button" class="secondary-button" data-ops-close>Cancelar</button><button class="primary-button" type="submit">Guardar ubicación</button></div></form>`);
  }
  async function openCashModal() {
    const data = await api('/cash-closures/current');
    const calc = Number(data.calculatedAmount || 0);
    const breakdown = (data.breakdown || []).map(b => `${esc(b.method_name)}: ${money(b.total_amount)} (${b.payment_count})`).join(' · ');
    modal('cash', 'Cerrar caja', `<form class="ops-form" data-ops-form="cash"><div class="ops-cash-summary"><span>Local / caja<strong>${esc(data.cashRegister?.name || 'Caja principal')}</strong></span><span>Desde<strong>${esc(date(data.openedAt))}</strong></span><span>Efectivo calculado<strong>${money(calc)}</strong></span></div>${breakdown ? `<div class="ops-inline-note"><strong>Desglose del turno:</strong> ${breakdown}</div>` : ''}<label class="ops-field">Importe en efectivo contado en caja<input name="countedAmount" type="number" min="0" step="1" value="${Math.round(calc)}" required></label><p class="ops-difference" data-ops-difference>La diferencia será $ 0</p><p class="ops-inline-note">Al confirmar, el período quedará cerrado y los cobros posteriores iniciarán el siguiente turno.</p><p class="ops-error" data-ops-error hidden></p><div class="ops-form-actions"><button type="button" class="secondary-button" data-ops-close>Cancelar</button><button class="primary-button" type="submit">Confirmar cierre</button></div></form>`);
    const form = document.querySelector('[data-ops-form="cash"]'); form.dataset.calculated = String(calc);
  }
  function closeModal(layer) { if (layer) layer.hidden = true; }
  function updateLines(form) {
    const lines = [...form.querySelectorAll('[data-ops-line]')]; let net = 0, tax = 0;
    lines.forEach((row, i) => {
      row.querySelector('.ops-line-no').textContent = String(i + 1);
      const qty = Number(row.querySelector('[name="quantity"]').value || 0);
      const cost = Number(row.querySelector('[name="unitCost"]')?.value || 0);
      const total = qty * cost; net += total; tax += total * Number(row.dataset.taxRate || 0) / 100; row.querySelector('.ops-line-total').textContent = form.dataset.opsForm === 'transfer' ? `${qty.toLocaleString('es-CL')} uds.` : money(total);
      if (form.dataset.opsForm === 'inventory') { const expected = Number(row.dataset.expected || 0); const delta = qty - expected; row.querySelector('.ops-line-total').textContent = `${delta > 0 ? '+' : ''}${delta.toLocaleString('es-CL')}`; }
    });
    if (form.dataset.opsForm === 'purchase') { form.querySelector('[data-ops-net]').textContent = money(net); form.querySelector('[data-ops-tax]').textContent = money(tax); form.querySelector('[data-ops-total]').textContent = money(net + tax); }
  }
  async function searchProducts(input) {
    const results = input.closest('td').querySelector('.ops-product-results'); const term = input.value.trim();
    if (!term) { results.hidden = true; return; }
    try { const result = await api('/products?limit=20&q=' + encodeURIComponent(term)); results.innerHTML = result.items.filter(item => item.product_type === 'product').map(item => `<button type="button" data-ops-product="${item.id}" data-name="${esc(item.name)}" data-sku="${esc(item.sku)}" data-cost="${Number(item.cost_amount || 0)}" data-tax="${Number(item.tax_rate || 0)}" data-stock="${Number(item.stock_quantity || 0)}"><strong>${esc(item.sku)} · ${esc(item.name)}</strong><small>Stock ${Number(item.stock_quantity || 0).toLocaleString('es-CL')} · Compra ${money(item.cost_amount)}</small></button>`).join('') || '<span class="ops-no-products">Sin coincidencias</span>'; results.hidden = false; } catch (error) { results.innerHTML = `<span class="ops-no-products">${esc(error.message)}</span>`; results.hidden = false; }
  }
  async function selectProduct(button) {
    const row = button.closest('[data-ops-line]'); const form = button.closest('form');
    row.querySelector('[name="productId"]').value = button.dataset.product;
    row.dataset.productId = button.dataset.product;
    row.dataset.taxRate = button.dataset.tax || '0';
    row.querySelector('[data-ops-product-search]').value = button.dataset.sku;
    row.querySelector('.ops-selected-name').textContent = button.dataset.name;
    row.querySelector('.ops-product-results').hidden = true;
    if (form.dataset.opsForm === 'purchase') row.querySelector('[name="unitCost"]').value = button.dataset.cost;
    if (form.dataset.opsForm === 'inventory') { const loc = form.elements.locationId.value; const stock = await api(`/stock?locationId=${encodeURIComponent(loc)}&filter=`); const item = stock.items.find(p => Number(p.id) === Number(button.dataset.product)); const expected = Number(item?.quantity || 0); row.dataset.expected = String(expected); row.querySelector('.ops-expected').textContent = expected.toLocaleString('es-CL'); }
    if (form.dataset.opsForm === 'transfer') { const origin = form.elements.originLocationId.value; const stock = await api(`/stock?locationId=${encodeURIComponent(origin)}&filter=`); const item = stock.items.find(p => Number(p.id) === Number(button.dataset.product)); row.querySelector('.ops-selected-name').textContent = button.dataset.name; row.querySelector('.ops-expected').textContent = Number(item?.quantity || 0).toLocaleString('es-CL'); }
    updateLines(form);
  }
  async function refreshLocationStock(form, locationId, mode) {
    const stock = await api(`/stock?locationId=${encodeURIComponent(locationId)}&filter=`);
    form.querySelectorAll('[data-ops-line]').forEach(row => {
      const id = Number(row.dataset.productId || row.querySelector('[name="productId"]').value);
      if (!id) return;
      const product = stock.items.find(item => Number(item.id) === id);
      if (mode === 'inventory') { row.dataset.expected = String(Number(product?.quantity || 0)); row.querySelector('.ops-expected').textContent = Number(product?.quantity || 0).toLocaleString('es-CL'); }
      else row.querySelector('.ops-expected').textContent = Number(product?.quantity || 0).toLocaleString('es-CL');
    });
    updateLines(form);
  }
  function createLine(form) { const tbody = form.querySelector('[data-ops-line]').parentElement; const mode = form.dataset.opsForm === 'inventory' ? 'inventory' : form.dataset.opsForm === 'transfer' ? 'transfer' : 'purchase'; tbody.insertAdjacentHTML('beforeend', productLine({ mode })); updateLines(form); }
  function error(form, message) { const node = form.querySelector('[data-ops-error]'); node.textContent = message; node.hidden = false; }
  async function submit(form) {
    const type = form.dataset.opsForm; const button = form.querySelector('[type="submit"]'); button.disabled = true;
    const lines = [...form.querySelectorAll('[data-ops-line]')].map(row => ({ productId: Number(row.querySelector('[name="productId"]').value), quantity: Number(row.querySelector('[name="quantity"]').value), unitCost: Number(row.querySelector('[name="unitCost"]')?.value || 0), countedQuantity: Number(row.querySelector('[name="quantity"]').value) })).filter(line => line.productId);
    try {
      const products = lines.map(line => line.productId); if (new Set(products).size !== products.length) throw new Error('Cada artículo debe aparecer una sola vez en el detalle.');
      let result;
      if (type === 'purchase') { if (!lines.length) throw new Error('Agrega al menos un producto antes de recibir la factura.'); result = await api('/purchase-invoices', { method: 'POST', body: JSON.stringify({ supplierId: Number(form.elements.supplierId.value), locationId: Number(form.elements.locationId.value), issueDate: form.elements.issueDate.value, externalRef: form.elements.externalRef.value.trim(), notes: form.elements.notes.value.trim(), lines }) }); }
      if (type === 'transfer') { if (!lines.length) throw new Error('Agrega al menos un producto al traspaso.'); result = await api('/transfers', { method: 'POST', body: JSON.stringify({ originLocationId: Number(form.elements.originLocationId.value), destinationLocationId: Number(form.elements.destinationLocationId.value), type: form.elements.type.value, notes: form.elements.notes.value.trim(), lines }) }); }
      if (type === 'inventory') { if (!lines.length) throw new Error('Agrega al menos un producto al recuento.'); result = await api('/inventories', { method: 'POST', body: JSON.stringify({ locationId: Number(form.elements.locationId.value), notes: form.elements.notes.value.trim(), lines }) }); }
      if (type === 'location') { result = await api('/locations', { method: 'POST', body: JSON.stringify({ name: form.elements.name.value.trim(), branchId: form.elements.branchId.value || null }) }); }
      if (type === 'cash') { result = await api('/cash-closures/close', { method: 'POST', body: JSON.stringify({ countedAmount: Number(form.elements.countedAmount.value) }) }); }
      if (type === 'cash') notifyOps(`Caja cerrada · Calculado ${money(result.closure.calculatedAmount)} · Diferencia ${money(result.closure.difference)}`);
      else notifyOps(type === 'purchase' ? `Factura recibida · ${lines.length} artículos agregados al stock` : type === 'transfer' ? `Traspaso TRA-${String(result.transfer.id).padStart(5, '0')} creado` : type === 'inventory' ? `Inventario INV-${String(result.inventory.id).padStart(5, '0')} guardado` : 'Ubicación creada');
      closeModal(form.closest('[data-ops-layer]')); await mount();
    } catch (failure) { error(form, failure.message); }
    finally { button.disabled = false; }
  }
  async function viewInventory(id) {
    try { const data = await api(`/inventories/${id}`); modal('inventory-view', `Inventario INV-${String(id).padStart(5, '0')}`, `<div class="ops-detail-meta"><span>Local<strong>${esc(data.inventory.location)}</strong></span><span>Estado<strong>${data.inventory.status === 'closed' ? 'Cuadrado' : 'Abierto'}</strong></span><span>Observaciones<strong>${esc(data.inventory.notes || '—')}</strong></span></div><div class="ops-lines-scroll"><table class="ops-lines"><thead><tr><th>Código</th><th>Producto</th><th>Stock sistema</th><th>Conteo</th><th>Diferencia</th></tr></thead><tbody>${data.items.map(item => `<tr><td>${esc(item.sku)}</td><td>${esc(item.name)}</td><td>${Number(item.expected_quantity).toLocaleString('es-CL')}</td><td>${Number(item.counted_quantity).toLocaleString('es-CL')}</td><td>${(Number(item.counted_quantity) - Number(item.expected_quantity)).toLocaleString('es-CL')}</td></tr>`).join('')}</tbody></table></div><div class="ops-form-actions"><button class="secondary-button" type="button" data-ops-close>Cerrar</button></div>`); } catch (e) { notifyOps(e.message); }
  }
  async function viewTransfer(id) {
    try { const data = await api(`/transfers/${id}`); const item = data.transfer; modal('transfer-view', `Traspaso TRA-${String(id).padStart(5, '0')}`, `<div class="ops-detail-meta"><span>Origen<strong>${esc(item.origin)}</strong></span><span>Destino<strong>${esc(item.destination)}</strong></span><span>Estado<strong>${esc(({ requested: 'Solicitado', sent: 'Enviado', received: 'Recibido' })[item.status] || item.status)}</strong></span></div><div class="ops-lines-scroll"><table class="ops-lines"><thead><tr><th>Código</th><th>Producto</th><th>Cantidad</th></tr></thead><tbody>${data.items.map(line => `<tr><td>${esc(line.sku)}</td><td>${esc(line.name)}</td><td>${Number(line.quantity).toLocaleString('es-CL')}</td></tr>`).join('')}</tbody></table></div><div class="ops-form-actions"><button class="secondary-button" type="button" data-ops-close>Cerrar</button></div>`); } catch (e) { notifyOps(e.message); }
  }
  async function closeInventory(id) {
    try { const detail = await api(`/inventories/${id}`); const differences = detail.items.filter(item => Number(item.expected_quantity) !== Number(item.counted_quantity)).length; if (!window.confirm(`¿Cuadrar el inventario INV-${String(id).padStart(5, '0')}? Se registrarán ${differences} ajuste(s) en el stock.`)) return; await api(`/inventories/${id}/close`, { method: 'POST', body: '{}' }); notifyOps('Inventario cuadrado y ajustes de stock registrados.'); await mount(); } catch (e) { notifyOps(e.message); }
  }
  async function transitionTransfer(id, action) {
    try { await api(`/transfers/${id}/${action}`, { method: 'POST', body: '{}' }); notifyOps(action === 'send' ? 'Traspaso enviado; unidades descontadas del origen.' : 'Recepción confirmada; unidades agregadas al destino.'); await mount(); } catch (e) { notifyOps(e.message); }
  }
  document.addEventListener('click', async event => {
    const target = event.target;
    if (target.closest('[data-ops-create]')) { const route = target.closest('[data-ops-create]').dataset.opsCreate; try { await openCreate(route); } catch (e) { notifyOps(e.message); } }
    if (target.closest('[data-ops-close]')) closeModal(target.closest('[data-ops-layer]'));
    if (target.closest('[data-ops-filter]')) { const selected = target.closest('[data-ops-filter]'); state.filter = selected.dataset.opsFilter; content.querySelectorAll('[data-ops-filter]').forEach(b => b.classList.toggle('is-active', b === selected)); state.page = 1; renderRows(); }
    if (target.closest('[data-ops-refresh]')) load();
    if (target.closest('[data-ops-sort]')) { const column = Number(target.closest('[data-ops-sort]').dataset.opsSort); if (state.sortColumn === column) state.sortDirection *= -1; else { state.sortColumn = column; state.sortDirection = 1; } renderRows(); }
    if (target.closest('[data-ops-add-line]')) createLine(target.closest('form'));
    if (target.closest('.ops-remove-line')) { const row = target.closest('[data-ops-line]'); if (row.parentElement.querySelectorAll('[data-ops-line]').length > 1) row.remove(); updateLines(target.closest('form')); }
    if (target.closest('[data-ops-product]')) await selectProduct(target.closest('[data-ops-product]'));
    if (target.closest('[data-ops-view-inventory]')) viewInventory(target.closest('[data-ops-view-inventory]').dataset.opsViewInventory);
    if (target.closest('[data-ops-view-transfer]')) viewTransfer(target.closest('[data-ops-view-transfer]').dataset.opsViewTransfer);
    if (target.closest('[data-ops-close-inventory]')) closeInventory(target.closest('[data-ops-close-inventory]').dataset.opsCloseInventory);
    if (target.closest('[data-ops-transfer]')) { const b = target.closest('[data-ops-transfer]'); transitionTransfer(b.dataset.opsTransfer, b.dataset.transition); }
    if (target.closest('[data-ops-view-transfer]')) viewTransfer(target.closest('[data-ops-view-transfer]').dataset.opsViewTransfer);
    if (target.closest('[data-ops-export]')) { const q = state.query.toLocaleLowerCase('es-CL'); const rows = state.rows.filter(item => { const text = rowCells(item).join(' ').toLocaleLowerCase('es-CL'); return (!state.filter || text.includes(state.filter.toLocaleLowerCase('es-CL'))) && (!q || text.includes(q)); }).map(item => rowCells(item).map(value => `"${String(value).replaceAll('"','""')}"`).join(',')); const blob = new Blob([[configs[state.route].columns.map(c => `"${c}"`).join(','), ...rows].join('\r\n')], { type: 'text/csv;charset=utf-8' }); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `${state.route}.csv`; a.click(); URL.revokeObjectURL(a.href); }
  });
  document.addEventListener('input', event => {
    if (event.target.matches('[data-ops-search]')) { state.query = event.target.value; state.page = 1; renderRows(); }
    if (event.target.matches('[data-ops-product-search]')) { clearTimeout(event.target._searchTimer); event.target._searchTimer = setTimeout(() => searchProducts(event.target), 200); }
    if (event.target.matches('[data-counted-quantity],[name="unitCost"],[name="quantity"]')) updateLines(event.target.closest('form'));
    if (event.target.matches('[name="countedAmount"]')) { const form = event.target.closest('form'); const diff = Number(event.target.value || 0) - Number(form.dataset.calculated || 0); form.querySelector('[data-ops-difference]').textContent = `Diferencia ${diff >= 0 ? '+' : ''}${money(diff)}`; }
  });
  document.addEventListener('change', event => {
    if (event.target.matches('[data-ops-page-size]')) { state.pageSize = Number(event.target.value); state.page = 1; renderRows(); }
    if (event.target.matches('[data-ops-date]')) { state.page = 1; renderRows(); }
    if (event.target.matches('[data-ops-select-all]')) content.querySelectorAll('[data-ops-rows] input[type="checkbox"]').forEach(box => { box.checked = event.target.checked; });
    if (event.target.matches('[name="locationId"]') && event.target.closest('[data-ops-form="inventory"]')) refreshLocationStock(event.target.closest('form'), event.target.value, 'inventory').catch(e => notifyOps(e.message));
    if (event.target.matches('[name="originLocationId"]') && event.target.closest('[data-ops-form="transfer"]')) {
      const form = event.target.closest('form'); const destination = form.elements.destinationLocationId; const previous = destination.value;
      destination.innerHTML = state.locations.filter(location => String(location.id) !== event.target.value).map(location => `<option value="${location.id}">${esc(location.name)}</option>`).join('');
      if ([...destination.options].some(option => option.value === previous)) destination.value = previous;
      refreshLocationStock(form, event.target.value, 'transfer').catch(e => notifyOps(e.message));
    }
  });
  document.addEventListener('submit', event => { const form = event.target.closest('[data-ops-form]'); if (form) { event.preventDefault(); submit(form); } });
  document.addEventListener('click', event => { if (event.target.closest('[data-ops-prev]') && state.page > 1) { state.page--; renderRows(); } if (event.target.closest('[data-ops-next]')) { state.page++; renderRows(); } });
  window.addEventListener('hashchange', mount);
  window.mountOperations = mount;
  window.opsConfigs = configs;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();


