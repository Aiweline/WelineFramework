'use strict';

// Runs the public selector's emitted script. Reading the old hidden value when
// selecting a new option must fail the visible-label and event-state checks.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
  constructor(dataset = {}) {
    this.dataset = dataset;
    this.attributes = {};
    this.listeners = {};
    this.classes = new Set();
    this.value = '';
    this.classList = {
      toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name),
      contains: name => this.classes.has(name),
      add: name => this.classes.add(name),
      remove: name => this.classes.delete(name),
    };
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
  dispatchEvent(event) { for (const listener of this.listeners[event.type] || []) listener(event); }
  focus() {}
}

const options = [
  new Element({value: 'default.default.default', label: 'Global', displayLabel: 'Global', titleLabel: 'All websites, stores and channels'}),
  new Element({value: 'default.__website__.default', label: 'Website', displayLabel: '网站：长安汉服', titleLabel: '网站：长安汉服完整名称'}),
  new Element({value: 'default.main.default', label: 'Store', displayLabel: '店铺：主店', titleLabel: '店铺：长安汉服 / 主店完整名称'}),
];
const elements = Object.fromEntries(['scope_container', 'scope', 'scope_trigger', 'scope_display', 'scope_dropdown', 'scope_tree', 'scope_empty'].map(id => [id, new Element()]));
elements.scope.value = 'default.default.default';
elements.scope_tree.querySelectorAll = () => options;
const document = {getElementById: id => elements[id] || null, addEventListener() {}};
const window = {addEventListener() {}};
const source = fs.readFileSync(path.join(__dirname, '../Taglib/Scope.php'), 'utf8');
const emitted = source.match(/<script>\(function\(\)\{([\s\S]*?)\}\)\(\);<\/script>/);
assert.ok(emitted, 'the selector emits its initialization script');
const script = '(function(){' + emitted[1].replace(/var id = <\?=[^\n]*\?>;/, 'var id = "scope";') + '})();';
vm.runInNewContext(script, {document, window, Event: class {constructor(type) {this.type = type;}}});

function assertSelection(value, text, title) {
  assert.equal(window.WelineScopeSelect.scope.getValue(), value);
  assert.equal(elements.scope_container.dataset.value, value);
  assert.equal(elements.scope_display.textContent, text, 'display must describe the new value immediately');
  for (const id of ['scope_display', 'scope_trigger', 'scope_container']) assert.equal(elements[id].attributes.title, title, id + ' title');
  for (const option of options) {
    assert.equal(option.classList.contains('selected'), option.dataset.value === value, 'selected class');
    assert.equal(option.attributes['aria-selected'], String(option.dataset.value === value), 'aria-selected');
  }
}

const observed = [];
for (const type of ['input', 'change']) elements.scope.addEventListener(type, () => {
  const selected = options.find(option => option.dataset.value === elements.scope.value);
  assertSelection(selected.dataset.value, selected.dataset.displayLabel, selected.dataset.titleLabel);
  observed.push(type);
});

assertSelection('default.default.default', 'Global', 'All websites, stores and channels');
// Two synchronous option activations exercise the same path as pointer selection.
for (const index of [1, 2]) elements.scope_tree.dispatchEvent({type: 'click', target: {closest(selector) {
  return selector === '[data-w-scope-node]' ? options[index] : selector === '[data-w-scope-option]' ? this : null;
}}});
assertSelection('default.main.default', '店铺：主店', '店铺：长安汉服 / 主店完整名称');
assert.deepEqual(observed, ['input', 'change', 'input', 'change']);
assert.equal(window.WelineScopeSelect.scope.setValue('default.default.default', false), true);
assertSelection('default.default.default', 'Global', 'All websites, stores and channels');
assert.equal(observed.length, 4, 'silent restoration does not fire change events');
assert.equal(window.WelineScopeSelect.scope.setValue('unknown.scope', true), false);
assertSelection('default.default.default', 'Global', 'All websites, stores and channels');
console.log('PASS Scope selection: immediate labels/titles, two rapid selections, silent restoration, selected state and events');
