(() => {
  const key = 'streetfix-theme';
  const root = document.documentElement;
  const stored = localStorage.getItem(key);
  const theme = stored ? (stored === 'dark' ? 'dark' : 'light') : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  root.dataset.theme = theme;

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'theme-toggle';
  toggle.setAttribute('aria-pressed', String(theme === 'dark'));

  const updateLabel = value => {
    toggle.textContent = value === 'dark' ? 'Light mode' : 'Dark mode';
    toggle.setAttribute('aria-label', `Switch to ${value === 'dark' ? 'light' : 'dark'} mode`);
    toggle.setAttribute('aria-pressed', String(value === 'dark'));
  };

  updateLabel(theme);
  const host = document.querySelector('.side') || document.querySelector('.auth');
  const account = host?.querySelector('.who');
  if (host && account) host.insertBefore(toggle, account);
  else if (host) host.append(toggle);
  else document.body.append(toggle);

  toggle.addEventListener('click', () => {
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    localStorage.setItem(key, next);
    updateLabel(next);
  });
})();

/* Shrinks a phone photo (often 3-8 MB) to about 1600 px / ~300 KB in the browser before it is uploaded.
   Falls back to the original file if anything goes wrong. */
window.sfShrinkImage = async file => {
  if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 400 * 1024) return file;
  try {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    if (bitmap.close) bitmap.close();
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
    if (!blob || blob.size >= file.size) return file;
    return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch (error) {
    return file;
  }
};

/* Show/hide control for every password field (login, register, profile, admin "create account").
   Added automatically, so any password input added later gets it too. Passwords stay hidden until the person asks. */
(() => {
  const eye = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeOff = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.9 17.9A10.4 10.4 0 0 1 12 19C5.6 19 2 12 2 12a18.5 18.5 0 0 1 4.1-5.1M9.9 5.2A9.9 9.9 0 0 1 12 5c6.4 0 10 7 10 7a18.6 18.6 0 0 1-2.2 3.2M1 1l22 22"/><path d="M14.1 14.1a3 3 0 1 1-4.2-4.2"/></svg>';

  const enhance = input => {
    if (input.dataset.pwToggle) return;
    input.dataset.pwToggle = '1';
    const wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.append(input);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'pw-toggle';
    const render = visible => {
      button.innerHTML = visible ? eyeOff : eye;
      button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
      button.setAttribute('aria-pressed', String(visible));
      button.title = visible ? 'Hide password' : 'Show password';
    };
    render(false);
    wrap.append(button);

    button.addEventListener('mousedown', event => event.preventDefault());   // keep the cursor in the field
    button.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      render(show);
      input.focus({ preventScroll: true });
    });
  };

  const scan = root => root.querySelectorAll('input[type="password"]').forEach(enhance);
  scan(document);
  new MutationObserver(records => {
    for (const record of records) for (const node of record.addedNodes) {
      if (node.nodeType !== 1) continue;
      if (node.matches('input[type="password"]')) enhance(node); else scan(node);
    }
  }).observe(document.body, { childList: true, subtree: true });
})();
