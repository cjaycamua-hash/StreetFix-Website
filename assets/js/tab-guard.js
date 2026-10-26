// Ends the login when the browser tab/window that signed in has been closed.
// sessionStorage lives only as long as the tab, so a fresh tab has no marker and is signed out.
(function () {
  var KEY = 'sf_tab_session';
  var script = document.currentScript;
  var logoutUrl = script.getAttribute('data-logout');
  var loginUrl = script.getAttribute('data-login');
  var marked = false;
  try { marked = sessionStorage.getItem(KEY) === '1'; } catch (e) {}
  if (marked) return;
  document.documentElement.style.visibility = 'hidden';
  var go = function () { location.replace(loginUrl); };
  fetch(logoutUrl, { method: 'POST', credentials: 'same-origin', keepalive: true }).then(go, go);
})();
