(function () {
    function findPage(element) {
        return element.closest('[data-subscription-page]');
    }

    function message(page, text, type) {
        var box = page.querySelector('[data-subscription-message]');
        if (!box) {
            return;
        }
        box.textContent = text || '';
        box.hidden = !text;
        box.classList.remove('is-success', 'is-error');
        if (text) {
            box.classList.add(type === 'success' ? 'is-success' : 'is-error');
        }
    }

    function endpoint(page, action) {
        if (action === 'pause') {
            return page.getAttribute('data-pause-url');
        }
        if (action === 'resume') {
            return page.getAttribute('data-resume-url');
        }
        if (action === 'cancel') {
            return page.getAttribute('data-cancel-url');
        }
        return '';
    }

    async function postAction(page, action, payload) {
        var url = endpoint(page, action);
        if (!url) {
            message(page, '操作地址不存在。', 'error');
            return;
        }

        try {
            var response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: payload
            });
            var result = await response.json();
            if (result.code === 200 || result.success === true) {
                message(page, result.msg || result.message || '操作成功。', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 650);
                return;
            }
            message(page, result.msg || result.message || '操作失败，请稍后重试。', 'error');
        } catch (error) {
            message(page, '操作失败，请稍后重试。', 'error');
        }
    }

    document.addEventListener('click', function (event) {
        var actionButton = event.target.closest('[data-subscription-action]');
        if (actionButton) {
            var page = findPage(actionButton);
            var action = actionButton.getAttribute('data-subscription-action');
            var id = actionButton.getAttribute('data-id');
            if (page && id) {
                postAction(page, action, new URLSearchParams({ id: id }));
            }
            return;
        }

        var cancelToggle = event.target.closest('[data-subscription-cancel-toggle]');
        if (cancelToggle) {
            var card = cancelToggle.closest('[data-subscription-card]');
            var form = card ? card.querySelector('[data-subscription-cancel-form]') : null;
            if (form) {
                form.hidden = !form.hidden;
            }
            return;
        }

        if (event.target.closest('[data-subscription-cancel-close]')) {
            var closeForm = event.target.closest('[data-subscription-cancel-form]');
            if (closeForm) {
                closeForm.hidden = true;
            }
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-subscription-cancel-form]');
        if (!form) {
            return;
        }

        event.preventDefault();
        var page = findPage(form);
        var id = form.getAttribute('data-id');
        var reason = form.querySelector('textarea[name="reason"]');
        if (!page || !id) {
            return;
        }

        postAction(page, 'cancel', new URLSearchParams({
            id: id,
            reason: reason ? reason.value : ''
        }));
    });
})();
