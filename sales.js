/* Sales workflow enhancements layered over the core app shell. */
let activeSaleDraftId = null;
let saleOptions = { sellers: [], paymentTerms: [], priceLists: [] };

function localSaleDate() {
  return new Intl.DateTimeFormat('es-CL', { dateStyle: 'short' }).format(new Date());
}

function saleLineMarkup(index = 1) {
  return `<tr data-sale-line><td><button class="remove-line-button" type="button" data-remove-sale-line aria-label="Quitar artículo">×</button></td><td data-line-number>${index}</td><td><input class="line-input" placeholder="Busque por código o descripción" aria-label="Concepto" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="product-search-results" data-product-search /></td><td data-line-sku>—</td><td data-line-description>—</td><td data-line-base>$ 0</td><td data-line-pvp data-amount="0">$ 0</td><td><input class="line-input line-number" type="number" min="0.001" step="1" value="1" aria-label="Unidades" data-line-qty /></td><td data-line-gross>$ 0</td><td><input class="line-input line-number" type="number" min="0" max="100" step="0.01" value="0" aria-label="Descuento %" data-line-dto /></td><td data-line-net>$ 0</td><td data-line-tax-rate>19%</td><td data-line-discount>$ 0</td><td data-line-stock>—</td></tr>`;
}

const SALE_TYPES = {
  BOL: { title: 'Nueva boleta', singular: 'Boleta', route: 'boletas', kicker: 'Crear boleta' },
  FAC: { title: 'Nueva factura', singular: 'Factura', route: 'facturas-emitidas', kicker: 'Crear factura' },
  PED: { title: 'Nuevo pedido', singular: 'Pedido', route: 'pedidos-clientes', kicker: 'Crear pedido' },
  PRE: { title: 'Nuevo presupuesto', singular: 'Presupuesto', route: 'presupuestos', kicker: 'Crear presupuesto' },
  GUI: { title: 'Nueva guía de despacho', singular: 'Guía', route: 'guias-emitidas', kicker: 'Crear guía' }
};

