(function () {
    'use strict';

    function boot() {
        const root = document.getElementById('async-event-probe-app');
        if (!root) return;
        const ui = {
            trigger: document.getElementById('probe-trigger'), advance: document.getElementById('probe-advance'),
            refresh: document.getElementById('probe-refresh'), message: document.getElementById('probe-message'),
            eventId: document.getElementById('probe-event-id'), probeId: document.getElementById('probe-id'),
            outbox: document.getElementById('probe-outbox'), delivery: document.getElementById('probe-delivery'),
            proof: document.getElementById('probe-proof'), json: document.getElementById('probe-json'),
            requestStep: document.getElementById('probe-step-request'), outboxStep: document.getElementById('probe-step-outbox'),
            deliveryStep: document.getElementById('probe-step-delivery'), observerStep: document.getElementById('probe-step-observer')
        };
        let apiPromise = null;
        let eventId = '';
        let probeId = '';
        let polling = null;

        function api() {
            if (!apiPromise) apiPromise = Promise.resolve(window.Weline.Api.resource(root.dataset.provider));
            return apiPromise;
        }
        function busy(value) {
            ui.trigger.disabled = value;
            ui.advance.disabled = value || !eventId;
            ui.refresh.disabled = value || !eventId;
        }
        function message(text, kind) {
            ui.message.className = 'alert alert-' + (kind || 'info');
            ui.message.textContent = text;
        }
        function render(status, boundary) {
            const outbox = status && status.outbox;
            const delivery = status && status.probe_delivery;
            ui.eventId.textContent = eventId || '—';
            ui.probeId.textContent = probeId || '—';
            ui.outbox.textContent = outbox ? '#' + outbox.id + ' / ' + outbox.status : '—';
            ui.delivery.textContent = delivery
                ? '#' + delivery.id + ' / ' + delivery.status + ' / attempt ' + delivery.attempt_no
                    + (delivery.queue_id ? ' / queue #' + delivery.queue_id : '')
                : '尚未创建（证明请求返回时 Observer 未执行）';
            ui.requestStep.dataset.state = eventId ? 'passed' : '';
            ui.outboxStep.dataset.state = outbox ? 'passed' : '';
            ui.deliveryStep.dataset.state = delivery ? (delivery.status === 'succeeded' ? 'passed' : 'active') : '';
            ui.observerStep.dataset.state = status && status.async_observer_succeeded ? 'passed' : '';
            ui.proof.textContent = status && status.async_observer_succeeded
                ? '通过：HTTP 返回后，探针由 Queue Worker 异步执行成功。'
                : (boundary && boundary.outbox_committed && boundary.observer_not_succeeded_before_response
                    ? '边界已证明：Outbox 已提交，请求返回时 Observer 尚未成功。'
                    : '等待异步 Observer 终态。');
            ui.json.textContent = JSON.stringify({ boundary: boundary || null, status: status }, null, 2);
            if (status && status.async_observer_succeeded) {
                message('验收通过：w_changed 资源变更已经过真实异步链路执行。', 'success');
                if (polling) window.clearInterval(polling);
                polling = null;
            }
        }
        async function status() {
            const result = await (await api()).status({ event_id: eventId });
            render(result, null);
            return result;
        }
        ui.trigger.addEventListener('click', async function () {
            busy(true); message('正在发起 w_changed 资源变更…', 'info');
            try {
                const result = await (await api()).trigger({});
                eventId = result.event_id; probeId = result.probe_id;
                render(result.status, result.request_boundary_proof);
                message('第一阶段通过：Outbox 已提交，请求返回时 Observer 尚未成功。请点击“推进异步链路”。', 'warning');
            } catch (error) { message(error && error.message ? error.message : '发起失败', 'danger'); }
            finally { busy(false); }
        });
        ui.advance.addEventListener('click', async function () {
            busy(true); message('正在调用真实 Relay / Transport / Queue…', 'info');
            try {
                const result = await (await api()).advance({ event_id: eventId });
                render(result.status, null);
                if (!result.status.async_observer_succeeded) {
                    message('Queue Worker 已接收，正在等待 Observer 终态…', 'info');
                    if (polling) window.clearInterval(polling);
                    polling = window.setInterval(function () { status().catch(function () {}); }, 1000);
                }
            } catch (error) { message(error && error.message ? error.message : '推进失败', 'danger'); }
            finally { busy(false); }
        });
        ui.refresh.addEventListener('click', function () { status().catch(function (error) { message(error.message, 'danger'); }); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
}());
