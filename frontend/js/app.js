/* Clínica Vida Salud — frontend (HTML5 + CSS3 + JavaScript puro) */
const $ = (s, r = document) => r.querySelector(s);
const view = $('#view');
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const hm = t => (t || '').slice(0, 5);
const fdate = d => { if (!d) return ''; const [y, m, dd] = String(d).slice(0, 10).split('-'); return `${dd}/${m}/${y}`; };
const ftime = d => d ? fdate(d) + ' ' + String(d).slice(11, 16) : '';
const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
const today = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 6e4).toISOString().slice(0, 10); };
const badge = e => `<span class="badge ${esc(e)}">${esc(e)}</span>`;
const onoff = b => `<span class="badge ${b ? 'on' : 'off'}">${b ? 'Activo' : 'Inactivo'}</span>`;
const debounce = (fn, ms = 350) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
let me = null;

function toast(msg, type = 'ok') {
  const t = document.createElement('div'); t.className = 'toast ' + type; t.textContent = msg;
  $('#toasts').append(t); setTimeout(() => t.remove(), 4200);
}
const qs = o => { const p = new URLSearchParams(); Object.entries(o).forEach(([k, v]) => v !== '' && v != null && p.set(k, v)); const s = p.toString(); return s ? '?' + s : ''; };