function newBoleta() {
  const currentType = window._nextSaleType || 'BOL';
  const typeCfg = SALE_TYPES[currentType] || SALE_TYPES.BOL;
  const sellers = saleOptions.sellers.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
  const terms = saleOptions.paymentTerms.map(item => `<option value="${escapeHtml(item.id)}" data-days="${Number(item.days)}">${escapeHtml(item.name)}</option>`).join('');
  const lists = saleOptions.priceLists.map(item => `<option value="${escapeHtml(item.code)}">${escapeHtml(item.name)}</option>`).join('');
  return `<div class="ticket-workspace"><div class="ticket-topline"><div><span class="ticket-kicker">${typeCfg.kicker}</span><strong>Oftaplus</strong></div><div class="ticket-window-actions"><button class="icon-button" type="button" data-close-sale aria-label="Cerrar venta">×</button></div></div>
    <div class="ticket-document-head"><div class="ticket-number"><strong data-sale-title>${activeSaleDraftId ? `Borrador #${activeSaleDraftId}` : typeCfg.title}</strong><span data-sale-folio>${activeSaleDraftId ? 'Borrador guardado' : 'Sin número asignado'}</span></div><div class="ticket-date"><strong>${localSaleDate()}</strong><span><em>Fecha local</em></span></div><label class="ticket-client"><span>Cliente</span><div class="input-with-icon"><span>♟</span><input value="VENTA DIRECTA" aria-label="Cliente" data-sale-customer readonly /><button class="icon-button js-modal" type="button" data-modal="client-selector" aria-label="Seleccionar cliente">⌄</button></div></label></div>
    <div class="ticket-toolbar"><label>Agente<select aria-label="Agente" data-sale-seller><option value="">Sin asignar</option>${sellers}</select><button class="term-add-button" type="button" data-create-seller>＋ Añadir agente</button></label><label>Tarifa<select aria-label="Tarifa de precios" data-sale-price-list>${lists || '<option value="general">General</option>'}</select></label><label>Motivo de venta<input aria-label="Motivo de venta" data-sale-reason placeholder="Venta en tienda" maxlength="120" /></label><label>Condición de pago<select aria-label="Condición de pago" data-sale-term><option value="">Seleccionar condición</option>${terms}</select><button class="term-add-button" type="button" data-create-term>＋ Crear condición</button></label></div>
    <p class="sale-electronic-note">La impresión disponible es un comprobante interno. La emisión electrónica SII requiere habilitar certificado digital, CAF y autorización del emisor. <button type="button" class="term-add-button" data-company-settings>Configurar datos del emisor</button></p>
    <section class="ticket-card ticket-lines-card"><div class="ticket-section-title"><strong>Detalle de artículos</strong><button class="secondary-button" type="button" data-add-sale-line>＋ Agregar artículo</button></div><div class="ticket-table-wrap"><table class="ticket-lines"><thead><tr><th>×</th><th>#</th><th>Concepto</th><th>Referencia</th><th>Descripción</th><th>Base</th><th>PVP</th><th>Unidades</th><th>Bruto</th><th>%Dto</th><th>Neto</th><th>%IVA</th><th>Total dto.</th><th>Stock</th></tr></thead><tbody>${saleLineMarkup()}</tbody></table></div></section>
    <section class="ticket-lower-grid"><div class="ticket-card payments-card"><div class="ticket-section-title"><strong>Cobros</strong></div><table class="payments-table"><thead><tr><th>Fecha</th><th>Forma de pago</th><th>Importe</th></tr></thead><tbody><tr><td colspan="3">Los cobros se registran al confirmar la venta.</td></tr></tbody></table><strong class="pending-payment" data-total-pending>Pendiente: $ 0</strong></div><div class="ticket-card ticket-summary"><div><span>Base</span><strong data-total-base>$ 0</strong></div><div><span>Cuota IVA</span><strong data-total-tax>$ 0</strong></div><div class="summary-total"><span>Total</span><strong data-total-amount data-amount="0">$ 0</strong></div></div></section>
    <div class="ticket-tabs"><button class="is-active" type="button">Cobros</button><button type="button" data-sale-tool="documents">Doc. asociados</button><button type="button" data-sale-tool="stock">Stock</button><button type="button" data-sale-tool="approvals">Vistos buenos</button><button type="button" data-sale-tool="optical">Óptica</button></div>
    <div class="ticket-footer"><div class="footer-tools"></div><a class="secondary-button" href="#${typeCfg.route}">Cerrar</a><button class="secondary-button" type="button" data-save-draft>Guardar borrador</button><button class="primary-button" type="button" data-open-charge>▣ Continuar al cobro</button></div>
    ${modal('client-selector','Seleccionar cliente',`<div class="modal-search"><div class="search-box"><span>⌕</span><input placeholder="Buscar por nombre, RUT o correo" aria-label="Buscar cliente" /></div></div><div class="selector-list"><div class="product-lookup-status">Cargando clientes…</div></div>`)}</div>`;
}

function saleChargeMarkup() {
  return modal('charge-form', 'Cobrar venta', `<form class="charge-form" data-charge-form><p class="charge-pending" data-charge-pending></p><div class="sale-payment-entries" data-charge-entries></div><button class="secondary-button" type="button" data-add-charge-row>＋ Dividir pago</button><label>Observación del cobro<input name="notes" maxlength="255" /></label><p class="form-error" data-charge-error hidden></p><div class="modal-actions"><button class="secondary-button modal-close" type="button">Volver a la venta</button><button class="primary-button" type="submit">Confirmar venta y cobros</button></div></form>`);
}

function paymentEntryMarkup(methods, amount = '') {
  return `<div class="sale-payment-entry"><label>Forma de pago<select data-entry-method required><option value="">Selecciona una forma de pago</option>${methods.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('')}</select></label><label>Importe<input type="number" min="0" step="1" inputmode="numeric" data-entry-amount value="${amount}" /></label><button type="button" class="icon-button" data-remove-charge-row aria-label="Quitar forma de pago">×</button></div>`;
}

async function hydrateNewBoleta(draftId = activeSaleDraftId) {
  let chargeLayer = document.querySelector('[data-modal-layer="charge-form"]');
  if (chargeLayer && !chargeLayer.querySelector('[data-charge-entries]')) { chargeLayer.remove(); chargeLayer = null; }
  if (!chargeLayer) document.body.insertAdjacentHTML('beforeend', saleChargeMarkup());
  recalcTicket();
  try {
    const [customers, methods, options] = await Promise.all([apiRequest('/customers?limit=50'), apiRequest('/payment-methods'), apiRequest('/sale-options')]);
    const clientList = document.querySelector('[data-modal-layer="client-selector"] .selector-list');
    if (clientList) clientList.innerHTML = selectorRows('client', customers.items);
    saleOptions = options;
    const seller = document.querySelector('[data-sale-seller]');
    if (seller) seller.innerHTML = '<option value="">Sin asignar</option>' + options.sellers.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
    const term = document.querySelector('[data-sale-term]');
    if (term) term.innerHTML = '<option value="">Seleccionar condición</option>' + options.paymentTerms.map(item => `<option value="${escapeHtml(item.id)}" data-days="${Number(item.days)}">${escapeHtml(item.name)}</option>`).join('');
    const priceList = document.querySelector('[data-sale-price-list]');
    if (priceList) priceList.innerHTML = options.priceLists.map(item => `<option value="${escapeHtml(item.code)}">${escapeHtml(item.name)}</option>`).join('') || '<option value="general">General</option>';
    if (!document.querySelector('[data-charge-methods]')) document.body.insertAdjacentHTML('beforeend', `<select hidden data-charge-methods>${methods.items.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('')}</select>`);
    if (draftId) await loadSaleDraft(Number(draftId));
  } catch (error) {
    notify('No se pudieron cargar los clientes, vendedores o condiciones de pago: ' + error.message);
  }
}

function updateSaleLine(row, product, selected = true) {
  const input = row.querySelector('[data-product-search]');
  if (!input) return;
  if (selected) {
    input.dataset.productId = String(product.id);
    input.dataset.taxRate = String(product.tax_rate || 19);
    input.dataset.productType = product.product_type || 'product';
    row.dataset.stockQuantity = String(product.stock_quantity || 0);
    input.value = product.description || product.name || '';
    row.querySelector('[data-line-sku]').textContent = product.sku || '—';
    row.querySelector('[data-line-description]').textContent = product.description || product.name || '—';
    const pvp = Number(product.price_amount || 0);
    const priceCell = row.querySelector('[data-line-pvp]');
    priceCell.dataset.amount = String(pvp);
    priceCell.textContent = clp(pvp);
    row.querySelector('[data-line-tax-rate]').textContent = Number(product.tax_rate || 19).toLocaleString('es-CL') + '%';
    row.querySelector('[data-line-stock]').textContent = product.product_type === 'service' ? '—' : Number(product.stock_quantity || 0).toLocaleString('es-CL');
  } else {
    delete input.dataset.productId;
    delete input.dataset.taxRate;
    delete input.dataset.productType;
    delete row.dataset.stockQuantity;
    input.value = '';
    row.querySelector('[data-line-sku]').textContent = '—';
    row.querySelector('[data-line-description]').textContent = '—';
    const priceCell = row.querySelector('[data-line-pvp]');
    priceCell.dataset.amount = '0'; priceCell.textContent = clp(0);
    row.querySelector('[data-line-tax-rate]').textContent = '19%';
    row.querySelector('[data-line-stock]').textContent = '—';
  }
  recalcTicket();
}

function ticketLinePayload(row, line) {
  const price = Number(row?.querySelector('[data-line-pvp]')?.dataset.amount || 0);
  const rate = Number(line?.dataset.taxRate || 19);
  return { productId: Number(line?.dataset.productId), concept: line?.value || '', quantity: Number(row?.querySelector('[data-line-qty]')?.value || 1), unitPrice: Math.round((price / (1 + rate / 100)) * 100) / 100, discountPercent: Number(row?.querySelector('[data-line-dto]')?.value || 0), taxRate: rate };
}

function recalcTicket() {
  const rows = [...document.querySelectorAll('.ticket-lines tbody tr[data-sale-line]')];
  let base = 0, tax = 0, total = 0;
  rows.forEach((row, index) => {
    row.querySelector('[data-line-number]').textContent = String(index + 1);
    const price = Number(row.querySelector('[data-line-pvp]')?.dataset.amount || 0);
    const rate = Number(row.querySelector('[data-product-search]')?.dataset.taxRate || 19);
    const result = computeLine(price, row.querySelector('[data-line-qty]')?.value, row.querySelector('[data-line-dto]')?.value, rate);
    const input = row.querySelector('[data-product-search]');
    const stockShort = input?.dataset.productId && input.dataset.productType !== 'service' && Number(row.querySelector('[data-line-qty]')?.value || 0) > Number(row.dataset.stockQuantity || 0);
    row.classList.toggle('sale-stock-short', Boolean(stockShort));
    row.querySelector('[data-line-stock]')?.setAttribute('title', stockShort ? 'La cantidad supera el stock disponible' : 'Stock actual');
    row.querySelector('[data-line-base]').textContent = clp(result.base);
    row.querySelector('[data-line-gross]').textContent = clp(result.gross);
    row.querySelector('[data-line-net]').textContent = clp(result.net);
    row.querySelector('[data-line-discount]').textContent = clp(result.discount);
    base += result.net; tax += result.tax; total += result.total;
  });
  document.querySelector('[data-total-base]')?.replaceChildren(document.createTextNode(clp(base)));
  document.querySelector('[data-total-tax]')?.replaceChildren(document.createTextNode(clp(tax)));
  const totalNode = document.querySelector('[data-total-amount]');
  if (totalNode) { totalNode.textContent = clp(total); totalNode.dataset.amount = String(Math.round(total * 100) / 100); }
  const pending = document.querySelector('[data-total-pending]');
  if (pending) pending.textContent = 'Pendiente: ' + clp(total);
}

async function loadSaleDraft(id) {
  const result = await apiRequest(`/documents/${id}`);
  if (result.document.status !== 'draft') throw new Error('Este documento ya no es un borrador editable.');
  activeSaleDraftId = Number(result.document.id);
  const docTypeCode = result.document.document_type_code || result.document.type || 'BOL';
  window._nextSaleType = docTypeCode;
  const typeCfg = SALE_TYPES[docTypeCode] || SALE_TYPES.BOL;
  const kicker = document.querySelector('.ticket-kicker');
  if (kicker) kicker.textContent = typeCfg.kicker;
  const closeLink = document.querySelector('.ticket-footer a.secondary-button');
  if (closeLink) closeLink.href = `#${typeCfg.route}`;
  const title = document.querySelector('[data-sale-title]');
  if (title) title.textContent = `Borrador #${activeSaleDraftId}`;
  const customer = document.querySelector('[data-sale-customer]');
  if (customer) { customer.value = result.document.customer_name || 'VENTA DIRECTA'; if (result.document.customer_id) customer.dataset.customerId = result.document.customer_id; }
  const seller = document.querySelector('[data-sale-seller]'); if (seller) seller.value = result.document.seller_id || '';
  const term = document.querySelector('[data-sale-term]'); if (term) term.value = result.document.payment_term_id || '';
  const priceList = document.querySelector('[data-sale-price-list]'); if (priceList && result.document.metadata?.price_list) priceList.value = result.document.metadata.price_list;
  const reason = document.querySelector('[data-sale-reason]'); if (reason) reason.value = result.document.notes || '';
  const tbody = document.querySelector('.ticket-lines tbody');
  if (!tbody) return;
  tbody.innerHTML = '';
  for (const line of result.lines) {
    const row = document.createElement('tbody');
    row.innerHTML = saleLineMarkup(result.lines.indexOf(line) + 1);
    const lineRow = row.firstElementChild;
    const taxRate = Number(line.tax_rate || 19);
    const pvp = Number(line.unit_price) * (1 + taxRate / 100);
    updateSaleLine(lineRow, { id: line.product_id, name: line.concept, description: line.description, sku: line.reference, tax_rate: taxRate, price_amount: pvp, product_type: line.product_type || 'product', stock_quantity: line.stock_quantity }, true);
    lineRow.querySelector('[data-line-qty]').value = line.quantity;
    lineRow.querySelector('[data-line-dto]').value = line.discount_percent;
    tbody.appendChild(lineRow);
  }
  recalcTicket();
}

