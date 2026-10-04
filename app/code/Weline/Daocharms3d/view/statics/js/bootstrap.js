(function (global) {
    'use strict';
    const source = document.currentScript.src;
    global.WelineDaocharmsTown = { ready: null };
    const boot = async function () {
        const root = document.querySelector('[data-daocharms-town]');
        if (!root || root.dataset.booted) return;
        root.dataset.booted = '1';
        try {
            const sourceUrl = new URL(source);
            const sceneUrl = new URL('./town.js', sourceUrl);
            sceneUrl.search = sourceUrl.search;
            const scene = await import(sceneUrl.href);
            await scene.mount(root);
        } catch (error) {
            const status = root.querySelector('[data-town-status]');
            status.textContent = root.dataset.fallback;
            status.hidden = false;
            console.error('[DaoCharms3d]', error);
        }
    };
    global.WelineDaocharmsTown.ready = document.readyState === 'loading'
        ? new Promise(resolve => document.addEventListener('DOMContentLoaded', () => resolve(boot()), { once: true }))
        : boot();
}(window));
