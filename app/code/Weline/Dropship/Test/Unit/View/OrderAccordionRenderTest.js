'use strict';

/**
 * order-accordion：商品列缩略图渲染。
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../../view/statics/backend/order-accordion.js');

function loadApi() {
  const source = fs.readFileSync(sourcePath, 'utf8');
  const document = {
    readyState: 'complete',
    querySelectorAll() { return []; },
    addEventListener() {},
  };
  const context = { console, document };
  context.window = context;
  context.global = context;
  vm.createContext(context);
  vm.runInContext(source, context, { filename: sourcePath });
  const api = context.WelineDropshipOrderAccordion || context.window.WelineDropshipOrderAccordion;
  assert.ok(api && typeof api.renderLines === 'function', 'renderLines must be exported');
  return api;
}

const i18n = {
  loadFailed: 'Load failed',
  noLines: 'No products',
  productsTitle: '订单商品',
  colName: '商品',
  colSku: '货号',
  colQty: '数量',
  colUnit: '单价',
  colRow: '小计',
};

test('order line with image_url renders thumb beside name', () => {
  const api = loadApi();
  const html = api.renderLines({
    ok: true,
    lines: [{
      name: '龙纹书籍',
      sku: 'DS-CJ-CJYD3153518',
      qty: 3,
      unit_price_display: 'CNY 21.94',
      row_total_display: 'CNY 65.82',
      image_url: 'https://example.test/thumb.jpeg',
    }],
  }, i18n);

  assert.match(html, /data-testid="dropship-order-line-thumb"/);
  assert.match(html, /src="https:\/\/example\.test\/thumb\.jpeg"/);
  assert.match(html, /ds-order-line__product/);
  assert.match(html, /龙纹书籍/);
  assert.doesNotMatch(html, /data-testid="dropship-order-line-thumb-ph"/);
});

test('order line without image renders placeholder', () => {
  const api = loadApi();
  const html = api.renderLines({
    ok: true,
    lines: [{
      name: '无图商品',
      sku: 'X',
      qty: 1,
      unit_price_display: '—',
      row_total_display: '—',
      image_url: '',
    }],
  }, i18n);

  assert.match(html, /data-testid="dropship-order-line-thumb-ph"/);
  assert.doesNotMatch(html, /data-testid="dropship-order-line-thumb"/);
});
