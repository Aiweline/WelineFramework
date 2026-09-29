const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const project = path.resolve(__dirname, '../../../../../../..');
const loaderSource = fs.readFileSync(path.join(project, 'app/code/Weline/Frontend/view/statics/js/weline.js'), 'utf8');
const moduleLoader = loaderSource.slice(loaderSource.indexOf('    class ModuleLoader {'), loaderSource.indexOf('    const moduleLoader = new ModuleLoader();'));
const declareStart = loaderSource.indexOf('        declare: async (');
const declare = loaderSource.slice(declareStart, loaderSource.indexOf('\n        /**', declareStart));
const sources = [
    'app/design/Weline/daocharms/frontend/assets/js/daocharms-hero-carousel.js',
    'app/design/Weline/daocharms/Weline/Theme/view/theme/frontend/assets/js/daocharms-hero-carousel.js',
];

function element(attributes = {}, initialClasses = []) {
    const attrs = { ...attributes };
    const classes = new Set(initialClasses);
    const listeners = {};
    return {
        hidden: true,
        getAttribute: name => attrs[name] ?? null,
        setAttribute: (name, value) => { attrs[name] = value; },
        removeAttribute: name => { delete attrs[name]; },
        classList: {
            contains: name => classes.has(name),
            toggle: (name, active) => active ? classes.add(name) : classes.delete(name),
        },
        addEventListener: (name, callback) => { listeners[name] = callback; },
        dispatch: (name, event = {}) => listeners[name]?.(event),
    };
}

function runtime(withCarousel) {
    const requests = [];
    const intervals = new Map();
    let nextTimer = 0;
    const slides = [0, 1, 2].map(index => {
        const slide = element({ hidden: '' }, index === 0 ? ['is-active'] : []);
        slide.title = element();
        slide.link = element();
        slide.querySelector = selector => selector === '.daocharms-hero__title' ? slide.title : null;
        slide.querySelectorAll = () => [slide.link];
        return slide;
    });
    const dots = [0, 1, 2].map(index => element({ 'data-carousel-go': String(index) }));
    const prev = element();
    const next = element();
    const controls = element();
    const root = element();
    root.querySelectorAll = selector => selector === '.daocharms-carousel__slide' ? slides : dots;
    root.querySelector = selector => ({
        '[data-carousel-prev]': prev,
        '[data-carousel-next]': next,
        '.daocharms-carousel__controls': controls,
    })[selector] || null;
    root.contains = node => node === root;
    const document = element();
    document.readyState = 'loading';
    document.querySelectorAll = selector => withCarousel && selector === '[data-daocharms-carousel="1"]' ? [root] : [];
    document.createElement = () => ({});
    document.head = {
        appendChild(script) {
            requests.push(script.src);
            queueMicrotask(() => script.onerror());
        },
    };
    const window = {
        document,
        location: new URL('https://shop.example/daocharms/'),
        matchMedia: () => ({ matches: false }),
        setTimeout, clearTimeout,
        setInterval(callback) { const id = ++nextTimer; intervals.set(id, callback); return id; },
        clearInterval: id => intervals.delete(id),
    };
    const context = vm.createContext({
        window, document, URL, console,
        runtimeConfig: { modulesBaseUrl: '/Weline/Frontend/view/statics/js/weline-api' },
        __: text => text,
    });
    // Execute the production loader and declare implementation, not a fake URL resolver.
    vm.runInContext(moduleLoader + '\nconst moduleLoader = new ModuleLoader();\nwindow.Weline = {' + declare + '};', context);
    return { context, document, root, controls, slides, dots, prev, next, intervals, requests };
}

for (const source of sources) {
    test(source + ': carousel remains interactive without requesting another module script', async () => {
        const env = runtime(true);
        vm.runInContext(fs.readFileSync(path.join(project, source), 'utf8'), env.context, { filename: source });
        env.document.dispatch('DOMContentLoaded');
        assert.equal(env.root.getAttribute('data-carousel-ready'), '1');
        assert.equal(env.controls.hidden, false);
        const active = () => env.slides.findIndex(slide => slide.classList.contains('is-active'));
        assert.equal(active(), 0);
        env.next.dispatch('click');
        assert.equal(active(), 1);
        assert.equal(env.slides[0].getAttribute('aria-hidden'), 'true');
        assert.equal(env.slides[0].link.getAttribute('tabindex'), '-1');
        assert.equal(env.slides[1].link.getAttribute('tabindex'), null);
        env.prev.dispatch('click');
        assert.equal(active(), 0);
        env.dots[2].dispatch('click');
        assert.equal(active(), 2);
        assert.equal(env.dots[2].getAttribute('aria-current'), 'true');
        env.root.dispatch('keydown', { key: 'ArrowRight', preventDefault() {} });
        assert.equal(active(), 0);
        assert.equal(env.intervals.size, 1);
        [...env.intervals.values()][0]();
        assert.equal(active(), 1);
        env.root.dispatch('mouseenter');
        assert.equal(env.intervals.size, 0);
        env.root.dispatch('mouseleave');
        assert.equal(env.intervals.size, 1);
        await new Promise(resolve => setImmediate(resolve));
        assert.deepEqual(env.requests, [], 'the theme asset is already executing; no second module resource is needed');
    });

    test(source + ': a page without the carousel issues no carousel module request', async () => {
        const env = runtime(false);
        vm.runInContext(fs.readFileSync(path.join(project, source), 'utf8'), env.context, { filename: source });
        env.document.dispatch('DOMContentLoaded');
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(env.intervals.size, 0);
        assert.deepEqual(env.requests, []);
    });
}
