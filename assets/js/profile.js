// "My profile" tab, shared by the citizen and maintenance modules.
(() => {
  const root = document.querySelector('#profile-section');
  if (!root) return;
  const api = root.dataset.api;
  const $ = selector => root.querySelector(selector);
  const form = $('#profile-form'), pwForm = $('#password-form');
  const msg = $('#profile-msg'), pwMsg = $('#password-msg');
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let original = null;

  const show = (el, text, ok = false) => { el.textContent = text; el.classList.add('show'); el.classList.toggle('ok', ok); };
  const hide = el => el.classList.remove('show', 'ok');
  const ageFrom = value => {
    const born = new Date(`${value}T00:00:00`);
    if (Number.isNaN(born.getTime())) return '';
    const now = new Date();
    let age = now.getFullYear() - born.getFullYear();
    if (now.getMonth() < born.getMonth() || (now.getMonth() === born.getMonth() && now.getDate() < born.getDate())) age--;
    return age >= 0 ? String(age) : '';
  };
  async function request(payload) {
    const response = await fetch(api, payload ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) } : { cache: 'no-store' });
    if (response.status === 401) { location.href = '../auth/login.php'; return null; }
    let data;
    try { data = await response.json(); } catch { data = { ok: false, error: 'The server did not return a valid response.' }; }
    return data;
  }
  function fill(profile) {
    original = profile;
    for (const name of ['first_name', 'middle_name', 'last_name', 'name_extension', 'email', 'contact_number', 'birthdate', 'address']) {
      if (form.elements[name]) form.elements[name].value = profile[name] ?? '';
    }
    $('#p-age').value = ageFrom(profile.birthdate);
    const rows = profile.role === 'maintenance'
      ? [['Role', 'Road maintenance crew'], ['LGU', profile.lgu_name || '—'], ['Team', profile.team_name || '—'], ['Account created', profile.member_since || '—']]
      : [['Role', 'Citizen reporter'], ['Member since', profile.member_since || '—']];
    $('#profile-meta').innerHTML = rows.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('');
  }

  // Capitalise each word of the name fields as the person types (same behaviour as registration).
  const capitalize = value => value.replace(/(^|[\s\-'’])(\p{L})/gu, (m, sep, letter) => sep + letter.toUpperCase());
  ['first_name', 'middle_name', 'last_name'].forEach(name => {
    const input = form.elements[name];
    input.addEventListener('input', () => {
      const start = input.selectionStart, end = input.selectionEnd, fixed = capitalize(input.value);
      if (fixed !== input.value) { input.value = fixed; try { input.setSelectionRange(start, end); } catch (e) {} }
    });
  });
  form.elements.birthdate.max = new Date().toISOString().slice(0, 10);
  form.elements.birthdate.addEventListener('input', () => { $('#p-age').value = ageFrom(form.elements.birthdate.value); });

  $('#profile-reset').addEventListener('click', () => { if (original) fill(original); hide(msg); });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    hide(msg);
    const button = form.querySelector('[type=submit]');
    button.disabled = true;
    try {
      const payload = Object.fromEntries(new FormData(form));
      payload.action = 'update';
      const result = await request(payload);
      if (!result) return;
      if (!result.ok) { show(msg, result.error || 'Your profile could not be saved.'); return; }
      fill(result.profile);
      show(msg, 'Profile saved.', true);
      // Refresh the name shown in the sidebar and header.
      const fullName = `${result.profile.first_name} ${result.profile.last_name}`;
      const who = document.querySelector('.who strong'); if (who) who.textContent = fullName;
      const initials = (result.profile.first_name[0] || '') + (result.profile.last_name[0] || '');
      const av = document.querySelector('.top .av'); if (av) av.textContent = initials.toUpperCase();
    } catch (error) {
      show(msg, 'Could not reach StreetFix. Check the server connection.');
    } finally { button.disabled = false; }
  });

  pwForm.addEventListener('submit', async event => {
    event.preventDefault();
    hide(pwMsg);
    const payload = Object.fromEntries(new FormData(pwForm));
    if (!payload.current_password || !payload.new_password) { show(pwMsg, 'Fill in every password field.'); return; }
    if (payload.new_password !== payload.confirm_password) { show(pwMsg, 'New passwords do not match.'); return; }
    const button = pwForm.querySelector('[type=submit]');
    button.disabled = true;
    try {
      payload.action = 'password';
      const result = await request(payload);
      if (!result) return;
      if (!result.ok) { show(pwMsg, result.error || 'Your password could not be changed.'); return; }
      pwForm.reset();
      show(pwMsg, 'Password updated.', true);
    } catch (error) {
      show(pwMsg, 'Could not reach StreetFix. Check the server connection.');
    } finally { button.disabled = false; }
  });

  request().then(data => {
    if (!data) return;
    if (data.ok) fill(data.profile); else show(msg, data.error || 'Could not load your profile.');
  }).catch(() => show(msg, 'Could not load your profile.'));
})();
