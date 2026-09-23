window.WelineWidgetAssets.register('mail-account-mail-register-0', function (widgetScript) {
(function () {
  function bind(form, confirmForm) {
    if (!form) return;
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      var msg = form.querySelector('[data-mail-register-msg]');
      var fd = new FormData(form);
      var res = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      var data = await res.json().catch(function () { return {}; });
      if (msg) { msg.hidden = false; msg.textContent = data.message || ''; }
      if (data.needs_verify && confirmForm) confirmForm.hidden = false;
    });
  }
  var root = document.querySelector('[data-w-component="account-mail-register"]');
  if (!root) return;
  bind(root.querySelector('[data-mail-register-form]'), root.querySelector('[data-mail-register-confirm]'));
  var confirmForm = root.querySelector('[data-mail-register-confirm]');
  if (confirmForm) {
    confirmForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      var fd = new FormData(confirmForm);
      var res = await fetch(confirmForm.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      var data = await res.json().catch(function () { return {}; });
      var msg = root.querySelector('[data-mail-register-msg]');
      if (msg) { msg.hidden = false; msg.textContent = data.message || ''; }
    });
  }
})();
});