async function saveSaleDraft() {
  const lines = [...document.querySelectorAll('.ticket-lines tbody tr[data-sale-line]')].map(row => {
    const input = row.querySelector('[data-product-search]');
    return input?.dataset.productId ? ticketLinePayload(row, input) : null;
  }).filter(Boolean);
  if (!lines.length) { notify('Agrega al menos un producto antes de guardar el borrador.'); return; }
  const currentType = window._nextSaleType || 'BOL';
  const term = document.querySelector('[data-sale-term]')?.value || '';
  const payload = { type: currentType, documentId: activeSaleDraftId, customerId: document.querySelector('[data-sale-customer]')?.dataset.customerId || null, sellerId: document.querySelector('[data-sale-seller]')?.value || null, paymentTermId: term || null, priceList: document.querySelector('[data-sale-price-list]')?.value || 'general', notes: document.querySelector('[data-sale-reason]')?.value || '', lines };
  const result = await apiRequest('/documents/draft', { method: 'POST', body: JSON.stringify(payload) });
  activeSaleDraftId = Number(result.document.id);
  document.querySelector('[data-sale-title]').textContent = `Borrador #${activeSaleDraftId}`;
  notify(`Borrador guardado #${activeSaleDraftId}`);
}

function currentTypeAffectsStock(typeCode) {
  const found = saleOptions.documentTypes?.find(dt => dt.code === typeCode);
  if (found) return Number(found.affects_stock) === 1;
  return !['PRE', 'PED'].includes(typeCode);
}

function currentTypeAffectsReceivables(typeCode) {
  const found = saleOptions.documentTypes?.find(dt => dt.code === typeCode);
  if (found) return Number(found.affects_receivables) === 1;
  return !['PRE', 'PED'].includes(typeCode);
}

function openChargeForm() {
  const lines = [...document.querySelectorAll('.ticket-lines tbody tr[data-sale-line]')].filter(row => row.querySelector('[data-product-search]')?.dataset.productId);
  if (!lines.length) { notify('Agrega un producto del catálogo antes de cobrar.'); return; }
  if (lines.some(row => Number(row.querySelector('[data-line-qty]').value) <= 0)) { notify('Revisa que todas las cantidades sean mayores que cero.'); return; }
  const currentType = window._nextSaleType || 'BOL';
  const affectsStock = currentTypeAffectsStock(currentType);
  const affectsReceivables = currentTypeAffectsReceivables(currentType);
  if (affectsStock) {
    const short = lines.find(row => { const input=row.querySelector('[data-product-search]'); return input.dataset.productType !== 'service' && Number(row.querySelector('[data-line-qty]').value) > Number(row.dataset.stockQuantity || 0); });
    if (short) { notify(`Stock insuficiente para ${short.querySelector('[data-line-sku]').textContent}. Disponible: ${short.dataset.stockQuantity || 0}.`); return; }
  }
  const form = document.querySelector('[data-charge-form]');
  const methods = [...document.querySelectorAll('[data-charge-methods] option')].map(option => ({ id: option.value, name: option.textContent }));
  if (!form || !methods.length) { notify('No hay formas de pago configuradas.'); return; }
  const entries = form.querySelector('[data-charge-entries]');
  const totalAmount = Math.round(Number(document.querySelector('[data-total-amount]').dataset.amount || 0));
  entries.innerHTML = paymentEntryMarkup(methods, affectsReceivables ? totalAmount : 0);
  const cash = methods.find(item => item.name.toLowerCase().includes('efectivo')) || methods[0];
  if (entries.querySelector('[data-entry-method]')) entries.querySelector('[data-entry-method]').value = cash.id;
  form.querySelector('[data-charge-pending]').textContent = !affectsReceivables
    ? `Total: ${clp(totalAmount)}. (Este documento no genera deuda ni requiere cobro inmediato).`
    : `Total venta: ${clp(totalAmount)}. Si no abonas hoy, elige una condición a crédito.`;
  form.querySelector('[data-charge-error]').hidden = true;
  openModal('charge-form');
}

