import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('Weline DataTable lazy bundle contract', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.replaceChildren();
        delete window.Weline;
        delete window.__;
    });

    it('registers table and form as independent Weline UI components without globals', async () => {
        const definitions = new Map();
        const UI = {
            define: vi.fn((name, factory) => definitions.set(name, factory)),
            mount: vi.fn(),
        };
        window.Weline = { UI };

        await import('../../../../../app/code/Weline/DataTable/view/statics/js/datatable-manager.js');
        expect(definitions.has('data-table')).toBe(true);
        expect(definitions.has('data-table-form')).toBe(false);

        await import('../../../../../app/code/Weline/DataTable/view/statics/js/datatable-form-manager.js');
        expect(definitions.has('data-table-form')).toBe(true);
        expect(window.DataTableManager).toBeUndefined();
        expect(window.DataTableFormManager).toBeUndefined();
        expect(UI.mount).toHaveBeenCalledTimes(2);
    });

    it('keeps table loading, dimensions, generated fields and form submission working after the split', async () => {
        const definitions = new Map();
        const resource = {
            fields: vi.fn().mockResolvedValue({
                data: {
                    all_fields: [
                        {name: 'name', label: 'Name', visible: true},
                        {name: 'email', label: 'Email', visible: true},
                    ],
                    display_fields: [
                        {name: 'name', label: 'Name', visible: true},
                        {name: 'email', label: 'Email', visible: true},
                    ],
                    filter_fields: [],
                },
            }),
            data: vi.fn().mockResolvedValue({
                data: {
                    data: [{id: 7, name: 'Weline'}],
                    pagination: {page: 1, pageSize: 20, total: 1, lastPage: 1},
                },
            }),
            formFields: vi.fn().mockResolvedValue({
                data: {fields: [{name: 'name', label: 'Name', type: 'text', required: true}]},
            }),
            create: vi.fn().mockResolvedValue({success: true}),
        };
        const UI = {
            define: vi.fn((name, factory) => definitions.set(name, factory)),
            mount: vi.fn(),
            get: vi.fn(),
            dialog: {
                open: vi.fn().mockReturnValue(true),
                close: vi.fn().mockReturnValue(true),
                confirm: vi.fn().mockResolvedValue(true),
            },
            toast: {
                success: vi.fn(),
                error: vi.fn(),
            },
        };
        window.Weline = {
            UI,
            Api: {resource: vi.fn().mockResolvedValue(resource)},
        };
        window.__ = (phrase, parameters) => Object.keys(parameters || {}).reduce(
            (text, key) => text.replaceAll(`%{${key}}`, String(parameters[key])),
            phrase,
        );

        await import('../../../../../app/code/Weline/DataTable/view/statics/js/datatable-manager.js');
        await import('../../../../../app/code/Weline/DataTable/view/statics/js/datatable-form-manager.js');

        const table = document.createElement('section');
        table.dataset.wConfig = JSON.stringify({
            id: 'orders',
            model: 'Example\\Order',
            scope: 'orders',
            operations: {fields: 'fields', data: 'data'},
            pageSize: 20,
        });
        table.dataset.wDatatableHeight = '36rem';
        table.dataset.wDatatableWidth = '100%';
        table.innerHTML = `
            <div data-w-datatable-status hidden></div>
            <span data-w-datatable-total></span>
            <span data-w-datatable-visible></span>
            <table>
                <thead class="w-datatable__head"><tr><th data-w-field='{"name":"name","label":"Name"}'></th></tr></thead>
                <tbody class="w-datatable__body"></tbody>
                <tfoot class="w-datatable__footer"><tr><td><span data-w-datatable-summary></span><nav data-w-datatable-pagination></nav></td></tr></tfoot>
            </table>
        `;
        document.body.append(table);
        const listen = (target, type, handler) => target.addEventListener(type, handler);
        const tableComponent = definitions.get('data-table')({element: table, listen});

        await vi.waitFor(() => expect(resource.data).toHaveBeenCalled());
        await vi.waitFor(() => expect(tableComponent.state.data).toHaveLength(1));
        expect(tableComponent.state.displayFields).toEqual([
            expect.objectContaining({name: 'name'}),
        ]);
        expect({
            body: table.querySelector('.w-datatable__body')?.innerHTML,
            errors: UI.toast.error.mock.calls,
        }).toEqual({
            body: expect.stringContaining('Weline'),
            errors: [],
        });
        expect(table.style.getPropertyValue('--w-datatable-height')).toBe('36rem');
        expect(table.style.getPropertyValue('--w-datatable-width')).toBe('100%');
        expect(table.querySelector('[data-w-datatable-summary]')?.textContent).toBe('显示 1–1，共 1 条');
        expect(tableComponent).toEqual(expect.objectContaining({
            reload: expect.any(Function),
            openConfig: expect.any(Function),
            destroy: expect.any(Function),
        }));

        const formRoot = document.createElement('dialog');
        formRoot.dataset.wConfig = JSON.stringify({
            id: 'order-form',
            model: 'Example\\Order',
            scope: 'orders',
            autoFields: true,
            operations: {formFields: 'formFields', create: 'create'},
        });
        formRoot.innerHTML = `
            <h2 data-w-datatable-form-title></h2>
            <form id="order-form"><div data-w-datatable-form-auto></div></form>
            <div data-w-datatable-form-message hidden></div>
        `;
        document.body.append(formRoot);
        const formComponent = definitions.get('data-table-form')({element: formRoot, listen});

        await vi.waitFor(() => {
            expect(formRoot.querySelector('input[name="name"]')).toBeInstanceOf(HTMLInputElement);
        });
        const name = formRoot.querySelector('input[name="name"]');
        name.value = 'Preserved feature';
        await formComponent.submit();
        expect(resource.create).toHaveBeenCalledWith(
            expect.objectContaining({data: {name: 'Preserved feature'}}),
            {silent: true},
        );
        expect(UI.toast.success).toHaveBeenCalled();
        expect(formComponent).toEqual(expect.objectContaining({
            open: expect.any(Function),
            reset: expect.any(Function),
            submit: expect.any(Function),
            destroy: expect.any(Function),
        }));
    });

    it('keeps backend demo data controls on the Weline API provider', async () => {
        document.body.innerHTML = `
            <main data-w-datatable-admin>
                <button type="button" data-w-demo-action="init">Init</button>
                <button type="button" data-w-demo-action="clear">Clear</button>
                <div data-w-demo-status hidden></div>
                <script type="application/json" id="w-datatable-demo-config">
                    {"apiProvider":"datatable","clearConfirm":"Confirm?","requestFailed":"Failed"}
                </script>
            </main>
        `;
        const resource = {
            initData: vi.fn().mockResolvedValue({success: true, message: 'Initialized', data: {users: 2, products: 3, orders: 4}}),
            clearData: vi.fn().mockResolvedValue({success: true, message: 'Cleared'}),
        };
        window.Weline = {
            Api: {resource: vi.fn().mockResolvedValue(resource)},
            UI: {
                icon: {create: vi.fn(() => document.createElement('span'))},
                dialog: {confirm: vi.fn().mockResolvedValue(true)},
            },
        };

        await import('../../../../../app/code/Weline/DataTable/view/statics/js/demo-index.js');
        document.querySelector('[data-w-demo-action="init"]').click();
        await vi.waitFor(() => expect(resource.initData).toHaveBeenCalledWith({}, {silent: true}));
        expect(document.querySelector('[data-w-demo-status]').textContent).toContain('Initialized');

        document.querySelector('[data-w-demo-action="clear"]').click();
        await vi.waitFor(() => expect(resource.clearData).toHaveBeenCalledWith({}, {silent: true}));
        expect(window.Weline.UI.dialog.confirm).toHaveBeenCalledWith('Confirm?');
        expect(document.querySelector('[data-w-demo-status]').textContent).toContain('Cleared');
    });

    it('keeps backend verification output functional without inline scripts', async () => {
        document.body.innerHTML = `
            <main data-w-datatable-verification>
                <button type="button" data-w-datatable-verify data-section="attribute_inheritance">Verify</button>
                <pre data-w-datatable-verify-output></pre>
                <script type="application/json" id="w-datatable-admin-config">
                    {"verifyUrl":"/backend/verify","requestFailed":"Failed"}
                </script>
            </main>
        `;
        const get = vi.fn().mockResolvedValue({
            success: true,
            data: {sections: {attribute_inheritance: {model: {status: 'success'}}}},
        });
        window.Weline = {Api: {get}, UI: {toast: {show: vi.fn()}}};

        await import('../../../../../app/code/Weline/DataTable/view/statics/js/demo-admin.js');
        document.querySelector('[data-w-datatable-verify]').click();

        await vi.waitFor(() => expect(get).toHaveBeenCalledWith('/backend/verify', {silent: true}));
        await vi.waitFor(() => {
            expect(document.querySelector('[data-w-datatable-verify-output]').textContent).toContain('"status": "success"');
        });
    });
});