/* ---------- Componentes de formulario ---------- */
function fld(label, name, o = {}) {
  const { type = 'text', value = '', req = false, options = null, attrs = '', cls = '' } = o;
  let input;
  if (options) input = `<select name="${name}" ${req ? 'required' : ''} ${attrs}>${options.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  else if (type === 'textarea') input = `<textarea name="${name}" ${req ? 'required' : ''} ${attrs}>${esc(value)}</textarea>`;
  else input = `<input type="${type}" name="${name}" value="${esc(value)}" ${req ? 'required' : ''} ${attrs}>`;
  return `<label class="f ${cls}"><span>${esc(label)}${req ? ' *' : ''}</span>${input}</label>`;
}
const estadoOpts = [['true', 'Activo'], ['false', 'Inactivo']];

function modal(title, body, onSubmit, { ok = 'Guardar' } = {}) {
  const d = $('#dlg');
  d.innerHTML = `<form class="dlg-box" novalidate><h3>${esc(title)}</h3><div>${body}</div><p class="err" hidden></p>
    <div class="dlg-actions"><button type="button" class="btn ghost" data-close>${onSubmit ? 'Cancelar' : 'Cerrar'}</button>${onSubmit ? `<button class="btn" type="submit">${esc(ok)}</button>` : ''}</div></form>`;
  const form = $('form', d);
  $('[data-close]', d).onclick = () => d.close();
  form.onsubmit = async e => {
    e.preventDefault();
    if (!onSubmit) return d.close();
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const err = $('.err', d); err.hidden = true;
    try { await onSubmit(Object.fromEntries(new FormData(form)), form); d.close(); }
    catch (ex) { err.textContent = ex.message; err.hidden = false; }
  };
  d.showModal(); return d;
}
const alertBox = (m) => `<div class="card err-box">${esc(m)}</div>`;

/* ---------- Layout y navegación ---------- */
const MENU = [
  ['/', 'Inicio', null], ['/usuarios', 'Usuarios y roles', ['Administrador']], ['/especialidades', 'Especialidades', ['Administrador']],
  ['/medicos', 'Médicos', ['Administrador']], ['/horarios', 'Horarios', ['Administrador']],
  ['/pacientes', 'Pacientes', ['Administrador', 'Recepcionista']], ['/nueva-cita', 'Nueva cita', ['Administrador', 'Recepcionista', 'Paciente']],
  ['/citas', 'Citas', null]
];
function shell(path) {
  const top = $('#top'); top.hidden = !me; top.classList.remove('open');
  if (!me) return;
  $('#nav').innerHTML = MENU.filter(m => !m[2] || m[2].includes(me.rol))
    .map(([p, l]) => `<a href="#${p}" class="${p === path ? 'on' : ''}">${p === '/citas' && ['Paciente', 'Médico'].includes(me.rol) ? 'Mis citas' : l}</a>`).join('');
  $('#who').innerHTML = `<span>${esc(me.nombre_completo)} <small>(${esc(me.rol)})</small></span><button class="btn sm ghost" id="out">Salir</button>`;
  $('#out').onclick = logout;
}
$('#burger').onclick = () => $('#top').classList.toggle('open');

const R = {};
async function router() {
  const [path, q] = (location.hash.slice(1) || '/').split('?'); const params = new URLSearchParams(q || '');
  const pub = ['/login', '/registro', '/olvide', '/reset'];
  if (!me && sessionStorage.getItem(TOKEN_KEY)) { try { me = (await api('/api/me')).user; } catch { me = null; } }
  if (!me && !pub.includes(path)) { location.hash = '#/login'; return; }
  if (me && pub.includes(path) && path !== '/reset') { location.hash = '#/'; return; }
  const v = R[path] || R['/404'];
  if (v.roles && !v.roles.includes(me.rol)) { toast('No tiene permiso para esta sección', 'err'); location.hash = '#/'; return; }
  shell(path); view.innerHTML = '<p class="muted">Cargando…</p>';
  try { await v.render(params); } catch (e) { view.innerHTML = alertBox(e.message); }
}
window.addEventListener('hashchange', router); window.addEventListener('load', router);

/* ---------- CU-01 / CU-02 / CU-07: acceso público ---------- */
function authCard(title, html) { view.innerHTML = `<div class="card auth"><div class="hero">🩺</div><h2>${title}</h2>${html}</div>`; }
function bindForm(sel, fn) {
  const f = $(sel); f.setAttribute('novalidate', '');
  f.onsubmit = async e => {
    e.preventDefault(); const err = $('.err', f); err.hidden = true;
    if (!f.checkValidity()) { f.reportValidity(); return; }
    const btn = $('button[type=submit]', f); btn.disabled = true;
    try { await fn(Object.fromEntries(new FormData(f)), f); } catch (ex) { err.textContent = ex.message; err.hidden = false; } finally { btn.disabled = false; }
  };
}
async function entrar(r) { sessionStorage.setItem(TOKEN_KEY, r.token); me = r.user; location.hash = '#/'; router(); }

R['/login'] = { render() {
  authCard('Iniciar sesión', `<form id="f">${fld('Usuario o correo', 'identificador', { req: true, attrs: 'autocomplete="username"' })}
    ${fld('Contraseña', 'password', { type: 'password', req: true, attrs: 'autocomplete="current-password"' })}
    <p class="err" hidden></p><button class="btn" style="width:100%" type="submit">Ingresar</button></form>
    <p class="links"><a href="#/olvide">¿Olvidó su contraseña?</a> · <a href="#/registro">Crear cuenta de paciente</a></p>`);
  bindForm('#f', async d => entrar(await api('/api/auth/login', { method: 'POST', body: d })));
} };

const pacienteCampos = (p = {}) => `<div class="grid">
  ${fld('CI', 'ci', { value: p.ci, req: true, attrs: 'pattern="[0-9A-Za-z\\-]{5,20}" title="5 a 20 caracteres" maxlength="20"' })}
  ${fld('Nombres', 'nombres', { value: p.nombres, req: true, attrs: 'maxlength="80"' })}
  ${fld('Apellidos', 'apellidos', { value: p.apellidos, req: true, attrs: 'maxlength="80"' })}
  ${fld('Fecha de nacimiento', 'fecha_nacimiento', { type: 'date', value: (p.fecha_nacimiento || '').slice(0, 10), req: true, attrs: `max="${today()}"` })}
  ${fld('Sexo', 'sexo', { value: p.sexo || '', req: true, options: [['', 'Seleccione'], ['M', 'Masculino'], ['F', 'Femenino']] })}
  ${fld('Teléfono', 'telefono', { type: 'tel', value: p.telefono, req: true, attrs: 'pattern="[0-9+\\-\\s]{7,20}" title="Sólo números" maxlength="20"' })}
  ${fld('Correo', 'correo', { type: 'email', value: p.correo, attrs: 'maxlength="120"' })}
  ${fld('Dirección', 'direccion', { value: p.direccion, attrs: 'maxlength="200"' })}</div>`;

R['/registro'] = { render() {
  authCard('Crear cuenta de paciente', `<form id="f">${pacienteCampos().replace(/name="correo"/, 'name="correo" required')}
    ${fld('Contraseña (mín. 8, letras y números)', 'password', { type: 'password', req: true, attrs: 'minlength="8" autocomplete="new-password"' })}
    <p class="err" hidden></p><button class="btn" style="width:100%" type="submit">Registrarme</button></form>
    <p class="links">Su usuario será su CI. <a href="#/login">Volver</a></p>`);
  $('.auth').style.maxWidth = '640px';
  bindForm('#f', async d => entrar(await api('/api/auth/register', { method: 'POST', body: d })));
} };

R['/olvide'] = { render() {
  authCard('Recuperar contraseña', `<form id="f">${fld('Correo registrado', 'correo', { type: 'email', req: true })}
    <p class="err" hidden></p><button class="btn" style="width:100%" type="submit">Enviar enlace</button></form><div id="msg"></div>
    <p class="links"><a href="#/login">Volver</a></p>`);
  bindForm('#f', async d => {
    const r = await api('/api/auth/forgot', { method: 'POST', body: d });
    $('#msg').innerHTML = `<p class="muted">${esc(r.mensaje)}</p>` + (r.enlace_demo ? `<p><b>Modo demostración:</b> <a href="${esc(r.enlace_demo)}">abrir enlace de restablecimiento</a></p>` : '');
  });
} };

R['/reset'] = { render(p) {
  authCard('Nueva contraseña', `<form id="f"><input type="hidden" name="token" value="${esc(p.get('token'))}">
    ${fld('Nueva contraseña (mín. 8, letras y números)', 'password', { type: 'password', req: true, attrs: 'minlength="8"' })}
    ${fld('Repita la contraseña', 'password2', { type: 'password', req: true })}
    <p class="err" hidden></p><button class="btn" style="width:100%" type="submit">Guardar</button></form>`);
  bindForm('#f', async d => {
    if (d.password !== d.password2) throw new Error('Las contraseñas no coinciden');
    await api('/api/auth/reset', { method: 'POST', body: { token: d.token, password: d.password } });
    toast('Contraseña actualizada. Inicie sesión.'); location.hash = '#/login';
  });
} };
R['/404'] = { render() { view.innerHTML = '<div class="card">Página no encontrada. <a href="#/">Ir al inicio</a></div>'; } };

/* ---------- Inicio ---------- */
R['/'] = { async render() {
  const rows = await api('/api/citas' + qs({ proximas: 1 }));
  const acc = { Administrador: ['/usuarios|Usuarios y roles', '/medicos|Médicos', '/horarios|Horarios', '/nueva-cita|Programar cita'],
    Recepcionista: ['/pacientes|Pacientes', '/nueva-cita|Programar cita', '/citas|Citas'], 'Médico': ['/citas|Mis citas'], Paciente: ['/nueva-cita|Reservar cita', '/citas|Mis citas'] }[me.rol];
  view.innerHTML = `<h2>Bienvenido/a, ${esc(me.nombre_completo)}</h2>
    <div class="bar">${acc.map(a => { const [h, l] = a.split('|'); return `<a class="btn" href="#${h}" style="text-decoration:none">${l}</a>`; }).join('')}</div>
    <div class="card"><h3>Próximas citas</h3>${citasTabla(rows.slice(0, 10), false)}</div>`;
  bindCitaAcciones(() => router());
} };

/* ---------- CU-03 Usuarios y roles ---------- */
R['/usuarios'] = { roles: ['Administrador'], async render() {
  const [rows, roles] = await Promise.all([api('/api/usuarios'), api('/api/roles')]);
  view.innerHTML = `<h2>Usuarios y roles</h2><div class="bar"><span class="sp"></span><button class="btn" id="nuevo">+ Nuevo usuario</button></div>
    <div class="card tw"><table><thead><tr><th>Usuario</th><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th></th></tr></thead><tbody>
    ${rows.map(u => `<tr><td>${esc(u.username)}</td><td>${esc(u.nombre_completo)}</td><td>${esc(u.correo)}</td><td>${esc(u.rol)}</td><td>${onoff(u.estado)}</td><td class="act"><button class="btn sm ghost" data-e="${u.id_usuario}">Editar</button></td></tr>`).join('')}</tbody></table></div>`;
  const form = (u = null) => modal(u ? 'Editar usuario' : 'Nuevo usuario', `<div class="grid">
    ${fld('Nombre completo', 'nombre_completo', { value: u?.nombre_completo, req: true, attrs: 'maxlength="120"' })}
    ${fld('Usuario', 'username', { value: u?.username, req: true, attrs: 'pattern="[A-Za-z0-9._\\-]{3,40}" title="3 a 40: letras, números . _ -"' })}
    ${fld('Correo', 'correo', { type: 'email', value: u?.correo, req: true })}
    ${fld('Rol', 'id_rol', { value: u?.id_rol, req: true, options: roles.map(r => [r.id_rol, r.nombre]) })}
    ${fld(u ? 'Nueva contraseña (opcional)' : 'Contraseña inicial', 'password', { type: 'password', req: !u, attrs: 'minlength="8" autocomplete="new-password"' })}
    ${fld('Estado', 'estado', { value: String(u ? u.estado : true), options: estadoOpts })}</div>`,
    async d => { await api(u ? `/api/usuarios/${u.id_usuario}` : '/api/usuarios', { method: u ? 'PUT' : 'POST', body: d }); toast('Usuario guardado'); router(); });
  $('#nuevo').onclick = () => form();
  view.querySelectorAll('[data-e]').forEach(b => b.onclick = () => form(rows.find(r => r.id_usuario == b.dataset.e)));
} };

/* ---------- CU-04 Especialidades ---------- */
R['/especialidades'] = { roles: ['Administrador'], async render() {
  const rows = await api('/api/especialidades');
  view.innerHTML = `<h2>Especialidades médicas</h2><div class="bar"><span class="sp"></span><button class="btn" id="nuevo">+ Nueva especialidad</button></div>
    <div class="card tw"><table><thead><tr><th>Nombre</th><th>Descripción</th><th>Médicos activos</th><th>Estado</th><th></th></tr></thead><tbody>
    ${rows.map(e => `<tr><td>${esc(e.nombre)}</td><td>${esc(e.descripcion)}</td><td>${e.medicos_activos}</td><td>${onoff(e.estado)}</td><td class="act"><button class="btn sm ghost" data-e="${e.id_especialidad}">Editar</button></td></tr>`).join('')}</tbody></table></div>`;
  const form = (e = null) => modal(e ? 'Editar especialidad' : 'Nueva especialidad', `${fld('Nombre', 'nombre', { value: e?.nombre, req: true, attrs: 'maxlength="80"' })}
    ${fld('Descripción (opcional)', 'descripcion', { value: e?.descripcion, attrs: 'maxlength="200"' })}${fld('Estado', 'estado', { value: String(e ? e.estado : true), options: estadoOpts })}`,
    async d => {
      const send = x => api(e ? `/api/especialidades/${e.id_especialidad}` : '/api/especialidades', { method: e ? 'PUT' : 'POST', body: x });
      try { await send(d); } catch (ex) { if (ex.data?.advertencia && confirm(ex.message)) await send({ ...d, confirmar: true }); else throw ex; }
      toast('Especialidad guardada'); router();
    });
  $('#nuevo').onclick = () => form();
  view.querySelectorAll('[data-e]').forEach(b => b.onclick = () => form(rows.find(r => r.id_especialidad == b.dataset.e)));
} };

/* ---------- CU-05 Médicos ---------- */
R['/medicos'] = { roles: ['Administrador'], async render() {
  const esps = await api('/api/especialidades');
  view.innerHTML = `<h2>Médicos</h2><div class="bar"><input id="q" placeholder="Buscar por nombre o especialidad"><select id="fe"><option value="">Todas las especialidades</option>${esps.map(e => `<option value="${e.id_especialidad}">${esc(e.nombre)}</option>`).join('')}</select>
    <span class="sp"></span><button class="btn" id="nuevo">+ Nuevo médico</button></div><div class="card tw" id="tb"></div>`;
  let rows = [];
  const form = (m = null) => modal(m ? 'Editar médico' : 'Nuevo médico', `<div class="grid">
    ${fld('Nro. registro profesional', 'nro_registro', { value: m?.nro_registro, req: true, attrs: 'maxlength="30"' })}
    ${fld('Nombres', 'nombres', { value: m?.nombres, req: true, attrs: 'maxlength="80"' })}${fld('Apellidos', 'apellidos', { value: m?.apellidos, req: true, attrs: 'maxlength="80"' })}
    ${fld('Teléfono', 'telefono', { type: 'tel', value: m?.telefono, attrs: 'pattern="[0-9+\\-\\s]{7,20}"' })}${fld('Correo', 'correo', { type: 'email', value: m?.correo, req: true })}
    ${m ? '' : fld('Usuario de acceso', 'username', { req: true, attrs: 'pattern="[A-Za-z0-9._\\-]{3,40}"' }) + fld('Contraseña inicial', 'password', { type: 'password', req: true, attrs: 'minlength="8"' })}
    ${fld('Estado', 'estado', { value: String(m ? m.estado : true), options: estadoOpts })}</div>
    <p class="muted" style="margin:.2rem 0">Especialidades *</p><div class="checks">${esps.filter(e => e.estado || m?.ids_especialidad.includes(e.id_especialidad)).map(e => `<label><input type="checkbox" name="esp" value="${e.id_especialidad}" ${m?.ids_especialidad.includes(e.id_especialidad) ? 'checked' : ''}>${esc(e.nombre)}</label>`).join('')}</div>`,
    async (d, f) => {
      const especialidades = [...f.querySelectorAll('[name=esp]:checked')].map(c => +c.value);
      if (!especialidades.length) throw new Error('Seleccione al menos una especialidad');
      await api(m ? `/api/medicos/${m.id_medico}` : '/api/medicos', { method: m ? 'PUT' : 'POST', body: { ...d, especialidades } }); toast('Médico guardado'); load();
    });
  const load = async () => {
    rows = await api('/api/medicos' + qs({ q: $('#q').value.trim(), especialidad: $('#fe').value }));
    $('#tb').innerHTML = rows.length ? `<table><thead><tr><th>Código</th><th>Médico</th><th>Registro</th><th>Especialidades</th><th>Contacto</th><th>Estado</th><th></th></tr></thead><tbody>
      ${rows.map(m => `<tr><td>${esc(m.codigo)}</td><td>${esc(m.nombres + ' ' + m.apellidos)}</td><td>${esc(m.nro_registro)}</td><td>${esc(m.especialidades)}</td><td>${esc(m.telefono || m.correo)}</td><td>${onoff(m.estado)}</td>
      <td class="act"><button class="btn sm ghost" data-e="${m.id_medico}">Editar</button><a class="btn sm ghost" style="text-decoration:none" href="#/horarios?medico=${m.id_medico}">Horarios</a></td></tr>`).join('')}</tbody></table>` : '<p class="muted">No se encontraron médicos.</p>';
    view.querySelectorAll('[data-e]').forEach(b => b.onclick = () => form(rows.find(r => r.id_medico == b.dataset.e)));
  };
  $('#q').oninput = debounce(load); $('#fe').onchange = load; $('#nuevo').onclick = () => form(); await load();
} };

/* ---------- CU-06 Horarios de atención ---------- */
R['/horarios'] = { roles: ['Administrador'], async render(p) {
  const meds = await api('/api/medicos');
  if (!meds.length) { view.innerHTML = '<h2>Horarios de atención</h2><div class="card">Registre médicos primero.</div>'; return; }
  const sel = p.get('medico') || meds[0].id_medico;
  view.innerHTML = `<h2>Horarios de atención</h2><div class="card"><div class="grid">
    ${fld('Médico', 'm', { value: sel, options: meds.map(m => [m.id_medico, `${m.nombres} ${m.apellidos} — ${m.especialidades}`]) })}</div></div>
    <div class="card"><h3>Agregar horario</h3><form id="f"><div class="grid">
    ${fld('Día', 'dia_semana', { req: true, options: DIAS.slice(1).map((d, i) => [i + 1, d]) })}${fld('Hora inicial', 'hora_inicio', { type: 'time', req: true, value: '08:00' })}
    ${fld('Hora final', 'hora_fin', { type: 'time', req: true, value: '12:00' })}${fld('Duración de cada cita (min)', 'duracion_min', { type: 'number', req: true, value: 30, attrs: 'min="5" max="120" step="5"' })}</div>
    <p class="err" hidden></p><button class="btn" type="submit">Agregar</button></form></div><div class="card tw" id="tb"></div>`;
  const id = () => $('[name=m]').value;
  const load = async () => {
    const rows = await api(`/api/medicos/${id()}/horarios`);
    $('#tb').innerHTML = rows.length ? `<table><thead><tr><th>Día</th><th>Desde</th><th>Hasta</th><th>Duración</th><th>Cupos</th><th>Estado</th><th></th></tr></thead><tbody>
      ${rows.map(h => `<tr><td>${DIAS[h.dia_semana]}</td><td>${hm(h.hora_inicio)}</td><td>${hm(h.hora_fin)}</td><td>${h.duracion_min} min</td><td>${h.cupos}</td><td>${onoff(h.estado)}</td>
      <td><button class="btn sm ghost" data-h="${h.id_horario}" data-s="${h.estado}">${h.estado ? 'Desactivar' : 'Activar'}</button></td></tr>`).join('')}</tbody></table>` : '<p class="muted">Este médico aún no tiene horarios.</p>';
    view.querySelectorAll('[data-h]').forEach(b => b.onclick = async () => {
      try { await api(`/api/horarios/${b.dataset.h}`, { method: 'PATCH', body: { estado: b.dataset.s !== 'true' } }); load(); } catch (e) { toast(e.message, 'err'); }
    });
  };
  $('[name=m]').onchange = load;
  bindForm('#f', async d => {
    const r = await api('/api/horarios', { method: 'POST', body: { ...d, id_medico: +id() } }); toast(r.mensaje); load();
  });
  await load();
} };

/* ---------- CU-07 / CU-08 Pacientes ---------- */
R['/pacientes'] = { roles: ['Administrador', 'Recepcionista'], async render() {
  view.innerHTML = `<h2>Pacientes</h2><div class="bar"><input id="q" placeholder="Buscar por CI, nombre o apellido"><span class="sp"></span><button class="btn" id="nuevo">+ Registrar paciente</button></div><div class="card tw" id="tb"></div>`;
  let rows = [];
  const form = (p = null) => modal(p ? 'Modificar paciente' : 'Registrar paciente', pacienteCampos(p || {}),
    async d => { const r = await api(p ? `/api/pacientes/${p.id_paciente}` : '/api/pacientes', { method: p ? 'PUT' : 'POST', body: d }); toast(r.codigo ? `Paciente registrado (${r.codigo})` : r.mensaje); load(); });
  const load = async () => {
    rows = await api('/api/pacientes' + qs({ q: $('#q').value.trim() }));
    $('#tb').innerHTML = rows.length ? `<table><thead><tr><th>Código</th><th>CI</th><th>Paciente</th><th>Nacimiento</th><th>Teléfono</th><th>Estado</th><th></th></tr></thead><tbody>
      ${rows.map(p => `<tr><td>${esc(p.codigo)}</td><td>${esc(p.ci)}</td><td>${esc(p.nombres + ' ' + p.apellidos)}</td><td>${fdate(p.fecha_nacimiento)}</td><td>${esc(p.telefono)}</td><td>${onoff(p.estado)}</td>
      <td class="act"><button class="btn sm ghost" data-e="${p.id_paciente}">Modificar</button><button class="btn sm ${p.estado ? 'danger' : 'ghost'}" data-t="${p.id_paciente}">${p.estado ? 'Desactivar' : 'Activar'}</button></td></tr>`).join('')}</tbody></table>` : '<p class="muted">No se encontraron pacientes con ese criterio.</p>';
    view.querySelectorAll('[data-e]').forEach(b => b.onclick = () => form(rows.find(r => r.id_paciente == b.dataset.e)));
    view.querySelectorAll('[data-t]').forEach(b => b.onclick = async () => {
      const p = rows.find(r => r.id_paciente == b.dataset.t);
      if (p.estado && !confirm('¿Desactivar al paciente? Su historial de citas se conserva.')) return;
      try { await api(`/api/pacientes/${p.id_paciente}/estado`, { method: 'PATCH', body: { estado: !p.estado } }); load(); } catch (e) { toast(e.message, 'err'); }
    });
  };
  $('#q').oninput = debounce(load); $('#nuevo').onclick = () => form(); await load();
} };

/* ---------- CU-09 / CU-10 Disponibilidad y programación ---------- */
const slotsHtml = c => c.map(s => `<button type="button" class="slot" data-h="${hm(s.hora_inicio)}">${hm(s.hora_inicio)} – ${hm(s.hora_fin)}</button>`).join('');
R['/nueva-cita'] = { roles: ['Administrador', 'Recepcionista', 'Paciente'], async render() {
  const staff = me.rol !== 'Paciente';
  if (!staff && !me.id_paciente) { view.innerHTML = alertBox('Su cuenta no tiene un perfil de paciente asociado.'); return; }
  const esps = await api('/api/especialidades?activas=1');
  view.innerHTML = `<h2>Programar cita médica</h2><div class="card"><div class="grid">
    ${staff ? `<div class="f full"><span>Paciente *</span><div class="row"><input id="pq" placeholder="Buscar por CI, nombre o apellido" style="flex:1;padding:.55rem;border:1px solid var(--bd);border-radius:8px"><button class="btn ghost" type="button" id="pb">Buscar</button></div><select id="ps"><option value="">— busque y seleccione —</option></select></div>` : ''}
    ${fld('Especialidad', 'esp', { options: [['', 'Seleccione…'], ...esps.map(e => [e.id_especialidad, e.nombre])] })}
    ${fld('Médico', 'med', { options: [['', 'Seleccione una especialidad']] })}
    ${fld('Fecha', 'fecha', { type: 'date', attrs: `min="${today()}"` })}</div>
    <div id="slots" class="muted">Seleccione médico y fecha para ver los horarios libres.</div>
    ${fld('Motivo de la consulta (opcional)', 'motivo', { attrs: 'maxlength="200"' })}
    <p class="err" id="er" hidden></p><button class="btn" id="go" disabled>Confirmar cita</button></div><div id="comp"></div>`;
  let hora = '';
  const $n = n => $(`[name=${n}]`);
  const err = m => { const e = $('#er'); e.textContent = m || ''; e.hidden = !m; };
  const valid = () => { $('#go').disabled = !(hora && (!staff || $('#ps').value)); };
  const loadSlots = async () => {
    hora = ''; valid(); err('');
    if (!$n('med').value || !$n('fecha').value) return;
    try {
      const r = await api(`/api/disponibilidad?medico=${$n('med').value}&fecha=${$n('fecha').value}`);
      $('#slots').innerHTML = r.cupos.length ? `<p class="muted" style="margin:.2rem 0">Horarios disponibles:</p><div class="slots">${slotsHtml(r.cupos)}</div>` : `<p class="err">${esc(r.mensaje)}</p>`;
      $('#slots').querySelectorAll('.slot').forEach(b => b.onclick = () => { $('#slots').querySelectorAll('.slot').forEach(x => x.classList.remove('sel')); b.classList.add('sel'); hora = b.dataset.h; valid(); });
    } catch (e) { $('#slots').innerHTML = `<p class="err">${esc(e.message)}</p>`; }
  };
  $n('esp').onchange = async () => {
    const m = $n('med'); m.innerHTML = '<option value="">Seleccione…</option>'; if (!$n('esp').value) return;
    const ms = await api('/api/medicos' + qs({ especialidad: $n('esp').value, activos: 1 }));
    m.innerHTML = `<option value="">${ms.length ? 'Seleccione…' : 'Sin médicos disponibles'}</option>` + ms.map(x => `<option value="${x.id_medico}">${esc(x.nombres + ' ' + x.apellidos)}</option>`).join(''); loadSlots();
  };
  $n('med').onchange = loadSlots; $n('fecha').onchange = loadSlots;
  if (staff) {
    $('#ps').onchange = valid;
    const buscar = async () => {
      const ps = await api('/api/pacientes' + qs({ q: $('#pq').value.trim(), activos: 1 }));
      $('#ps').innerHTML = `<option value="">${ps.length ? '— seleccione —' : 'Sin resultados'}</option>` + ps.map(p => `<option value="${p.id_paciente}">${esc(p.ci + ' — ' + p.nombres + ' ' + p.apellidos)}</option>`).join('');
      if (ps.length === 1) $('#ps').value = ps[0].id_paciente; valid();
    };
    $('#pb').onclick = buscar; $('#pq').onkeydown = e => { if (e.key === 'Enter') { e.preventDefault(); buscar(); } };
  }
  $('#go').onclick = async () => {
    err(''); $('#go').disabled = true;
    try {
      const c = await api('/api/citas', { method: 'POST', body: { id_paciente: staff ? +$('#ps').value : undefined, id_medico: +$n('med').value, id_especialidad: +$n('esp').value, fecha: $n('fecha').value, hora_inicio: hora, motivo: $n('motivo').value } });
      $('#comp').innerHTML = `<div class="card comp"><p class="muted">¡Cita registrada!</p><div class="code">${esc(c.codigo)}</div>
        <p><b>${esc(c.paciente)}</b><br>${esc(c.especialidad)} con ${esc(c.medico)}<br>${fdate(c.fecha)} de ${hm(c.hora_inicio)} a ${hm(c.hora_fin)}</p>
        <p class="muted">Presente este código en recepción. Estado: ${esc(c.estado)}</p><a class="btn" style="text-decoration:none" href="#/citas">Ver mis citas</a></div>`;
      toast('Cita programada'); hora = ''; await loadSlots(); $('#comp').scrollIntoView({ behavior: 'smooth' });
    } catch (e) { err(e.message); await loadSlots(); }
  };
} };

/* ---------- Citas: listado, CU-11 reprogramar, CU-12 cancelar ---------- */
function citasTabla(rows, acciones = true) {
  if (!rows.length) return '<p class="muted">No hay citas para mostrar.</p>';
  const puede = me.rol !== 'Médico';
  return `<div class="tw"><table><thead><tr><th>Código</th><th>Fecha</th><th>Hora</th>${me.rol !== 'Paciente' ? '<th>Paciente</th>' : ''}${me.rol !== 'Médico' ? '<th>Médico</th>' : ''}<th>Especialidad</th><th>Estado</th>${acciones ? '<th></th>' : ''}</tr></thead><tbody>
    ${rows.map(c => `<tr><td>${esc(c.codigo)}</td><td>${fdate(c.fecha)}</td><td>${hm(c.hora_inicio)}–${hm(c.hora_fin)}</td>${me.rol !== 'Paciente' ? `<td>${esc(c.paciente)}</td>` : ''}${me.rol !== 'Médico' ? `<td>${esc(c.medico)}</td>` : ''}<td>${esc(c.especialidad)}</td><td>${badge(c.estado)}</td>
    ${acciones ? `<td class="act"><button class="btn sm ghost" data-hist="${c.id_cita}">Historial</button>${puede && ['Programada', 'Confirmada'].includes(c.estado) ? `<button class="btn sm ghost" data-rep='${esc(JSON.stringify({ id: c.id_cita, m: c.id_medico, f: c.fecha, h: hm(c.hora_inicio), c: c.codigo }))}'>Reprogramar</button><button class="btn sm danger" data-can="${c.id_cita}" data-cod="${esc(c.codigo)}">Cancelar</button>` : ''}</td>` : ''}</tr>`).join('')}</tbody></table></div>`;
}
function bindCitaAcciones(reload) {
  view.querySelectorAll('[data-hist]').forEach(b => b.onclick = async () => {
    try {
      const h = await api(`/api/citas/${b.dataset.hist}/historial`);
      modal('Historial de la cita', `<ul class="hist">${h.map(x => `<li><b>${esc(x.estado_anterior ? x.estado_anterior + ' → ' : '')}${esc(x.estado_nuevo)}</b> · ${ftime(x.fecha_hora)}<br><span class="muted">${esc(x.observacion || '')}${x.usuario ? ' — ' + esc(x.usuario) : ''}</span></li>`).join('')}</ul>`, null);
    } catch (e) { toast(e.message, 'err'); }
  });
  view.querySelectorAll('[data-can]').forEach(b => b.onclick = () => modal(`Cancelar cita ${b.dataset.cod}`, fld('Motivo de la cancelación', 'motivo', { type: 'textarea', req: true, attrs: 'maxlength="200"' }),
    async d => { await api(`/api/citas/${b.dataset.can}/cancelar`, { method: 'POST', body: d }); toast('Cita cancelada'); reload(); }, { ok: 'Confirmar cancelación' }));
  view.querySelectorAll('[data-rep]').forEach(b => b.onclick = () => {
    const c = JSON.parse(b.dataset.rep);
    const d = modal(`Reprogramar cita ${c.c}`, `<p class="muted">Actual: ${fdate(c.f)} ${c.h}</p>${fld('Nueva fecha', 'fecha', { type: 'date', req: true, attrs: `min="${today()}"` })}
      ${fld('Nuevo horario', 'hora_inicio', { req: true, options: [['', 'Elija una fecha primero']] })}${fld('Motivo del cambio (opcional)', 'motivo', { attrs: 'maxlength="100"' })}`,
      async x => { await api(`/api/citas/${c.id}/reprogramar`, { method: 'POST', body: x }); toast('Cita reprogramada'); reload(); }, { ok: 'Reprogramar' });
    $('[name=fecha]', d).onchange = async e => {
      const s = $('[name=hora_inicio]', d);
      try { const r = await api(`/api/disponibilidad?medico=${c.m}&fecha=${e.target.value}`);
        s.innerHTML = r.cupos.length ? '<option value="">Seleccione…</option>' + r.cupos.map(x => `<option value="${hm(x.hora_inicio)}">${hm(x.hora_inicio)} – ${hm(x.hora_fin)}</option>`).join('') : `<option value="">${esc(r.mensaje)}</option>`;
      } catch (ex) { s.innerHTML = `<option value="">${esc(ex.message)}</option>`; }
    };
  });
}
R['/citas'] = { async render() {
  const staff = ['Administrador', 'Recepcionista'].includes(me.rol);
  view.innerHTML = `<h2>${['Paciente', 'Médico'].includes(me.rol) ? 'Mis citas' : 'Citas médicas'}</h2><div class="bar">
    ${staff ? '<input id="q" placeholder="Paciente, CI o código">' : ''}<input type="date" id="fecha" title="Fecha"><select id="est"><option value="">Todos los estados</option>${['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'].map(e => `<option>${e}</option>`).join('')}</select>
    <span class="sp"></span>${me.rol !== 'Médico' ? '<a class="btn" style="text-decoration:none" href="#/nueva-cita">+ Nueva cita</a>' : ''}</div><div class="card" id="tb"></div>`;
  const load = async () => {
    const rows = await api('/api/citas' + qs({ q: staff ? $('#q').value.trim() : '', fecha: $('#fecha').value, estado: $('#est').value }));
    $('#tb').innerHTML = citasTabla(rows); bindCitaAcciones(load);
  };
  if (staff) $('#q').oninput = debounce(load); $('#fecha').onchange = load; $('#est').onchange = load; await load();
} };
