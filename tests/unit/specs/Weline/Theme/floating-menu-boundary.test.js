import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

let UI;

beforeAll(async () => {
    Object.defineProperty(window, 'visualViewport', {
        configurable: true,
        value: {
            width: 360,
            height: 640,
            offsetLeft: 0,
            offsetTop: 0,
            addEventListener() {},
            removeEventListener() {},
        },
    });
    document.documentElement.dataset.wArea = 'backend';
    document.documentElement.dataset.themePreference = 'system';
    ({ UI } = await import('../../../../../app/code/Weline/Theme/view/ui/js/weline-ui.js'));
});

afterAll(() => {
    document.body.replaceChildren();
});

function rect(left, top, width, height) {
    return { left, top, right: left + width, bottom: top + height, width, height, x: left, y: top };
}

function createMenu() {
    const root = document.createElement('div');
    root.dataset.wComponent = 'menu';
    root.dataset.wPlacement = 'bottom-end';
    root.dataset.wAnchorMode = 'element';
    root.innerHTML = `
        <button type="button" data-w-menu-trigger aria-expanded="false">Notifications</button>
        <div class="w-menu" data-w-menu-panel hidden aria-hidden="true"><button role="menuitem">Open</button></div>
    `;
    const trigger = root.querySelector('[data-w-menu-trigger]');
    const panel = root.querySelector('[data-w-menu-panel]');
    trigger.getBoundingClientRect = () => rect(328, 20, 24, 24);
    panel.getBoundingClientRect = () => {
        const left = Number.parseFloat(panel.style.getPropertyValue('--w-floating-left')) || 0;
        const top = Number.parseFloat(panel.style.getPropertyValue('--w-floating-top')) || 0;
        return rect(left, top, 320, 500);
    };
    document.body.append(root);
    UI.mount(root);
    return { root, trigger, panel };
}

function click(target, clientX = 0, clientY = 0) {
    target.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        button: 0,
        clientX,
        clientY,
        detail: 1,
    }));
}

describe('Weline UI floating menu boundaries', () => {
    beforeEach(() => {
        document.querySelectorAll('[data-floating-test]').forEach((node) => node.remove());
    });

    it('clamps to the visual viewport and reopens at the stable element anchor', () => {
        const fixture = createMenu();
        fixture.root.dataset.floatingTest = '1';

        click(fixture.trigger, 330, 22);
        const first = fixture.panel.getBoundingClientRect();
        expect(fixture.panel.hidden).toBe(false);
        expect(first.left).toBeGreaterThanOrEqual(8);
        expect(first.right).toBeLessThanOrEqual(352);
        expect(fixture.panel.dataset.wActualPlacement).toBe('bottom-end');

        click(fixture.trigger, 351, 40);
        expect(fixture.panel.hidden).toBe(true);
        click(fixture.trigger, 329, 21);
        const second = fixture.panel.getBoundingClientRect();
        expect(second.left).toBe(first.left);
        expect(second.right).toBe(first.right);

        UI.unmount(fixture.root);
        fixture.root.remove();
    });

    it('closes transient state on pagehide and resets a stale open DOM state when remounted', () => {
        const fixture = createMenu();
        fixture.root.dataset.floatingTest = '1';

        click(fixture.trigger, 340, 30);
        expect(fixture.panel.hidden).toBe(false);
        window.dispatchEvent(new Event('pagehide'));
        expect(fixture.panel.hidden).toBe(true);
        expect(fixture.panel.hasAttribute('data-w-floating-positioned')).toBe(false);

        UI.unmount(fixture.root);
        fixture.panel.hidden = false;
        fixture.panel.dataset.state = 'open';
        UI.mount(fixture.root);
        expect(fixture.panel.hidden).toBe(true);
        expect(fixture.panel.dataset.state).toBe('closed');

        UI.unmount(fixture.root);
        fixture.root.remove();
    });
});
