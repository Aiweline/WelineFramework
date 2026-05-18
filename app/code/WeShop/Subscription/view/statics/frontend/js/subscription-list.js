(function () {
    var subscriptionApiPromise = null;

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

    function getSubscriptionApi() {
        if (!subscriptionApiPromise) {
            subscriptionApiPromise = Promise.resolve(window.Weline.Api.resource('subscription'));
        }

        return subscriptionApiPromise;
    }

    async function postAction(page, action, payload) {
        if (!action) {
            message(page, 'Operation is missing.', 'error');
            return;
        }

        try {
            var SubscriptionApi = await getSubscriptionApi();
            var result = await SubscriptionApi[action](payload, {silent: true});
            if (result.code === 200 || result.success === true) {
                message(page, result.msg || result.message || 'Operation succeeded.', 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 650);
                return;
            }
            message(page, result.msg || result.message || 'Operation failed. Please try again.', 'error');
        } catch (error) {
            message(page, 'Operation failed. Please try again.', 'error');
        }
    }

    document.addEventListener('click', function (event) {
        var actionButton = event.target.closest('[data-subscription-action]');
        if (actionButton) {
            var page = findPage(actionButton);
            var action = actionButton.getAttribute('data-subscription-action');
            var id = actionButton.getAttribute('data-id');
            if (page && id) {
                postAction(page, action, {id: parseInt(id, 10) || 0});
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

        postAction(page, 'cancel', {
            id: parseInt(id, 10) || 0,
            reason: reason ? reason.value : ''
        });
    });
})();
