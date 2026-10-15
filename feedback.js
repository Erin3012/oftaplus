(() => {
  // Reportes y sugerencias: botón en la barra superior, formulario para todos los roles que escriben y historial solo para owner/admin.
  const API = './api';
  const MAX_DESCRIPTION = 2000;
  const MAX_FILE_BYTES = 5 * 1024 * 1024;
  const ALLOWED_FILES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
  const STATUSES = [['new', 'Nuevo'], ['in_review', 'En revisión'], ['resolved', 'Resuelto']];
  const TYPES = { report: 'Reporte', suggestion: 'Sugerencia' };
  const state = { role: '', tab: 'new', filter: '' };
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

  const trigger = document.createElement('button');
  trigger.type = 'button';
  trigger.className = 'top-action feedback-trigger';
  trigger.setAttribute('aria-label', 'Reportes y sugerencias');
  trigger.innerHTML = '<span aria-hidden="true">✎</span> <span class="feedback-trigger-label">Reportes y sugerencias</span>';
  const topbarActions = document.querySelector('.topbar-actions');
  const userWrap = topbarActions?.querySelector('.auth-user-wrap');
  if (userWrap) userWrap.before(trigger); else topbarActions?.append(trigger);

  const layer = document.createElement('div');
  layer.className = 'feedback-backdrop';
  layer.hidden = true;
  layer.innerHTML = `<section class="feedback-modal" role="dialog" aria-modal="true" aria-labelledby="feedback-title">
    <header class="feedback-head"><h2 id="feedback-title">Reportes y sugerencias</h2><button type="button" class="icon-button" data-feedback-close aria-label="Cerrar">×</button></header>
    <nav class="feedback-tabs" role="tablist" hidden><button type="button" role="tab" data-feedback-tab="new" aria-selected="true">Nuevo reporte</button><button type="button" role="tab" data-feedback-tab="history" aria-selected="false">Historial</button></nav>
    <div class="feedback-body" data-feedback-body></div>
  </section>`;
  document.body.append(layer);
  const tabs = layer.querySelector('.feedback-tabs');
  const body = layer.querySelector('[data-feedback-body]');

  function close() { layer.hidden = true; }

  function showTab(tab) {
    state.tab = tab;
    tabs.querySelectorAll('[data-feedback-tab]').forEach(button => {
      const active = button.dataset.feedbackTab === tab;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', String(active));
    });
    if (tab === 'history') loadHistory(); else renderForm();
  }

  async function open() {
    layer.hidden = false;
    try { state.role = (await api('/auth/status')).user?.role || ''; } catch (error) { state.role = ''; }
    tabs.hidden = !canReview();
    showTab(canReview() ? state.tab : 'new');
  }

  function renderForm() {
    if (state.role === 'viewer') {
      body.innerHTML = '<p class="feedback-notice">Tu rol de consulta no puede enviar reportes. Pide a un administrador que lo revise.</p>';
      return;
    }
    body.innerHTML = `<form class="feedback-form" data-feedback-form>
      <label class="feedback-field">Tipo<select name="type"><option value="report">Reporte de un problema</option><option value="suggestion">Sugerencia</option></select></label>
      <label class="feedback-field">Motivo<textarea name="description" rows="6" maxlength="${MAX_DESCRIPTION}" required placeholder="Qué pasó, dónde y qué esperabas, o qué mejorarías."></textarea><small data-feedback-count>0 / ${MAX_DESCRIPTION}</small></label>
      <label class="feedback-field">Adjunto <span>(opcional)</span><input type="file" name="attachment" accept="${ALLOWED_FILES.join(',')}"><small>JPG, PNG, WebP o PDF. Máximo 5 MB.</small></label>
      <p class="feedback-error" data-feedback-error hidden></p>
      <div class="feedback-actions"><button type="submit" class="primary-button">Enviar</button></div>
    </form>`;
    body.querySelector('textarea')?.focus();
  }

  async function submitReport(form) {
    const error = form.querySelector('[data-feedback-error]');
    const submit = form.querySelector('[type="submit"]');
    const description = form.elements.description.value.trim();
    const file = form.elements.attachment.files[0];
    const problem = !description ? 'Escribe el motivo del reporte o sugerencia.'
      : file && file.size > MAX_FILE_BYTES ? 'El adjunto supera el máximo de 5 MB.'
      : file && !ALLOWED_FILES.includes(file.type) ? 'El adjunto debe ser una imagen JPG, PNG o WebP, o un PDF.'
      : '';
    if (problem) { error.textContent = problem; error.hidden = false; return; }
    const data = new FormData();
    data.append('type', form.elements.type.value);
    data.append('description', description);
    if (file) data.append('attachment', file);
    submit.disabled = true;
    try {
      await api('/reports', { method: 'POST', body: data });
      close();
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
      <td>${attachment}</td>
      <td><select class="feedback-status-select" data-feedback-status="${Number(report.id)}" data-current="${esc(report.status)}" aria-label="Estado del reporte">${STATUSES.map(([value, label]) => `<option value="${value}" ${value === report.status ? 'selected' : ''}>${label}</option>`).join('')}</select></td>
    </tr>`;
  }

  async function loadHistory() {
    body.innerHTML = `<div class="feedback-toolbar">
      <label class="feedback-field">Estado<select data-feedback-filter><option value="">Todos</option>${STATUSES.map(([value, label]) => `<option value="${value}" ${state.filter === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>
      <button type="button" class="secondary-button" data-feedback-refresh>Actualizar</button>
    </div>
    <p class="feedback-error" data-feedback-error hidden></p>
    <div class="feedback-table-wrap"><table class="feedback-table">
      <thead><tr><th>Fecha y hora</th><th>Tipo</th><th>Motivo</th><th>Usuario</th><th>Adjunto</th><th>Estado</th></tr></thead>
      <tbody data-feedback-rows><tr><td colspan="6" class="feedback-empty">Cargando…</td></tr></tbody>
    </table></div>`;
    const rows = body.querySelector('[data-feedback-rows]');
    const error = body.querySelector('[data-feedback-error]');
    try {
      const result = await api(`/reports${state.filter ? `?status=${encodeURIComponent(state.filter)}` : ''}`);
      rows.innerHTML = result.items.length ? result.items.map(reportRow).join('') : '<tr><td colspan="6" class="feedback-empty">No hay reportes para este filtro.</td></tr>';
    } catch (failure) {
      rows.innerHTML = '<tr><td colspan="6" class="feedback-empty">No se pudo cargar el historial.</td></tr>';
      error.textContent = failure.message;
      error.hidden = false;
    }
  }

  trigger.addEventListener('click', open);
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !layer.hidden) close(); });

  layer.addEventListener('click', event => {
    if (event.target === layer || event.target.closest('[data-feedback-close]')) close();
    const tab = event.target.closest('[data-feedback-tab]');
    if (tab) showTab(tab.dataset.feedbackTab);
    if (event.target.closest('[data-feedback-refresh]')) loadHistory();
  });

  layer.addEventListener('input', event => {
    if (event.target.matches('textarea[name="description"]')) {
      event.target.form.querySelector('[data-feedback-count]').textContent = `${event.target.value.length} / ${MAX_DESCRIPTION}`;
    }
  });

  layer.addEventListener('change', async event => {
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

  layer.addEventListener('submit', event => {
    const form = event.target.closest('[data-feedback-form]');
    if (form) { event.preventDefault(); submitReport(form); }
  });
})();