async function submitCharge(form) {
  const button = form.querySelector('[type="submit"]');
  const error = form.querySelector('[data-charge-error]');
  const lines = [...document.querySelectorAll('.ticket-lines tbody tr[data-sale-line]')].map(row => {
    const input = row.querySelector('[data-product-search]');
    return input?.dataset.productId ? ticketLinePayload(row, input) : null;
  }).filter(Boolean);
  const payments = [...form.querySelectorAll('.sale-payment-entry')].map(row => ({ paymentMethodId: Number(row.querySelector('[data-entry-method]').value), amount: Number(row.querySelector('[data-entry-amount]').value || 0) })).filter(item => item.amount > 0);
  const term = document.querySelector('[data-sale-term]')?.value || '';
  const termDays = Number(document.querySelector('[data-sale-term] option:checked')?.dataset.days || 0);
  const currentType = window._nextSaleType || 'BOL';
  const typeCfg = SALE_TYPES[currentType] || SALE_TYPES.BOL;
  const affectsReceivables = currentTypeAffectsReceivables(currentType);
  const totalAmount = Math.round(Number(document.querySelector('[data-total-amount]')?.dataset.amount || 0));
  if (!payments.length && termDays <= 0 && affectsReceivables && totalAmount > 0) { error.textContent = 'Ingresa un abono o selecciona una condición de pago a crédito.'; error.hidden = false; return; }
  const payload = { type: currentType, documentId: activeSaleDraftId, customerId: document.querySelector('[data-sale-customer]')?.dataset.customerId || null, sellerId: document.querySelector('[data-sale-seller]')?.value || null, paymentTermId: term || null, priceList: document.querySelector('[data-sale-price-list]')?.value || 'general', notes: document.querySelector('[data-sale-reason]')?.value || '', lines, payments };
  button.disabled = true; button.textContent = 'Procesando…'; error.hidden = true;
  try {
    const result = await apiRequest('/documents/charge', { method: 'POST', body: JSON.stringify(payload) });
    activeSaleDraftId = null;
    closeModal(form.closest('.modal-backdrop'));
    notify(`${typeCfg.singular} ${result.document.folio} registrada. Estado: ${result.document.status === 'paid' ? 'pagada' : 'con saldo pendiente'}. Emisión SII sin configurar.`);
    window.lastIssuedSaleId = result.document.id;
    location.hash = typeCfg.route;
  } catch (failure) {
    error.textContent = failure.message; error.hidden = false;
  } finally {
    button.disabled = false; button.textContent = 'Confirmar venta y cobros';
  }
}

function ensureLaterPaymentModal() {
  if (document.querySelector('[data-modal-layer="later-payment"]')) return;
  document.body.insertAdjacentHTML('beforeend', modal('later-payment','Registrar abono','<form data-later-payment-form class="charge-form"><input type="hidden" name="documentId" /><label>Forma de pago<select name="paymentMethodId" required data-later-method></select></label><label>Importe<input type="number" name="amount" min="1" step="1" required /></label><label>Referencia<input name="reference" maxlength="120" /></label><label>Observación<input name="notes" maxlength="255" /></label><p class="form-error" data-later-error hidden></p><div class="modal-actions"><button class="secondary-button modal-close" type="button">Cancelar</button><button class="primary-button" type="submit">Registrar abono</button></div></form>'));
}

function ensurePaymentTermModal() {
  if (document.querySelector('[data-modal-layer="payment-term-form"]')) return;
  document.body.insertAdjacentHTML('beforeend', modal('payment-term-form','Nueva condición de pago','<form class="charge-form" data-payment-term-form><label>Nombre<input name="name" required maxlength="100" placeholder="Ej.: Crédito 30 días" /></label><label>Plazo en días<input name="days" required type="number" min="0" max="3650" step="1" value="30" /></label><p class="form-error" data-payment-term-error hidden></p><div class="modal-actions"><button type="button" class="secondary-button modal-close">Cancelar</button><button type="submit" class="primary-button">Guardar condición</button></div></form>'));
}

function ensureSellerModal() {
  if (document.querySelector('[data-modal-layer="seller-form"]')) return;
  document.body.insertAdjacentHTML('beforeend', modal('seller-form','Añadir agente de venta','<form class="charge-form" data-seller-form><label>Nombre del agente<input name="name" required maxlength="160" placeholder="Nombre y apellido" /></label><p class="charge-pending">El agente quedará disponible para asignar ventas; esto no crea una cuenta de acceso.</p><p class="form-error" data-seller-error hidden></p><div class="modal-actions"><button type="button" class="secondary-button modal-close">Cancelar</button><button type="submit" class="primary-button">Guardar agente</button></div></form>'));
}

function ensureCompanyModal() {
  if (document.querySelector('[data-modal-layer="company-form"]')) return;
  document.body.insertAdjacentHTML('beforeend', modal('company-form','Datos del emisor','<form class="charge-form" data-company-form><input type="hidden" name="branchId" /><label>Nombre comercial<input name="name" required maxlength="160" /></label><label>Razón social<input name="legalName" maxlength="200" /></label><label>RUT emisor<input name="taxId" maxlength="40" placeholder="12.345.678-5" /></label><label>Dirección del local<input name="branchAddress" maxlength="255" /></label><p class="charge-pending">Estos datos se imprimen en el comprobante interno y se usan como datos base para habilitar la emisión electrónica.</p><p class="form-error" data-company-error hidden></p><div class="modal-actions"><button type="button" class="secondary-button modal-close">Cancelar</button><button type="submit" class="primary-button">Guardar datos</button></div></form>'));
}

async function openCompanySettings() {
  ensureCompanyModal();
  const result=await apiRequest('/company');
  const form=document.querySelector('[data-company-form]');
  form.elements.name.value=result.company.name||'';
  form.elements.legalName.value=result.company.legal_name||'';
  form.elements.taxId.value=result.company.tax_id||'';
  form.elements.branchId.value=result.branch?.id||'';
  form.elements.branchAddress.value=result.branch?.address||'';
  openModal('company-form');
}

