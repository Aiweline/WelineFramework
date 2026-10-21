/**
 * 支付方式选择与提交 (Weline_Payment).
 * Moved verbatim from the inline <script> in checkout/payment-methods.phtml.
 * Registered as module `paymentMethods`; root declares data-weline-load.
 */
document.addEventListener('DOMContentLoaded', function() {
    const root = document.querySelector('.payment-methods');
    if (!root) {
        return;
    }

    const paymentMethods = root.querySelectorAll('input[name="payment_method"]');
    const paymentForm = root.querySelector('#payment-form');
    const isFakeMode = root.dataset.fakeMode === '1';

    paymentMethods.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (paymentForm) {
                paymentForm.hidden = !this.checked;
            }
        });
    });

    if (!isFakeMode) {
        return;
    }

    let scenarios = {};
    try {
        scenarios = JSON.parse(root.dataset.fakeScenarios || '{}');
    } catch (error) {
        scenarios = {};
    }

    const terms = root.querySelector('[data-payment-terms]');
    const feedback = root.querySelector('[data-payment-feedback]');
    const result = root.querySelector('[data-payment-result]');
    const resultTitle = root.querySelector('[data-payment-result-title]');
    const resultMethod = root.querySelector('[data-payment-result-method]');
    const resultStatus = root.querySelector('[data-payment-result-status]');
    const resultReference = root.querySelector('[data-payment-result-reference]');
    const resultAmount = root.querySelector('[data-payment-result-amount]');
    const resultMessage = root.querySelector('[data-payment-result-message]');

    const setFeedback = function(message) {
        if (feedback) {
            feedback.textContent = message || '';
        }
    };

    root.querySelectorAll('[data-fake-action]').forEach(function(button) {
        button.addEventListener('click', function() {
            const selected = root.querySelector('input[name="payment_method"]:checked');
            if (!selected) {
                setFeedback(root.dataset.messageSelect || '');
                return;
            }
            if (terms && !terms.checked) {
                setFeedback(root.dataset.messageTerms || '');
                return;
            }

            const scenario = scenarios[selected.value] || {};
            const action = button.dataset.fakeAction || 'paid';
            const entry = (scenario.results || {})[action];
            if (!entry) {
                setFeedback(scenario.disabled_reason || '');
                return;
            }

            setFeedback('');
            if (result) {
                result.hidden = false;
                result.dataset.status = action;
            }
            if (resultTitle) {
                resultTitle.textContent = entry.title || '';
            }
            if (resultMethod) {
                resultMethod.textContent = scenario.method_label || selected.value;
            }
            if (resultStatus) {
                resultStatus.textContent = entry.status || '';
            }
            if (resultReference) {
                resultReference.textContent = entry.reference || '';
            }
            if (resultAmount) {
                resultAmount.textContent = scenario.amount || '';
            }
            if (resultMessage) {
                resultMessage.textContent = entry.message || '';
            }
        });
    });
});
