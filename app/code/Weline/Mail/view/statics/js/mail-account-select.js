/**
 * Mail account switcher: submit the host form when the account select changes.
 * Registered as module `mailAccountSelect`; selects declare data-mail-account-select.
 */
(function () {
    'use strict';
    document.querySelectorAll('select[data-mail-account-select]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) {
                select.form.submit();
            }
        });
    });
    window.WelineMailAccountSelect = window.WelineMailAccountSelect || { ready: true };
}());
