'use strict';

/**
 * listed-accordion：定价三元组 + 经济学/运维次要信息。
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../../view/statics/backend/listed-accordion.js');

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
  const api = context.WelineDropshipListedAccordion || context.window.WelineDropshipListedAccordion;
  assert.ok(api && typeof api.renderDetail === 'function', 'renderDetail must be exported');
  return api;
}

const i18n = {
  loadFailed: 'Load failed',
  noLocal: 'No local',
  noVariants: 'No offers',
  localSale: '本地售价',
  origin: '货源原价',
  uplift: '涨价比例',
  product: '主产品',
  variantsTitle: '规格与定价',
  configurable: '多规格',
  simple: '简单品',
  colImage: '图',
  colVariant: '规格',
  colSku: 'SKU',
  colOffer: '货源原价',
  colPrice: '售价',
  colUplift: '涨幅',
  colStatus: '状态',
  cost: '换汇成本',
  margin: '毛利',
  originChange: '货源变动',
  synced: '上次同步',
  priceLock: '售价已锁',
  defaultVariant: '默认规格',
  statusDraft: '草稿',
  statusPublished: '已发布',
  statusDisabled: '已下架',
  statusArchived: '已归档',
};

test('pricing trio and economics secondary are rendered', () => {
  const api = loadApi();
  const html = api.renderDetail({
    ok: true,
    has_local: true,
    is_configurable: false,
    product: {
      sku: 'DS-CJ-CJYD3153518',
      product_type: 'simple',
      status: 'published',
      status_label: '已发布',
      status_tone: 'success',
    },
    sale: { amount_minor: 2194, currency: 'CNY', uplift_percent: 30, compare_amount_minor: 274, compare_currency: 'USD' },
    origin: { amount_minor: 211, currency: 'USD', prev_minor: 250 },
    economics: {
      cost_minor: 1500,
      cost_currency: 'CNY',
      margin_minor: 694,
      margin_percent: 46.3,
      origin_direction: 'down',
      origin_delta_percent: -15.6,
    },
    ops: {
      price_lock: true,
      last_synced_at: '2026-09-11 12:00:00',
      price_drop_tip: '远程降价提示',
    },
    variants: [{
      offer_id: 1,
      sku: 'DS-CJ-CJYD3153518',
      label: '默认规格',
      amount_minor: 2194,
      currency: 'CNY',
      compare_amount_minor: 274,
      compare_currency: 'USD',
      status: 'published',
      status_label: '已发布',
      image_url: '',
    }],
  }, i18n);

  assert.match(html, /data-testid="dropship-listed-detail-pricing"/);
  assert.match(html, /data-testid="dropship-listed-detail-origin-change"/);
  assert.match(html, /data-testid="dropship-listed-detail-economics"/);
  assert.match(html, /data-testid="dropship-listed-detail-cost"/);
  assert.match(html, /data-testid="dropship-listed-detail-margin"/);
  assert.match(html, /data-testid="dropship-listed-detail-ops"/);
  assert.match(html, /data-testid="dropship-listed-detail-price-lock"/);
  assert.match(html, /data-testid="dropship-listed-detail-tip"/);
  assert.match(html, /data-testid="dropship-listed-sale-compare"/);
  assert.match(html, /data-testid="dropship-listed-detail-product-status"/);
  assert.match(html, /已发布/);
  assert.doesNotMatch(html, /data-testid="dropship-listed-detail-product-status"[^>]*>published</);
  assert.match(html, /\+30%/);
  assert.match(html, /-15\.6%/);
  assert.match(html, /DS-CJ-CJYD3153518/);
  assert.match(html, /≈/);

  const offerCell = html.match(/data-testid="dropship-listed-variant-offer"[^>]*>([\s\S]*?)<\/td>/);
  assert.ok(offerCell);
  assert.match(offerCell[1], /US\$|USD|2\.11/);
  assert.doesNotMatch(offerCell[1], /DS-CJ/);

  const priceCell = html.match(/data-testid="dropship-listed-variant-price"[^>]*>([\s\S]*?)<\/td>/);
  assert.ok(priceCell);
  assert.match(priceCell[1], /data-testid="dropship-listed-sale-compare"/);
  assert.match(priceCell[1], /US\$|USD|2\.74/);
});

test('configurable shows variant label and pricing columns', () => {
  const api = loadApi();
  const html = api.renderDetail({
    ok: true,
    has_local: true,
    is_configurable: true,
    product: { sku: 'CFG-PARENT', product_type: 'configurable', status: 'published' },
    sale: { amount_minor: 10868, currency: 'CNY', uplift_percent: 30 },
    origin: { amount_minor: 800, currency: 'USD' },
    economics: {
      cost_minor: 5800,
      cost_currency: 'CNY',
      margin_minor: 5068,
      margin_percent: 87.4,
      origin_direction: 'same',
      origin_delta_percent: null,
    },
    ops: { price_lock: false, last_synced_at: '', price_drop_tip: '' },
    variants: [{
      offer_id: 2,
      sku: 'SKU-A',
      label: 'Groove B · L',
      amount_minor: 10868,
      currency: 'CNY',
      status: 'published',
      image_url: '',
    }],
  }, i18n);

  assert.match(html, /CFG-PARENT/);
  assert.match(html, /多规格/);
  assert.match(html, />规格</);
  assert.match(html, /Groove B · L/);
  assert.match(html, /SKU-A/);
  assert.match(html, /data-testid="dropship-listed-variant-offer"/);
  assert.match(html, /data-testid="dropship-listed-variant-uplift"/);
  assert.match(html, /data-testid="dropship-listed-detail-economics"/);
});
