(() => {
  // Reportes y sugerencias: lanzador flotante junto a la esquina inferior derecha. El formulario tiene dos pestañas (problema o sugerencia); el historial es solo para owner y admin.
  const API = './api';
  const MAX_DESCRIPTION = 2000;
  const MAX_FILE_BYTES = 5 * 1024 * 1024;
  const ALLOWED_FILES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
  const STATUSES = [['new', 'Nuevo'], ['in_review', 'En revisión'], ['resolved', 'Resuelto']];
  const TYPES = { report: 'Reporte', suggestion: 'Sugerencia' };
  const state = { role: '', view: 'form', type: 'report', filter: '' };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const dateTime = value => value ? new Date(String(value).replace(' ', 'T')).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' }) : '—';
  const fileSize = bytes => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  const sendJson = (method, data) => ({ method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
  const canReview = () => state.role === 'owner' || state.role === 'admin';

  async function api(path, options = {}) {
    const response = await fetch(API + path, { credentials: 'same-origin', ...options });
    const result = await response.json().catch(() => ({ ok: false, error: 'Respuesta inválida' }));
    if (!response.ok || result.ok === false) throw new Error(result.error || 'No se pudo completar la operación.');
    return result;
  }
  function notifyFeedback(message) {
    if (typeof window.notify === 'function') window.notify(message);
    else { const toast = document.querySelector('#toast'); if (toast) { toast.textContent = message; toast.classList.add('is-visible'); setTimeout(() => toast.classList.remove('is-visible'), 3000); } }
  }

  const root = document.createElement('div');
  root.className = 'feedback-root';
  root.innerHTML = `<section class="feedback-card" role="dialog" aria-labelledby="feedback-title" hidden>
    <header class="feedback-head"><h2 id="feedback-title">Reportes y Sugerencias</h2><span class="feedback-head-actions"><button type="button" class="feedback-link-button" data-feedback-view-toggle hidden>Historial</button><button type="button" class="feedback-icon" data-feedback-close aria-label="Cerrar">×</button></span></header>
    <div class="feedback-body" data-feedback-body></div>
  </section>
  <div class="feedback-launcher"><button type="button" class="feedback-pill" data-feedback-toggle>📢 Reportar o Sugerir</button><button type="button" class="feedback-round" data-feedback-toggle aria-label="Reportar o sugerir">💬</button></div>`;
  document.body.append(root);
  const card = root.querySelector('.feedback-card');
  const body = root.querySelector('[data-feedback-body]');
  const viewToggle = root.querySelector('[data-feedback-view-toggle]');

  function close() { card.hidden = true; }

  async function open() {
    card.hidden = false;
    try { state.role = (await api('/auth/status')).user?.role || ''; } catch (error) { state.role = ''; }
    viewToggle.hidden = !canReview();
    if (!canReview()) state.view = 'form';
    render();
  }

  function render() {
    card.classList.toggle('is-wide', state.view === 'history');
    viewToggle.textContent = state.view === 'history' ? 'Nuevo reporte' : 'Historial';
    if (state.view === 'history') loadHistory(); else renderForm();
  }

  function renderForm() {
    if (state.role === 'viewer') {
      body.innerHTML = '<p class="feedback-notice">Tu rol de consulta no puede enviar reportes. Pide a un administrador que lo revise.</p>';
      return;
    }
    body.innerHTML = `<div class="feedback-tabs" role="tablist">
      <button type="button" role="tab" data-feedback-type="report" class="${state.type === 'report' ? 'is-active' : ''}" aria-selected="${state.type === 'report'}"><span aria-hidden="true">⚠</span>Reportar un problema</button>
      <button type="button" role="tab" data-feedback-type="suggestion" class="${state.type === 'suggestion' ? 'is-active' : ''}" aria-selected="${state.type === 'suggestion'}"><span aria-hidden="true">💡</span>Enviar sugerencia</button>
    </div>
    <form class="feedback-form" data-feedback-form>
      <input type="hidden" name="type" value="${state.type}">
      <textarea name="description" maxlength="${MAX_DESCRIPTION}" required placeholder="Escribe aquí tu reporte o sugerencia..." aria-label="Descripción"></textarea>
      <small data-feedback-count>0 / ${MAX_DESCRIPTION}</small>
      <label class="feedback-attach">Adjuntar captura o archivo<input type="file" name="attachment" accept="${ALLOWED_FILES.join(',')}"></label>
      <p class="feedback-file-name" data-feedback-file hidden></p>
      <input type="email" name="contactEmail" maxlength="190" placeholder="Correo electrónico (opcional)" aria-label="Correo electrónico opcional">
      <p class="feedback-error" data-feedback-error hidden></p>
      <div class="feedback-actions"><button type="submit" class="primary-button">Enviar</button><button type="button" class="secondary-button" data-feedback-close>Cerrar</button></div>
    </form>`;
    body.querySelector('textarea')?.focus();
  }

  function setType(type) {
    state.type = type;
    body.querySelectorAll('[data-feedback-type]').forEach(button => {
      const active = button.dataset.feedbackType === type;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
    });
    const hidden = body.querySelector('input[name="type"]');
    if (hidden) hidden.value = type;
  }

  async function submitReport(form) {
    const error = form.querySelector('[data-feedback-error]');
    const submit = form.querySelector('[type="submit"]');
    const description = form.elements.description.value.trim();
    const file = form.elements.attachment.files[0];
    const contactEmail = form.elements.contactEmail.value.trim();
    const problem = !description ? 'Escribe el motivo del reporte o sugerencia.'
      : file && file.size > MAX_FILE_BYTES ? 'El adjunto supera el máximo de 5 MB.'
      : file && !ALLOWED_FILES.includes(file.type) ? 'El adjunto debe ser una imagen JPG, PNG o WebP, o un PDF.'
      : contactEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactEmail) ? 'Ingresa un correo válido o déjalo vacío.'
      : '';
    if (problem) { error.textContent = problem; error.hidden = false; return; }
    const data = new FormData();
    data.append('type', form.elements.type.value);
    data.append('description', description);
    data.append('contactEmail', contactEmail);
    if (file) data.append('attachment', file);
    submit.disabled = true;
    try {
      await api('/reports', { method: 'POST', body: data });
      close();
      state.type = 'report';
      notifyFeedback('Gracias, tu reporte quedó guardado.');
    } catch (failure) {
      error.textContent = failure.message;
      error.hidden = false;
    } finally {
      submit.disabled = false;
    }
  }

  function reportRow(report) {
    const attachment = report.has_attachment
      ? `<a class="feedback-link" href="${API}/reports/${Number(report.id)}/attachment">${esc(report.attachment_name)} (${fileSize(Number(report.attachment_size))})</a>`
      : '—';
    return `<tr>
      <td>${esc(dateTime(report.created_at))}</td>
      <td><span class="feedback-type feedback-type-${esc(report.type)}">${esc(TYPES[report.type] || report.type)}</span></td>
      <td class="feedback-text">${esc(report.description)}</td>
      <td>${esc(report.reporter_name || '—')}</td>
      <td>${esc(report.contact_email || '—')}</td>
      <td>${attachment}</td>
      <td><select class="feedback-status-select" data-feedback-status="${Number(report.id)}" data-current="${esc(report.status)}" aria-label="Estado del reporte">${STATUSES.map(([value, label]) => `<option value="${value}" ${value === report.status ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
    </tr>`;
  }

  async function loadHistory() {
    body.innerHTML = `<div class="feedback-history">
      <div class="feedback-toolbar">
        <label>Estado<select data-feedback-filter><option value="">Todos</option>${STATUSES.map(([value, label]) => `<option value="${value}" ${state.filter === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
        <button type="button" class="secondary-button" data-feedback-refresh>Actualizar</button>
      </div>
      <p class="feedback-error" data-feedback-error hidden></p>
      <div class="feedback-table-wrap"><table class="feedback-table">
        <thead><tr><th>Fecha y hora</th><th>Tipo</th><th>Motivo</th><th>Usuario</th><th>Correo</th><th>Adjunto</th><th>Estado</th></tr></thead>
        <tbody data-feedback-rows><tr><td colspan="7" class="feedback-empty">Cargando…</td></tr></tbody>
      </table></div>
    </div>`;
    const rows = body.querySelector('[data-feedback-rows]');
    const error = body.querySelector('[data-feedback-error]');
    try {
      const result = await api(`/reports${state.filter ? `?status=${encodeURIComponent(state.filter)}` : ''}`);
      rows.innerHTML = result.items.length ? result.items.map(reportRow).join('') : '<tr><td colspan="7" class="feedback-empty">No hay reportes para este filtro.</td></tr>';
    } catch (failure) {
      rows.innerHTML = '<tr><td colspan="7" class="feedback-empty">No se pudo cargar el historial.</td></tr>';
      error.textContent = failure.message;
      error.hidden = false;
    }
  }

  root.addEventListener('click', event => {
    if (event.target.closest('[data-feedback-toggle]')) {
      if (card.hidden) open(); else close();
      return;
    }
    if (event.target.closest('[data-feedback-close]')) { close(); return; }
    if (event.target.closest('[data-feedback-view-toggle]')) {
      state.view = state.view === 'history' ? 'form' : 'history';
      render();
      return;
    }
    const type = event.target.closest('[data-feedback-type]');
    if (type) { setType(type.dataset.feedbackType); return; }
    if (event.target.closest('[data-feedback-refresh]')) loadHistory();
  });

  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !card.hidden) close(); });

  root.addEventListener('input', event => {
    if (event.target.matches('textarea[name="description"]')) {
      event.target.form.querySelector('[data-feedback-count]').textContent = `${event.target.value.length} / ${MAX_DESCRIPTION}`;
    }
  });

  root.addEventListener('change', async event => {
    if (event.target.matches('input[name="attachment"]')) {
      const label = body.querySelector('[data-feedback-file]');
      const file = event.target.files[0];
      label.textContent = file ? `Adjunto: ${file.name}` : '';
      label.hidden = !file;
      return;
    }
    if (event.target.matches('[data-feedback-filter]')) {
      state.filter = event.target.value;
      loadHistory();
      return;
    }
    if (event.target.matches('[data-feedback-status]')) {
      const select = event.target;
      try {
        await api(`/reports/${Number(select.dataset.feedbackStatus)}/status`, sendJson('PUT', { status: select.value }));
        select.dataset.current = select.value;
        notifyFeedback('Estado actualizado.');
      } catch (failure) {
        select.value = select.dataset.current;
        notifyFeedback(failure.message);
      }
    }
  });

  root.addEventListener('submit', event => {
    const form = event.target.closest('[data-feedback-form]');
    if (form) { event.preventDefault(); submitReport(form); }
  });
})();
