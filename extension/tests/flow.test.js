'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const schema = require('../shared/schema.js');
const snapshot = require('../content/snapshot.js');

function element(options) {
  const config = options || {};
  const attrs = config.attrs || {};
  const node = {
    tagName: String(config.tag || 'button').toUpperCase(),
    type: config.type || '',
    disabled: config.disabled === true,
    hidden: config.hidden === true,
    innerText: config.text || '',
    textContent: config.text || '',
    getAttribute(name) {
      if (name === 'type') {
        return config.type || attrs.type || null;
      }
      return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null;
    },
    hasAttribute(name) {
      return node.getAttribute(name) !== null;
    },
    getClientRects() {
      return [{ width: 1, height: 1 }];
    },
  };
  return node;
}

function documentWith(nodes) {
  return {
    location: { href: 'https://www.facebook.com/weline/posts/create' },
    title: '创建帖子',
    body: { innerText: '创建帖子\n发布\n图片' },
    querySelectorAll() {
      return nodes;
    },
  };
}

function interactiveFixture() {
  return [
    element({ tag: 'button', text: '发布' }),
    element({ tag: 'a', attrs: { href: '/weline' }, text: '个人主页' }),
    element({ tag: 'input', type: 'file', attrs: { 'aria-label': '上传图片' } }),
    element({ tag: 'input', type: 'text', attrs: { placeholder: '写点什么' } }),
    element({ tag: 'input', type: 'password', attrs: { 'aria-label': '密码' } }),
    element({ tag: 'button', text: '隐藏按钮', hidden: true }),
    element({ tag: 'button', text: '不可用按钮', disabled: true }),
  ];
}

test('动作白名单固定为 9 种', function () {
  assert.deepEqual(Array.from(schema.ACTIONS), [
    'navigate',
    'click',
    'type',
    'upload',
    'scroll',
    'wait',
    'assert',
    'done',
    'give_up',
  ]);
  assert.equal(schema.validateAction({ type: 'eval_js', code: '1' }).ok, false);
  assert.equal(schema.validateAction({ type: 'click' }).ok, false);
  assert.equal(schema.validateAction({ type: 'click', handle: 1 }).ok, true);
  assert.equal(schema.validateAction({ type: 'click', role: 'button', label: '发布' }).ok, true);
});

test('upload 只接受 jpeg/png/mp4', function () {
  assert.deepEqual(Array.from(schema.UPLOAD_MIME_WHITELIST), ['image/jpeg', 'image/png', 'video/mp4']);
  assert.equal(schema.isUploadMimeAllowed('image/jpeg'), true);
  assert.equal(schema.isUploadMimeAllowed('image/png'), true);
  assert.equal(schema.isUploadMimeAllowed('video/mp4'), true);
  assert.equal(schema.isUploadMimeAllowed('image/gif'), false);
  assert.equal(schema.isUploadMimeAllowed('application/pdf'), false);
  assert.equal(
    schema.validateAction({
      type: 'upload',
      handle: 2,
      mime: 'image/gif',
      data_url: 'data:image/gif;base64,AA==',
    }).ok,
    false
  );
  assert.equal(
    schema.validateAction({
      type: 'upload',
      handle: 2,
      mime: 'image/png',
      data_url: 'data:image/png;base64,AA==',
    }).ok,
    true
  );
  assert.equal(
    schema.validateAction({
      type: 'upload',
      handle: 2,
      mime: 'image/png',
      data_url: 'AA==',
    }).ok,
    true
  );
  assert.equal(
    schema.validateAction({
      type: 'upload',
      handle: 2,
      asset: { mime: 'image/png', data_url: 'data:image/png;base64,AA==' },
    }).ok,
    true
  );
  assert.equal(
    schema.validateAction({
      type: 'upload',
      handle: 2,
      asset: { mime: 'image/gif', data_url: 'data:image/gif;base64,AA==' },
    }).ok,
    false
  );
});

