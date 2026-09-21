/* Weline UI source: js/agent-admin-prototype.js */
/**
 * PROTOTYPE ONLY — floating variant switcher for Agent role listing.
 * Question: What should Agent admin role onboarding look like?
 * Variants: A dense list | B studio tiles (default) | C split rail
 */
(function () {
    const params = new URLSearchParams(window.location.search);
    const variants = ['A', 'B', 'C'];
    const labels = {
        A: 'A 紧凑列表',
        B: 'B 工作台磁贴',
        C: 'C 分栏步骤',
    };

    function current() {
        const v = (params.get('variant') || 'B').toUpperCase();
        return variants.includes(v) ? v : 'B';
    }

    function go(next) {
        params.set('variant', next);
        const url = window.location.pathname + '?' + params.toString() + window.location.hash;
        window.location.assign(url);
    }

    function cycle(delta) {
        const i = variants.indexOf(current());
        const next = variants[(i + delta + variants.length) % variants.length];
        go(next);
    }

    const bar = document.createElement('div');
    bar.className = 'w-agent-prototype-bar';
    bar.setAttribute('data-prototype', 'agent-role-ui');
    bar.innerHTML =
        '<div class="w-agent-prototype-bar__inner" role="group" aria-label="原型变体">' +
        '<button type="button" class="w-button" data-size="sm" data-tone="neutral" data-variant="outline" data-proto-prev aria-label="上一个变体">←</button>' +
        '<span data-proto-label></span>' +
        '<button type="button" class="w-button" data-size="sm" data-tone="neutral" data-variant="outline" data-proto-next aria-label="下一个变体">→</button>' +
        '</div>';
    document.body.appendChild(bar);
    const label = bar.querySelector('[data-proto-label]');
    if (label) {
        label.textContent = labels[current()] || current();
    }
    bar.querySelector('[data-proto-prev]')?.addEventListener('click', () => cycle(-1));
    bar.querySelector('[data-proto-next]')?.addEventListener('click', () => cycle(1));

    document.addEventListener('keydown', (event) => {
        const t = event.target;
        if (t && (t.matches('input, textarea, select, [contenteditable="true"]'))) {
            return;
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            cycle(-1);
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            cycle(1);
        }
    });
})();