async function hydrateBoletas() {
  try {
    const result = await apiRequest('/documents?type=BOL&limit=100');
    const dateRange = document.querySelector('.filter-bar .period');
    if (dateRange) {
      const end = new Date(); const start = new Date(end); start.setMonth(start.getMonth() - 6);
      const format = value => new Intl.DateTimeFormat('es-CL', { day: '2-digit', month: 'short', year: 'numeric' }).format(value);
      dateRange.textContent = `◷ ${format(start)} – ${format(end)}`;
    }
    const rows = result.items.map(item => {
      const folio = item.number ? `${item.prefix || ''}/${String(item.number).padStart(6,'0')}` : `Borrador #${item.id}`;
      const status = statusLabels[item.status] || item.status;
      const actions = item.status === 'draft'
        ? `<button type="button" class="secondary-button compact-action" data-resume-sale="${item.id}">Continuar</button>`
        : `<button type="button" class="secondary-button compact-action" data-print-sale="${item.id}">Imprimir</button>${Number(item.pending_total)>0 && item.status!=='cancelled' ? `<button type="button" class="secondary-button compact-action" data-later-payment="${item.id}" data-pending="${item.pending_total}">Abonar</button>` : ''}`;
      return `<tr><td><input type="checkbox" aria-label="Seleccionar registro" /></td><td>${escapeHtml(folio)}</td><td>${escapeHtml(item.branch||'Casa matriz')}</td><td>${escapeHtml(item.customer_name||'Venta directa')}</td><td><span class="status ${statusClass(status)}">${escapeHtml(status)}</span></td><td>${clp(item.paid_total)}</td><td>${clp(item.pending_total)}</td><td>${clp(item.discount_total)}</td><td>${clp(item.total)}</td><td>${shortDate(item.created_at,false)}</td><td class="sale-list-actions">${actions}</td></tr>`;
    }).join('');
    fillListRows(rows, result.items.length);
  } catch (error) { notify('No se pudieron cargar las boletas: ' + error.message); }
}

async function printSaleDocument(id) {
  const result = await apiRequest(`/documents/${id}`);
  const doc = result.document;
  const e = value => escapeHtml(value ?? '');
  const lines = result.lines.map(line => `<tr><td>${e(line.concept)}<small>${e(line.reference)}</small></td><td>${Number(line.quantity).toLocaleString('es-CL')}</td><td>${clp(line.unit_price)}</td><td>${clp(line.total_amount)}</td></tr>`).join('');
  const payments = result.payments.map(payment => `<p>${e(payment.payment_method)} · ${shortDate(payment.paid_at,true)} · ${clp(payment.amount)}</p>`).join('') || '<p>Sin abonos registrados</p>';
  const win = window.open('', '_blank', 'width=850,height=900');
  if (!win) { notify('El navegador bloqueó la ventana de impresión. Permite ventanas emergentes para esta aplicación local.'); return; }
  win.document.write(`<!doctype html><html lang="es"><meta charset="utf-8"><title>Comprobante ${e(doc.prefix)}/${e(doc.number)}</title><style>body{font:14px Arial,sans-serif;color:#182433;max-width:760px;margin:35px auto;padding:20px}header{display:flex;justify-content:space-between;border-bottom:2px solid #162d4a;padding-bottom:14px}h1{font-size:21px;margin:0 0 8px}table{width:100%;border-collapse:collapse;margin-top:22px}th,td{text-align:left;border-bottom:1px solid #dce2e9;padding:9px}td:last-child,th:last-child{text-align:right}small{display:block;color:#66748a}.totals{text-align:right;margin-top:18px}.notice{margin-top:24px;padding:12px;background:#fff4d6;border:1px solid #ecd59c;font-size:12px}.toolbar{margin-bottom:18px}@media print{.toolbar{display:none}body{margin:0 auto}}</style><button class="toolbar" onclick="window.print()">Imprimir comprobante</button><header><div><h1>${e(doc.legal_name||doc.company_name||'Oftaplus')}</h1><div>RUT: ${e(doc.company_tax_id||'Pendiente configurar')}</div><div>${e(doc.branch||'')}</div><div>${e(doc.branch_address||'')}</div></div><div><strong>COMPROBANTE INTERNO</strong><br>Folio ${e(doc.prefix)}/${String(doc.number||'').padStart(6,'0')}<br>Fecha ${e(doc.issue_date)}</div></header><p><b>Cliente:</b> ${e(doc.customer_name||'Venta directa')} ${doc.customer_tax_id ? `· RUT ${e(doc.customer_tax_id)}` : ''}</p><table><thead><tr><th>Artículo</th><th>Cant.</th><th>Precio neto</th><th>Total</th></tr></thead><tbody>${lines}</tbody></table><div class="totals"><p>Base: ${clp(doc.subtotal)}</p><p>Descuentos: ${clp(doc.discount_total)}</p><p>IVA: ${clp(doc.tax_total)}</p><h2>Total: ${clp(doc.total)}</h2><p>Pagado: ${clp(doc.paid_total)} · Saldo: ${clp(doc.pending_total)}</p></div><h3>Abonos</h3>${payments}<div class="notice">Este documento es un comprobante interno de la aplicación. No es un Documento Tributario Electrónico (DTE) ni reemplaza una boleta electrónica autorizada por el SII.</div><script>window.onload=()=>window.print()</script></html>`);
  win.document.close();
}

