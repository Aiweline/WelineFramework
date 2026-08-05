'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {
    createLocaleBuffer,
    slugifyEnglish,
    resolveSlugUpdate,
    missingLocales,
    mergeTranslationResults,
    normalizeRuntimeTaskHandle,
    runtimeResultData,
    runTranslationTask,
} = require('../../view/statics/js/cms-page-editor.js');

test('locale buffer preserves unsaved titles while switching back and forth', () => {
    const buffer = createLocaleBuffer({
        en_US: 'About',
        ru_RU: 'О нас',
    }, 'ru_RU');

    assert.equal(buffer.switchTo('en_US', 'Несохранённый русский'), 'About');
    assert.equal(buffer.switchTo('ru_RU', 'About edited'), 'Несохранённый русский');
    assert.deepEqual(buffer.toObject(), {
        en_US: 'About edited',
        ru_RU: 'Несохранённый русский',
    });
});

test('english source title updates only automatic slug', () => {
    assert.equal(slugifyEnglish(' About Our Team! '), 'about-our-team');
    assert.equal(resolveSlugUpdate({
        mode: 'auto',
        locale: 'en_US',
        sourceLocale: 'en_US',
        title: 'About Our Team',
        currentSlug: 'old-title',
    }), 'about-our-team');
    assert.equal(resolveSlugUpdate({
        mode: 'manual',
        locale: 'en_US',
        sourceLocale: 'en_US',
        title: 'About Our Team',
        currentSlug: 'editor-choice',
    }), 'editor-choice');
    assert.equal(resolveSlugUpdate({
        mode: 'auto',
        locale: 'ru_RU',
        sourceLocale: 'en_US',
        title: 'О нас',
        currentSlug: 'about',
    }), 'about');
});

test('missing locale detection excludes source and already authored titles', () => {
    assert.deepEqual(missingLocales(
        ['en_US', 'zh_Hans_CN', 'ru_RU', 'zh_Hans_CN'],
        { en_US: 'About', zh_Hans_CN: '关于', ru_RU: '   ' },
        'en_US',
    ), ['ru_RU']);
});

test('translation results fill blanks but preserve unsaved and authored titles', () => {
    const buffer = createLocaleBuffer({
        en_US: 'About',
        zh_Hans_CN: '',
        ru_RU: 'О компании',
    }, 'zh_Hans_CN');

    const currentValue = mergeTranslationResults(buffer, {
        zh_Hans_CN: { status: 'saved', title: '关于我们' },
        ru_RU: { status: 'saved', title: 'Перезаписано' },
        fr_FR: { status: 'already_filled', title: '' },
    }, '我正在手工输入');

    assert.equal(currentValue, '我正在手工输入');
    assert.deepEqual(buffer.toObject(), {
        en_US: 'About',
        zh_Hans_CN: '我正在手工输入',
        ru_RU: 'О компании',
    });
});

test('runtime task helpers unwrap API envelopes and expose completed result data', () => {
    assert.deepEqual(normalizeRuntimeTaskHandle({ data: { task: {
        task_id: 'task-1',
        lease_id: 'lease-1',
        stream_channel: 'runtime.task-1',
    } } }), {
        task_id: 'task-1',
        lease_id: 'lease-1',
        stream_channel: 'runtime.task-1',
    });
    assert.deepEqual(runtimeResultData({ data: { result: { data: { results: { zh_Hans_CN: { status: 'saved' } } } } } }), {
        results: { zh_Hans_CN: { status: 'saved' } },
    });
});

test('one-click translation uses runtime_task resource and reaches terminal status', async () => {
    const calls = [];
    let statusIndex = 0;
    const snapshots = [
        { status: 'running', checkpoint: { state: { next_index: 0, target_locales: ['zh_Hans_CN'] } } },
        { status: 'completed', result: { data: { results: { zh_Hans_CN: { status: 'saved', title: '关于我们' } } } } },
    ];
    const taskApi = {
        async start(payload) {
            calls.push(['start', payload]);
            return { data: { task_id: 'task-1', lease_id: 'lease-1' } };
        },
        async touch(payload) {
            calls.push(['touch', payload]);
        },
        async status(payload) {
            calls.push(['status', payload]);
            return { data: snapshots[statusIndex++] };
        },
    };
    const api = {
        resource(name) {
            calls.push(['resource', name]);
            assert.equal(name, 'runtime_task');
            return taskApi;
        },
    };

    const outcome = await runTranslationTask({
        api,
        pageId: 6,
        requestId: 'cms-translation-test',
        sleep: async () => {},
        now: (() => {
            let time = 0;
            return () => (time += 1000);
        })(),
    });

    assert.equal(outcome.snapshot.status, 'completed');
    assert.equal(outcome.data.results.zh_Hans_CN.title, '关于我们');
    assert.deepEqual(calls[1], ['start', {
        type_code: 'cms.page_translation',
        input: { page_id: 6, request_id: 'cms-translation-test' },
    }]);
    assert.equal(calls.filter(([name]) => name === 'status').length, 2);
    assert.equal(calls.filter(([name]) => name === 'touch').length, 1);
});
