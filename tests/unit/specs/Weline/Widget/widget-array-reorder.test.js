import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

beforeAll(async () => {
    window.Weline = {
        UI: {
            define: vi.fn(),
            mount: vi.fn(),
            unmount: vi.fn(),
        },
    };
    await import('../../../../../app/code/Weline/Widget/view/statics/js/widget-param-types.js');
});

function item(index, title) {
    return `
        <article class="w-param-array-item" data-index="${index}" data-w-reorder-item>
            <button type="button" data-w-reorder-handle>Move</button>
            <label for="config_10_slides_${index}_title">Title</label>
            <input id="config_10_slides_${index}_title" data-field="title" value="${title}">
            <button type="button" data-field="slides.${index}.title" data-array-key="slides" data-array-index="${index}">i18n</button>
            <section id="i18n_panel_10_slides_${index}_title" data-field="slides.${index}.title"
                data-array-key="slides" data-array-index="${index}"></section>
        </article>
    `;
}

describe('Widget array reorder integration', () => {
    beforeEach(() => {
        document.body.replaceChildren();
    });

    it('persists DOM order and reindexes nested i18n identities together', () => {
        const form = document.createElement('form');
        form.className = 'w-param-form';
        form.innerHTML = `
            <div class="w-param-array" data-field-id="config_10_slides" data-key="slides">
                <div class="w-param-array-items" data-w-component="reorder-list">
                    ${item(0, 'First')}
                    ${item(1, 'Second')}
                </div>
                <input type="hidden" id="config_10_slides" value='[{"title":"First"},{"title":"Second"}]'>
                <script type="application/json" id="config_10_slides_schema">{"title":{"type":"string"}}</script>
            </div>
        `;
        document.body.append(form);
        window.Weline.Widget.Params.mount(form);

        const list = form.querySelector('.w-param-array-items');
        const original = [...list.querySelectorAll('.w-param-array-item')];
        list.insertBefore(original[1], original[0]);
        list.dispatchEvent(new CustomEvent('weline:ui:reorder-list:change', {
            bubbles: true,
            detail: { item: original[1], oldIndex: 1, newIndex: 0, reason: 'keyboard' },
        }));

        const reordered = [...list.querySelectorAll('.w-param-array-item')];
        expect(reordered.map((node) => node.dataset.index)).toEqual(['0', '1']);
        expect(form.querySelector('#config_10_slides').value)
            .toBe('[{"title":"Second"},{"title":"First"}]');
        expect(reordered[0].querySelector('[data-array-index]').dataset.arrayIndex).toBe('0');
        expect(reordered[0].querySelector('[data-field^="slides."]').dataset.field).toBe('slides.0.title');
        expect(reordered[0].querySelector('section').id).toBe('i18n_panel_10_slides_0_title');
        expect(reordered[0].querySelector('label').htmlFor).toBe('config_10_slides_0_title');
        expect(reordered[1].querySelector('[data-array-index]').dataset.arrayIndex).toBe('1');
        expect(reordered[1].querySelector('[data-field^="slides."]').dataset.field).toBe('slides.1.title');
        expect(reordered[1].querySelector('section').id).toBe('i18n_panel_10_slides_1_title');
    });
});