function ensureOpticalExamModal(){if(document.querySelector('[data-optical-exam-form]'))return;document.body.insertAdjacentHTML('beforeend',`<div class="modal-backdrop" data-modal-layer="optical-exam" hidden><section class="modal-card optical-exam-card" role="dialog" aria-modal="true"><div class="modal-head"><h2>Receta óptica del cliente</h2><button class="icon-button modal-close" type="button" aria-label="Cerrar">×</button></div><form data-optical-exam-form><div class="optical-exam-grid"><strong>Ojo</strong><strong>Esfera</strong><strong>Cilindro</strong><strong>Eje</strong><strong>Adición</strong><label>OD</label><input name="rightSphere" inputmode="decimal"><input name="rightCylinder" inputmode="decimal"><input name="rightAxis" inputmode="numeric"><input name="rightAddition" inputmode="decimal"><label>OI</label><input name="leftSphere" inputmode="decimal"><input name="leftCylinder" inputmode="decimal"><input name="leftAxis" inputmode="numeric"><input name="leftAddition" inputmode="decimal"></div><label class="optical-extra">Distancia pupilar<input name="pupillaryDistance" inputmode="decimal"></label><label class="optical-extra">Observaciones<textarea name="notes" rows="3" maxlength="1000"></textarea></label><p class="form-error" data-optical-error hidden></p><div class="modal-actions"><button class="secondary-button modal-close" type="button">Cancelar</button><button class="primary-button" type="submit">Guardar examen</button></div></form></section></div>`)}
function openSaleStock(){const rows=[...document.querySelectorAll('.ticket-lines tbody tr[data-sale-line]')].map(row=>{const input=row.querySelector('[data-product-search]'),quantity=Number(row.querySelector('[data-line-qty]')?.value||0),stock=Number(row.dataset.stockQuantity||0);return input?.dataset.productId?`<tr><td>${escapeHtml(row.querySelector('[data-line-sku]')?.textContent||'—')}</td><td>${escapeHtml(row.querySelector('[data-line-description]')?.textContent||input.value)}</td><td>${quantity.toLocaleString('es-CL')}</td><td>${input.dataset.productType==='service'?'—':stock.toLocaleString('es-CL')}</td><td>${input.dataset.productType==='service'?'Servicio':(stock-quantity).toLocaleString('es-CL')}</td></tr>`:''}).filter(Boolean).join('');let layer=document.querySelector('[data-modal-layer="sale-stock"]');if(!layer){document.body.insertAdjacentHTML('beforeend',`<div class="modal-backdrop" data-modal-layer="sale-stock" hidden><section class="modal-card" role="dialog" aria-modal="true"><div class="modal-head"><h2>Disponibilidad de artículos</h2><button class="icon-button modal-close" type="button">×</button></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Referencia</th><th>Artículo</th><th>Venta</th><th>Disponible</th><th>Saldo</th></tr></thead><tbody data-sale-stock-rows></tbody></table></div></section></div>`);layer=document.querySelector('[data-modal-layer="sale-stock"]')}layer.querySelector('[data-sale-stock-rows]').innerHTML=rows||'<tr><td colspan="5">Agrega productos para consultar su stock disponible.</td></tr>';openModal('sale-stock')}
async function openSaleRelations(){if(!activeSaleDraftId){notify('Guarda el borrador para asociar documentos.');return}let layer=document.querySelector('[data-modal-layer="sale-relations"]');if(!layer){document.body.insertAdjacentHTML('beforeend',`<div class="modal-backdrop" data-modal-layer="sale-relations" hidden><section class="modal-card" role="dialog" aria-modal="true"><div class="modal-head"><h2>Documentos asociados</h2><button class="icon-button modal-close" type="button">×</button></div><form class="form-grid" data-relation-form><label>Asociar con boleta<select name="targetDocumentId" required></select></label><button class="primary-button" type="submit">Asociar documento</button><p class="form-error" data-relation-error hidden></p></form><div class="table-wrap"><table class="data-table"><thead><tr><th>Documento</th><th>Tipo</th><th>Estado</th><th>Cliente</th><th>Total</th><th></th></tr></thead><tbody data-related-documents></tbody></table></div></section></div>`);layer=document.querySelector('[data-modal-layer="sale-relations"]')}const [related,docs]=await Promise.all([apiRequest(`/documents/${activeSaleDraftId}/relations`),apiRequest('/documents?type=BOL&limit=100')]);const used=new Set(related.items.map(item=>Number(item.document_id)));const select=layer.querySelector('[name="targetDocumentId"]');select.innerHTML='<option value="">Selecciona documento</option>'+docs.items.filter(item=>Number(item.id)!==activeSaleDraftId&&!used.has(Number(item.id))).map(item=>`<option value="${escapeHtml(item.id)}">${escapeHtml(item.prefix||'BOL')}/${String(item.number||'').padStart(6,'0')} · ${escapeHtml(item.customer_name||'Venta directa')} · ${clp(item.total)}</option>`).join('');layer.querySelector('[data-related-documents]').innerHTML=related.items.map(item=>`<tr><td>${escapeHtml(item.prefix||item.document_type)}/${String(item.number||'').padStart(6,'0')}</td><td>${escapeHtml(item.document_type)}</td><td>${escapeHtml(item.status)}</td><td>${escapeHtml(item.customer_name||'Venta directa')}</td><td>${clp(item.total)}</td><td><button class="secondary-button" type="button" data-unlink-relation="${escapeHtml(item.id)}">Quitar</button></td></tr>`).join('')||'<tr><td colspan="6">No hay documentos asociados.</td></tr>';openModal('sale-relations')}
async function unlinkSaleRelation(relationId){await apiRequest(`/documents/${activeSaleDraftId}/relations/${encodeURIComponent(relationId)}`,{method:'DELETE'});notify('Documento desvinculado');openSaleRelations()}
function saleApprovalLabel(status){return {pending:'Pendiente',approved:'Aprobado',rejected:'Rechazado'}[status]||status}
async function refreshSaleApprovals(){const list=document.querySelector('[data-approval-list]');if(!list||!activeSaleDraftId)return;const data=await apiRequest(`/documents/${activeSaleDraftId}/approvals`);const canDecide=['owner','admin'].includes(authUser?.role);list.innerHTML=data.items.length?data.items.map(item=>`<tr><td>${escapeHtml(item.requested_at.slice(0,10))}</td><td>${escapeHtml(item.requested_by||'—')}</td><td>${escapeHtml(item.reason||'—')}</td><td><strong>${saleApprovalLabel(item.status)}</strong>${item.decision_note?`<br><small>${escapeHtml(item.decision_note)}</small>`:''}</td><td>${item.status==='pending'&&canDecide?`<button class="secondary-button" type="button" data-approval-decide="approve" data-approval-id="${item.id}">Aprobar</button> <button class="secondary-button" type="button" data-approval-decide="reject" data-approval-id="${item.id}">Rechazar</button>`:''}</td></tr>`).join(''):'<tr><td colspan="5">No hay solicitudes de visto bueno</td></tr>'}
function openSaleApproval(){if(!activeSaleDraftId){notify('Guarda el borrador para solicitar el visto bueno.');return}let layer=document.querySelector('[data-modal-layer="sale-approval"]');if(!layer){document.body.insertAdjacentHTML('beforeend',`<div class="modal-backdrop" data-modal-layer="sale-approval" hidden><section class="modal-card" role="dialog" aria-modal="true"><div class="modal-head"><h2>Vistos buenos</h2><button class="icon-button modal-close" type="button">×</button></div><table class="payments-table"><thead><tr><th>Fecha</th><th>Solicitó</th><th>Motivo</th><th>Estado</th><th></th></tr></thead><tbody data-approval-list></tbody></table><form data-approval-form class="charge-form"><p>La solicitud queda registrada en el borrador hasta que un owner o admin la resuelva.</p><label>Motivo<input name="reason" maxlength="500" placeholder="Describe por qué requiere aprobación"></label><p class="form-error" data-approval-error hidden></p><div class="modal-actions"><button class="secondary-button modal-close" type="button">Cerrar</button><button class="primary-button" type="submit">Solicitar aprobación</button></div></form></section></div>`);layer=document.querySelector('[data-modal-layer="sale-approval"]')}openModal('sale-approval');refreshSaleApprovals().catch(error=>notify(error.message))}
async function openOpticalExam(){const customerId=document.querySelector('[data-sale-customer]')?.dataset.customerId;if(!customerId)throw new Error('Selecciona un cliente antes de registrar su receta óptica.');ensureOpticalExamModal();const form=document.querySelector('[data-optical-exam-form]');form.reset();const result=await apiRequest(`/customers/${customerId}/clinical-data`),exam=result.data?.opticalExam||{};for(const eye of ['right','left'])for(const field of ['sphere','cylinder','axis','addition'])form.elements[eye+field[0].toUpperCase()+field.slice(1)].value=exam[eye]?.[field]||'';form.elements.pupillaryDistance.value=exam.pupillaryDistance||'';form.elements.notes.value=exam.notes||'';form.dataset.customerId=String(customerId);openModal('optical-exam')}
document.addEventListener('submit',async event=>{const approval=event.target.closest('[data-approval-form]');if(approval){event.preventDefault();const error=approval.querySelector('[data-approval-error]');error.hidden=true;try{await apiRequest(`/documents/${activeSaleDraftId}/approval-request`,{method:'POST',body:JSON.stringify({reason:approval.elements.reason.value})});approval.elements.reason.value='';notify('Solicitud de visto bueno registrada en el borrador.');await refreshSaleApprovals()}catch(failure){error.textContent=failure.message;error.hidden=false}return}const relation=event.target.closest('[data-relation-form]');if(relation){event.preventDefault();const error=relation.querySelector('[data-relation-error]');try{await apiRequest(`/documents/${activeSaleDraftId}/relations`,{method:'POST',body:JSON.stringify({targetDocumentId:relation.elements.targetDocumentId.value})});notify('Documento asociado');openSaleRelations()}catch(failure){error.textContent=failure.message;error.hidden=false}return}const form=event.target.closest('[data-optical-exam-form]');if(!form)return;event.preventDefault();const value=name=>form.elements[name].value.trim(),error=form.querySelector('[data-optical-error]');const opticalExam={right:{sphere:value('rightSphere'),cylinder:value('rightCylinder'),axis:value('rightAxis'),addition:value('rightAddition')},left:{sphere:value('leftSphere'),cylinder:value('leftCylinder'),axis:value('leftAxis'),addition:value('leftAddition')},pupillaryDistance:value('pupillaryDistance'),notes:value('notes')};try{await apiRequest(`/customers/${form.dataset.customerId}/clinical-data`,{method:'PUT',body:JSON.stringify({opticalExam})});closeModal(form.closest('.modal-backdrop'));notify('Receta óptica guardada en la ficha del cliente.')}catch(failure){error.textContent=failure.message;error.hidden=false}});

