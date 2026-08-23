import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { register } from '../../../../../app/code/Weline/Theme/view/ui/js/components/advanced.js';

const definitions = new Map();

beforeAll(() => {
    register({
        define(name, factory) {
            definitions.set(name, factory);
        },
    });
});

function createReorderList() {
    const element = document.createElement('div');
    element.dataset.wReorderAxis = 'vertical';
    element.innerHTML = `
        <article data-w-reorder-item data-index="0"><button type="button" data-w-reorder-handle>One</button></article>
        <article data-w-reorder-item data-index="1"><button type="button" data-w-reorder-handle>Two</button></article>
        <article data-w-reorder-item data-index="2"><button type="button" data-w-reorder-handle>Three</button></article>
    `;
    document.body.append(element);

    const cleanups = [];
    const emit = vi.fn();
    const listen = (target, type, handler, options) => {
        target.addEventListener(type, handler, options);
        cleanups.push(() => target.removeEventListener(type, handler, options));
    };
    const factory = definitions.get('reorder-list');
    const instance = factory({ element, listen, emit });

    return {
        element,
        emit,
        instance,
        destroy() {
            instance.destroy?.();
            cleanups.reverse().forEach((cleanup) => cleanup());
            element.remove();
        },
    };
}

describe('Weline UI reorder-list', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('reorders with the keyboard, reindexes items, and emits one change', () => {
        const fixture = createReorderList();
        const firstHandle = fixture.element.querySelector('[data-w-reorder-handle]');

        firstHandle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));

        const order = [...fixture.element.querySelectorAll('[data-w-reorder-item]')];
        expect(order.map((item) => item.textContent.trim())).toEqual(['Two', 'One', 'Three']);
        expect(order.map((item) => item.dataset.index)).toEqual(['0', '1', '2']);
        expect(document.activeElement).toBe(firstHandle);
        expect(fixture.emit).toHaveBeenCalledOnce();
        expect(fixture.emit).toHaveBeenCalledWith('change', expect.objectContaining({
            item: order[1],
            oldIndex: 0,
            newIndex: 1,
            reason: 'keyboard',
        }), false);

        fixture.destroy();
    });

    it('supports Home, End, and a disabled boundary without losing focus', () => {
        const fixture = createReorderList();
        const middleHandle = fixture.element.querySelectorAll('[data-w-reorder-handle]')[1];

        middleHandle.dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
        expect([...fixture.element.querySelectorAll('[data-w-reorder-item]')].map((item) => item.textContent.trim()))
            .toEqual(['One', 'Three', 'Two']);

        fixture.element.dataset.wReorderDisabled = 'true';
        middleHandle.dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
        expect([...fixture.element.querySelectorAll('[data-w-reorder-item]')].map((item) => item.textContent.trim()))
            .toEqual(['One', 'Three', 'Two']);
        expect(document.activeElement).toBe(middleHandle);

        fixture.destroy();
    });

    it('rejects an invalid programmatic target without changing the list', () => {
        const fixture = createReorderList();
        const first = fixture.element.querySelector('[data-w-reorder-item]');

        expect(fixture.instance.move(first, 'invalid')).toBe(false);
        expect([...fixture.element.querySelectorAll('[data-w-reorder-item]')].map((item) => item.textContent.trim()))
            .toEqual(['One', 'Two', 'Three']);
        expect(fixture.emit).not.toHaveBeenCalled();

        fixture.destroy();
    });

    it('uses Pointer Events for touch-compatible reordering and commits once on release', () => {
        const fixture = createReorderList();
        const items = [...fixture.element.querySelectorAll('[data-w-reorder-item]')];
        fixture.element.getBoundingClientRect = () => ({ top: 0, bottom: 120, left: 0, right: 200, width: 200, height: 120 });
        items.forEach((item, index) => {
            item.getBoundingClientRect = () => ({
                top: index * 40,
                bottom: (index + 1) * 40,
                left: 0,
                right: 200,
                width: 200,
                height: 40,
            });
        });
        const firstHandle = items[0].querySelector('[data-w-reorder-handle]');

        firstHandle.dispatchEvent(new PointerEvent('pointerdown', {
            bubbles: true,
            button: 0,
            clientY: 10,
            isPrimary: true,
            pointerId: 7,
            pointerType: 'touch',
        }));
        firstHandle.dispatchEvent(new PointerEvent('pointermove', {
            bubbles: true,
            clientY: 115,
            isPrimary: true,
            pointerId: 7,
            pointerType: 'touch',
        }));

        expect(fixture.emit).not.toHaveBeenCalled();
        firstHandle.dispatchEvent(new PointerEvent('pointerup', {
            bubbles: true,
            clientY: 115,
            isPrimary: true,
            pointerId: 7,
            pointerType: 'touch',
        }));

        expect([...fixture.element.querySelectorAll('[data-w-reorder-item]')].map((item) => item.textContent.trim()))
            .toEqual(['Two', 'Three', 'One']);
        expect(fixture.emit).toHaveBeenCalledOnce();
        expect(fixture.emit).toHaveBeenCalledWith('change', expect.objectContaining({
            oldIndex: 0,
            newIndex: 2,
            reason: 'pointer',
        }), false);
        expect(firstHandle.closest('[data-w-reorder-item]').hasAttribute('data-state')).toBe(false);

        fixture.destroy();
    });
});
