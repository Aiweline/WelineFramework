const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loaderFixture(globalVar, preRegistered = false) {
    const exported = { boot() {} };
    const inserted = [];
    let listenerRegistrations = 0;
    const window = {
        location: new URL('https://shop.test/product'),
        setTimeout, clearTimeout, addEventListener() {}, dispatchEvent() {},
        WelineModulesConfig: { modules: { fixture: { paths: ['/fixture.js'], globalVar } } },
    };
    function installExport() {
        const keys = globalVar.split('.');
        let owner = window;
        for (const key of keys.slice(0, -1)) owner = owner[key] ||= {};
        owner[keys.at(-1)] = exported;
    }
    if (preRegistered) installExport();
    const document = {
        readyState: 'loading', documentElement: {}, body: {},
        addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; },
        createElement() { return { setAttribute() {} }; },
        head: { appendChild(script) {
            inserted.push(script);
            setImmediate(() => {
                installExport();
                listenerRegistrations++;
                script.onload();
            });
        } },
    };
    const context = vm.createContext({ window, document, URL, console, setTimeout, clearTimeout });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../../view/statics/js/weline.js'), 'utf8'), context);
    return { loader: window.Weline, exported, inserted, listenerRegistrations: () => listenerRegistrations };
}

test('pre-registered namespace export resolves without loading or reinitializing its script', async () => {
    const fixture = loaderFixture('Business.Modules.share', true);
    assert.equal(await fixture.loader.load('fixture'), fixture.exported);
    assert.equal(fixture.inserted.length, 0);
    assert.equal(fixture.listenerRegistrations(), 0);
});

test('namespace export loads once for concurrent and repeated callers', async () => {
    const fixture = loaderFixture('Business.Modules.share');
    const results = await Promise.all([fixture.loader.load('fixture'), fixture.loader.load('fixture')]);
    assert.equal(results[0], fixture.exported);
    assert.equal(results[1], fixture.exported);
    assert.equal(await fixture.loader.load('fixture'), fixture.exported);
    assert.equal(fixture.inserted.length, 1);
    assert.equal(fixture.listenerRegistrations(), 1);
});

test('single-segment global exports retain the normal load behavior', async () => {
    const fixture = loaderFixture('FixtureModule');
    assert.equal(await fixture.loader.load('fixture'), fixture.exported);
    assert.equal(await fixture.loader.load('fixture'), fixture.exported);
    assert.equal(fixture.inserted.length, 1);
    assert.equal(fixture.listenerRegistrations(), 1);
});