document.addEventListener('click', event => {
  if (event.target.closest('[data-save-draft]')) {
    event.preventDefault(); event.stopImmediatePropagation();
    saveSaleDraft().catch(error => notify(error.message)); return;
  }
}, true);

document.addEventListener('click', event => {
  const addLine = event.target.closest('[data-add-sale-line]');
  if (addLine) { document.querySelector('.ticket-lines tbody')?.insertAdjacentHTML('beforeend', saleLineMarkup(document.querySelectorAll('[data-sale-line]').length + 1)); document.querySelectorAll('[data-product-search]')?.[document.querySelectorAll('[data-product-search]').length - 1]?.focus(); return; }
  const remove = event.target.closest('[data-remove-sale-line]');
  if (remove) { const rows = document.querySelectorAll('[data-sale-line]'); if (rows.length === 1) updateSaleLine(remove.closest('tr'), null, false); else remove.closest('tr').remove(); recalcTicket(); return; }
  const unlink = event.target.closest('[data-unlink-relation]'); if (unlink) { unlinkSaleRelation(unlink.dataset.unlinkRelation).catch(error=>notify(error.message)); return; }
  const tool = event.target.closest('[data-sale-tool]'); if (tool) { if(tool.dataset.saleTool==='stock')openSaleStock();else if(tool.dataset.saleTool==='optical')openOpticalExam().catch(error=>notify(error.message));else if(tool.dataset.saleTool==='documents')openSaleRelations().catch(error=>notify(error.message));else openSaleApproval();return; }
  if (event.target.closest('[data-create-term]')) { ensurePaymentTermModal(); openModal('payment-term-form'); return; }
  if (event.target.closest('[data-create-seller]')) { ensureSellerModal(); openModal('seller-form'); return; }
  if (event.target.closest('[data-company-settings]')) { openCompanySettings().catch(error => notify(error.message)); return; }
  if (event.target.closest('[data-add-charge-row]')) { const methods=[...document.querySelectorAll('[data-charge-methods] option')].map(option=>({id:option.value,name:option.textContent})); document.querySelector('[data-charge-entries]')?.insertAdjacentHTML('beforeend',paymentEntryMarkup(methods,'')); return; }
  if (event.target.closest('[data-remove-charge-row]')) { event.target.closest('.sale-payment-entry')?.remove(); return; }
  if (event.target.closest('[data-close-sale]')) {
    activeSaleDraftId=null;
    const currentType = window._nextSaleType || 'BOL';
    const typeCfg = SALE_TYPES[currentType] || SALE_TYPES.BOL;
    location.hash = typeCfg.route;
    return;
  }
  const resume=event.target.closest('[data-resume-sale]'); if(resume){activeSaleDraftId=Number(resume.dataset.resumeSale);location.hash='nueva-boleta';return;}
  const print=event.target.closest('[data-print-sale]'); if(print){printSaleDocument(Number(print.dataset.printSale)).catch(error=>notify(error.message));return;}
  const payment=event.target.closest('[data-later-payment]'); if(payment){openLaterPayment(Number(payment.dataset.laterPayment),Number(payment.dataset.pending));return;}
}, true);

