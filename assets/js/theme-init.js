// Runs synchronously in <head> (before the stylesheet) so the saved theme is applied before the first paint.
// theme.js used to be the only place this happened, at the end of <body>, which caused a white flash in dark mode on every page switch.
(function () {
  var theme = 'light';
  try {
    var stored = localStorage.getItem('streetfix-theme');
    theme = stored ? (stored === 'dark' ? 'dark' : 'light') : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  } catch (e) {}
  document.documentElement.setAttribute('data-theme', theme);
})();