test('快照只保留可见可交互元素并按 handle 自增', function () {
  const captured = snapshot.collectSnapshot(documentWith(interactiveFixture()));

  assert.equal(captured.url, 'https://www.facebook.com/weline/posts/create');
  assert.match(captured.visible_text, /创建帖子/);
  assert.equal(captured.elements.length, 4);
  assert.deepEqual(captured.elements.map(function (item) { return item.handle; }), [1, 2, 3, 4]);
  assert.deepEqual(captured.elements.map(function (item) { return item.tag; }), ['button', 'a', 'input', 'input']);
  assert.equal(captured.elements[2].role, 'file');
  assert.equal(captured.elements[2].label, '上传图片');
  assert.equal(snapshot.resolveHandle(1) !== null, true);
  assert.equal(snapshot.resolveHandle(99), null);
  assert.equal(snapshot.findBySemantic({ role: 'button', label: '发布' }) !== null, true);
  assert.equal(schema.validateSnapshot(captured).ok, true);
});

test('快照结构校验拒绝重复 handle 和非法 URL', function () {
  const captured = snapshot.collectSnapshot(documentWith(interactiveFixture()));
  const duplicate = {
    ...captured,
    elements: [
      { handle: 1, role: 'button', label: '发布', tag: 'button' },
      { handle: 1, role: 'link', label: '主页', tag: 'a' },
    ],
  };
  assert.equal(schema.validateSnapshot(duplicate).ok, false);
  assert.equal(schema.validateSnapshot({ ...captured, url: 'http://x.com/' }).ok, false);
});

test('快照包含非密码字段当前值', function () {
  const input = element({ tag: 'input', type: 'text', attrs: { placeholder: '写点什么' } });
  input.value = '草稿';
  const captured = snapshot.collectSnapshot(documentWith([input]));
  assert.equal(captured.elements.length, 1);
  assert.equal(captured.elements[0].value, '草稿');
});

test('扁平动作：handle 归一为 int，assert 支持 expect-only', function () {
  assert.equal(schema.normalizeHandle('7'), 7);
  assert.equal(schema.normalizeTarget({ handle: '7' }).handle, 7);
  assert.equal(schema.validateAction({ type: 'click', handle: '7' }).ok, true);
  assert.equal(schema.validateAction({ type: 'assert', expect: '已发布' }).ok, true);
  assert.equal(schema.validateAction({ type: 'assert', handle: 3, expect: '已发布' }).ok, true);
  assert.equal(schema.validateAction({ type: 'assert' }).ok, false);
  assert.equal(schema.validateAction({ type: 'assert', expect: 123 }).ok, false);
  assert.equal(schema.validateAction({ type: 'done', remote_url: 'https://x.com/u/status/1' }).ok, true);
  assert.equal(schema.validateAction({ type: 'give_up', reason: 'recipe_missing' }).ok, true);
});

test('其余动作参数校验', function () {
  assert.equal(schema.validateAction({ type: 'navigate', url: 'https://x.com/compose/post' }).ok, true);
  assert.equal(schema.validateAction({ type: 'navigate', url: 'https://www.facebook.com/example/posts/create' }).ok, true);
  assert.equal(schema.validateAction({ type: 'navigate', url: 'http://x.com/compose/post' }).ok, false);
  assert.equal(schema.validateAction({ type: 'navigate', url: 'https://evil.example/post' }).ok, false);
  assert.equal(schema.validateAction({ type: 'navigate', url: '/compose/post' }).ok, true);
  assert.equal(schema.validateAction({ type: 'navigate', url: '//evil.example/post' }).ok, false);
  assert.equal(schema.validateAction({ type: 'type', handle: 4, text: 'hello' }).ok, true);
  assert.equal(schema.validateAction({ type: 'type', handle: 4 }).ok, false);
  assert.equal(schema.validateAction({ type: 'scroll', direction: 'down', delta: 600 }).ok, true);
  assert.equal(schema.validateAction({ type: 'scroll', direction: 'left' }).ok, true);
  assert.equal(schema.validateAction({ type: 'scroll', delta: 99999 }).ok, false);
  assert.equal(schema.validateAction({ type: 'wait', ms: 500 }).ok, true);
  assert.equal(schema.validateAction({ type: 'wait', ms: 31000 }).ok, false);
  assert.equal(schema.validateAction({ type: 'assert', handle: 1, expect: '发布' }).ok, true);
  assert.equal(schema.validateAction({ type: 'done', remote_url: 'https://x.com/u/status/1' }).ok, true);
  assert.equal(schema.validateAction({ type: 'give_up', reason: '需要验证码' }).ok, true);
  assert.equal(schema.validateAction({ type: 'give_up', reason: '' }).ok, false);
});