async function openLaterPayment(documentId, pending) {
  ensureLaterPaymentModal();
  try {
    const methods=await apiRequest('/payment-methods');
    const form=document.querySelector('[data-later-payment-form]');
    form.elements.documentId.value=documentId; form.elements.amount.max=String(Math.round(pending)); form.elements.amount.value=String(Math.round(pending));
    form.querySelector('[data-later-method]').innerHTML=methods.items.map(item=>`<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
    openModal('later-payment');
  } catch(error) { notify(error.message); }
}

document.addEventListener('submit', async event => {
  const companyForm=event.target.closest('[data-company-form]');
  if(companyForm){
    event.preventDefault();const error=companyForm.querySelector('[data-company-error]');
    try{await apiRequest('/company',{method:'PUT',body:JSON.stringify({name:companyForm.elements.name.value,legalName:companyForm.elements.legalName.value,taxId:companyForm.elements.taxId.value,branchId:companyForm.elements.branchId.value||null,branchAddress:companyForm.elements.branchAddress.value})});closeModal(companyForm.closest('.modal-backdrop'));notify('Datos del emisor actualizados.');}
    catch(failure){error.textContent=failure.message;error.hidden=false;}return;
  }
  const sellerForm=event.target.closest('[data-seller-form]');
  if(sellerForm){
    event.preventDefault();const error=sellerForm.querySelector('[data-seller-error]');
    try{const result=await apiRequest('/sellers',{method:'POST',body:JSON.stringify({name:sellerForm.elements.name.value})});const select=document.querySelector('[data-sale-seller]');select.insertAdjacentHTML('beforeend',`<option value="${escapeHtml(result.seller.id)}">${escapeHtml(result.seller.name)}</option>`);select.value=String(result.seller.id);closeModal(sellerForm.closest('.modal-backdrop'));notify('Agente de venta guardado.');}
    catch(failure){error.textContent=failure.message;error.hidden=false;}return;
  }
  const termForm=event.target.closest('[data-payment-term-form]');
  if(termForm){
    event.preventDefault();const error=termForm.querySelector('[data-payment-term-error]');
    try{const result=await apiRequest('/payment-terms',{method:'POST',body:JSON.stringify({name:termForm.elements.name.value,days:Number(termForm.elements.days.value)})});const select=document.querySelector('[data-sale-term]');select.insertAdjacentHTML('beforeend',`<option value="${escapeHtml(result.paymentTerm.id)}" data-days="${Number(result.paymentTerm.days)}">${escapeHtml(result.paymentTerm.name)}</option>`);select.value=String(result.paymentTerm.id);closeModal(termForm.closest('.modal-backdrop'));notify('Condición de pago guardada.');}
    catch(failure){error.textContent=failure.message;error.hidden=false;}return;
  }
  const form=event.target.closest('[data-later-payment-form]'); if(!form)return;
  event.preventDefault();
  const error=form.querySelector('[data-later-error]');
  try {
    const result=await apiRequest('/payments',{method:'POST',body:JSON.stringify({documentId:Number(form.elements.documentId.value),paymentMethodId:Number(form.elements.paymentMethodId.value),amount:Number(form.elements.amount.value),reference:form.elements.reference.value,notes:form.elements.notes.value})});
    closeModal(form.closest('.modal-backdrop')); notify(`Abono registrado. Saldo pendiente: ${clp(result.document.pending)}`);
    if (typeof hydrateDocuments === 'function') hydrateDocuments(window._nextSaleType || 'BOL');
    else hydrateBoletas();
  } catch(failure) { error.textContent=failure.message; error.hidden=false; }
});

document.addEventListener('change', event => {
  if(event.target.matches('[data-sale-price-list]')) {
    const priceList=event.target.value;
    [...document.querySelectorAll('[data-sale-line]')].forEach(async row=>{
      const input=row.querySelector('[data-product-search]'); if(!input?.dataset.productId)return;
      try { const result=await apiRequest(`/products?limit=50&priceList=${encodeURIComponent(priceList)}&q=${encodeURIComponent(input.value)}`); const product=result.items.find(item=>Number(item.id)===Number(input.dataset.productId)); if(product)updateSaleLine(row,product,true); } catch(error) { notify(error.message); }
    });
  }
});

document.addEventListener('focusin', event => {
  const input=event.target.closest('[data-product-search]'); if(!input)return;
  activeProductSearchInput=input;
  clearTimeout(productLookupTimer);
  productLookupTimer=setTimeout(()=>searchTicketProducts(input),80);
});

async function searchTicketProducts(input) {
  activeProductSearchInput=input;
  const lookup=ensureProductLookup();
  input.setAttribute('aria-expanded','true'); lookup.innerHTML='<div class="product-lookup-status">Buscando artículos…</div>'; lookup.hidden=false; positionProductLookup();
  try {
    const priceList=document.querySelector('[data-sale-price-list]')?.value||'general';
    const result=await apiRequest(`/products?limit=20&priceList=${encodeURIComponent(priceList)}&q=${encodeURIComponent(input.value.trim())}`);
    if(activeProductSearchInput!==input)return;
    lookup.innerHTML='<div class="product-lookup-head"><span>Ref.</span><span>Cod. barras</span><span>Descripción</span><span>PVP</span><span>Stock</span></div>'+result.items.map(item=>`<button type="button" role="option" class="product-lookup-row" data-product-result data-id="${escapeHtml(item.id)}" data-name="${escapeHtml(item.name)}" data-description="${escapeHtml(item.description||item.name)}" data-sku="${escapeHtml(item.sku)}" data-price="${Number(item.price_amount||0)}" data-stock="${Number(item.stock_quantity||0)}" data-tax-rate="${Number(item.tax_rate||19)}" data-product-type="${escapeHtml(item.product_type)}"><span>—</span><span>${escapeHtml(item.sku)}</span><span>${escapeHtml(item.description||item.name)}</span><span>${Number(item.price_amount||0).toLocaleString('es-CL')}</span><span>${Number(item.stock_quantity||0).toLocaleString('es-CL')}</span></button>`).join('')||'<div class="product-lookup-status">No se encontraron productos con esa búsqueda.</div>';
    positionProductLookup();
  } catch(error) { if(activeProductSearchInput===input){lookup.innerHTML=`<div class="product-lookup-status">${escapeHtml(error.message)}</div>`;positionProductLookup();} }
}

document.addEventListener('click', event => {
  const result=event.target.closest('[data-product-result]');
  if(result && activeProductSearchInput) {
    activeProductSearchInput.dataset.taxRate=result.dataset.taxRate;
    activeProductSearchInput.dataset.productType=result.dataset.productType;
    updateSaleLine(activeProductSearchInput.closest('tr'),{id:result.dataset.id,name:result.dataset.name,description:result.dataset.description,sku:result.dataset.sku,price_amount:result.dataset.price,stock_quantity:result.dataset.stock,tax_rate:result.dataset.taxRate,product_type:result.dataset.productType},true);
  }
}, true);

document.addEventListener('input', event => { if(event.target.matches('[data-line-qty],[data-line-dto]')) recalcTicket(); });
window.addEventListener('hashchange',()=>{
  const route = location.hash.slice(1);
  if(typeof docTypeMap!=='undefined' && docTypeMap[route] && typeof hydrateDocuments==='function') {
    hydrateDocuments(docTypeMap[route]);
  }
});

if (typeof render === 'function') {
  if (location.hash === '#nueva-boleta') { activeSaleDraftId=null; render('nueva-boleta'); }
  const currentDocType = (typeof docTypeMap !== 'undefined' && docTypeMap[location.hash.slice(1)]);
  if (currentDocType && typeof hydrateDocuments === 'function') hydrateDocuments(currentDocType);
}
document.addEventListener('click', event => {
  const decision = event.target.closest('[data-approval-decide]');
  if (!decision) return;
  apiRequest(`/document-approvals/${decision.dataset.approvalId}/decision`, {method:'POST', body:JSON.stringify({decision:decision.dataset.approvalDecide})})
    .then(() => { notify('Visto bueno actualizado.'); return refreshSaleApprovals(); })
    .catch(error => notify(error.message));
});
