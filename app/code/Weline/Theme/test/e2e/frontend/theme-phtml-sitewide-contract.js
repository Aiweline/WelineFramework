// Pure comparison of independently frozen source assertions with real Browser observations.

function isSlotHost(node) {
  const attrs = node.attributes;
  const classes = (attrs.class || '').split(/\s+/);
  return !classes.includes('widget-wrapper') && (!!attrs['data-wslot'] || (classes.includes('theme-published-slot') && !!attrs['data-slot-id']));
}

function isDeclaredSlotParent(node) {
  return !(node.attributes.class || '').split(/\s+/).includes('widget-wrapper')
    && !!(node.attributes['data-wslot'] || node.attributes['data-slot-id']);
}

function matchNode(node, match) {
  const attrs = node.attributes;
  return Object.entries(match || {}).every(([key, value]) => {
    // SlotBoundaryMarkers::stripReactiveSlotAttributes promotes data-wslot to
    // data-slot-id + theme-published-slot for the formal document.
    if (key === 'slot_host') return isSlotHost(node) && (attrs['data-wslot'] === String(value) || attrs['data-slot-id'] === String(value));
    if (key === 'class_token') return (attrs.class || '').split(/\s+/).includes(String(value));
    const names = { module: 'data-widget-module', type: 'data-widget-type', code: 'data-widget-code', uid: 'data-node-uid', slot: 'data-slot-id' };
    if (key === 'slot') return attrs['data-slot-id'] === String(value) || attrs['data-wslot'] === String(value);
    return attrs[names[key] || key] === String(value);
  });
}

// Explicit source assertions are separate from observed DOM. Unresolved PHP/Hook/business
// conditions remain unverified; observations never rewrite or relax their expectations.
function verifyContract(contract, dom) {
  const assertions = contract.page?.browser_assertions;
  if (!contract.available || !assertions) return { result: 'not_evaluated', reason: 'source browser assertions not frozen', checks: [], unresolved: contract.page?.unresolved_conditions || [] };
  const checks = [];
  const references = new Map();
  const nodes = new Map(dom.topology.map(node => [node.index, node]));
  const elementNodes = new Map([...dom.topology, ...dom.wrappers, ...dom.slots, ...(dom.source_roots || [])].map(node => [node.index, node]));
  for (const assertion of [...(assertions.widgets || []), ...(assertions.slots || [])]) {
    if (assertion.applicability?.state !== 'applies' && assertion.applicability?.state !== 'absent') {
      checks.push({ id: assertion.id, result: 'not_evaluated', reason: 'applicability not resolved from independent source', applicability: assertion.applicability });
      continue;
    }
    const pool = assertion.node_kind === 'element' ? Array.from(elementNodes.values()) : (assertions.slots || []).includes(assertion) ? dom.slots : dom.wrappers;
    const matches = pool.filter(node => matchNode(node, assertion.match)).filter(node => {
      if (!assertion.parent_widget && !assertion.parent_slot) return true;
      const parents = [];
      for (let parent = nodes.get(node.parent); parent; parent = nodes.get(parent.parent)) parents.push(parent);
      const wrapper = parents.find(parent => (parent.attributes.class || '').split(/\s+/).includes('widget-wrapper'));
      // Literal template hosts (e.g. DaoCharms' explicit forced-footer wrapper)
      // can carry data-slot-id without the formal promotion class. The source
      // parent predicate still decides whether that exact host is acceptable.
      const slot = parents.find(isDeclaredSlotParent);
      return (!assertion.parent_widget || (wrapper && matchNode(wrapper, assertion.parent_widget)))
        && (!assertion.parent_slot || (slot && matchNode(slot, assertion.parent_slot)));
    });
    references.set(assertion.id, matches);
    const expected = assertion.applicability.state === 'absent' ? 0 : assertion.count;
    const countValid = Number.isInteger(expected) && matches.length === expected;
    const visibilityValid = assertion.visible === undefined || matches.every(node => node.visible === assertion.visible);
    checks.push({ id: assertion.id, result: countValid && visibilityValid ? 'pass' : 'fail', expected_count: expected, actual_count: matches.length,
      expected_visible: assertion.visible, indexes: matches.map(node => node.index), source: assertion.source, applicability: assertion.applicability });
  }
  for (const order of assertions.order || []) {
    const before = references.get(order.before) || [];
    const after = references.get(order.after) || [];
    const parentKey = node => {
      const parents = [];
      for (let parent = nodes.get(node.parent); parent; parent = nodes.get(parent.parent)) parents.push(parent);
      return `${parents.find(parent => (parent.attributes.class || '').split(/\s+/).includes('widget-wrapper'))?.index ?? ''}|${parents.find(isDeclaredSlotParent)?.index ?? ''}`;
    };
    const sameParent = !order.same_parent || new Set([...before, ...after].map(parentKey)).size === 1;
    const valid = before.length > 0 && after.length > 0 && sameParent && Math.max(...before.map(node => node.index)) < Math.min(...after.map(node => node.index));
    checks.push({ ...order, result: valid ? 'pass' : 'fail', before_indexes: before.map(node => node.index), after_indexes: after.map(node => node.index) });
  }
  // Per-URL entity identity/text comes only from the independently frozen case.
  // A shared layout passing is insufficient when the wrong product/article rendered.
  const headingText = new Map((dom.headings || []).map(node => [node.index, node.text]));
  const normalizedText = value => String(value).replace(/\s+/g, ' ').trim();
  for (const assertion of contract.page?.request_case_assertions || []) {
    const field = assertion.expected_from || assertion.text_from;
    const requestCase = contract.request_case || {};
    if (!field || !Object.prototype.hasOwnProperty.call(requestCase, field)) {
      checks.push({ id: assertion.id, result: 'not_evaluated', reason: 'Independent request-case expected field is missing', field, source: assertion.source });
      continue;
    }
    const selectorObservation = assertion.selector ? (dom.request_case_nodes || []).find(group => group.id === assertion.id && group.selector === assertion.selector) : null;
    if (assertion.selector && !selectorObservation) {
      checks.push({ id: assertion.id, result: 'not_evaluated', reason: 'The source-declared content selector was not observed', selector: assertion.selector, source: assertion.source });
      continue;
    }
    const matches = assertion.selector ? selectorObservation.nodes : Array.from(nodes.values()).filter(node => matchNode(node, assertion.match));
    const expected = requestCase[field];
    const values = matches.map(node => assertion.attribute ? node.attributes[assertion.attribute] : (selectorObservation ? node.text : headingText.get(node.index)));
    const valuesValid = values.every(value => value !== undefined && (assertion.attribute
      ? String(value) === String(expected) : normalizedText(value) === normalizedText(expected)));
    checks.push({ id: assertion.id, result: matches.length === assertion.count && valuesValid ? 'pass' : 'fail',
      expected_count: assertion.count, actual_count: matches.length, expected_from: field, expected_value: expected,
      attribute: assertion.attribute, actual_values: values, indexes: matches.map(node => node.index), source: assertion.source });
  }
  const unresolved = [...(contract.page?.unresolved_conditions || []), ...(contract.page?.source_conflicts || [])];
  return { result: checks.some(check => check.result === 'fail') ? 'fail' : !checks.length || checks.some(check => check.result === 'not_evaluated') || unresolved.length ? 'not_evaluated' : 'pass', checks, unresolved };
}

function verifyGeometry(assertions, dom) {
  return (assertions || []).map(assertion => {
    const elements = dom.geometry?.find(group => group.selector === assertion.selector)?.elements || [];
    const containers = dom.geometry?.find(group => group.selector === assertion.contained_by)?.elements || [];
    const container = containers[0]?.rect;
    const valid = elements.length === (assertion.count || 1) && containers.length === 1 && elements.every(element => {
      const rect = element.rect;
      return element.visible && rect.width > 0 && rect.height > 0 && rect.left >= container.left - 1 && rect.right <= container.right + 1
        && rect.top >= container.top - 1 && rect.bottom <= container.bottom + 1;
    });
    return { type: 'geometry_containment', selector: assertion.selector, contained_by: assertion.contained_by,
      result: valid ? 'pass' : 'fail', element_rects: elements.map(element => element.rect), container_rects: containers.map(element => element.rect), basis: assertion.basis };
  });
}

function verifyNavigation(branch, chain, finalUrl) {
  if (!branch || branch.kind !== 'redirect') return [];
  const suffix = branch.expected_destination_path_suffix;
  const pathname = value => { try { return new URL(value).pathname.replace(/\/$/, ''); } catch { return ''; } };
  const redirects = chain.filter(entry => entry.status === branch.initial_status && entry.location && pathname(entry.location).endsWith(suffix));
  return [{ type: 'source_redirect_branch', result: !!suffix && pathname(finalUrl).endsWith(suffix) && redirects.length > 0 ? 'pass' : 'fail',
    branch, observed_url: finalUrl, matching_redirects: redirects.map(entry => ({ url: entry.url, status: entry.status, location: entry.location })) }];
}

function verifyResponseContract(contract, response) {
  const assertion = contract.page?.response_assertions;
  if (!contract.available || !assertion) return null;
  const checks = [{ type: 'source_response_status', expected: assertion.status, actual: response.status,
    result: response.status === assertion.status ? 'pass' : 'fail' }];
  const mime = String(response.content_type || '').split(';', 1)[0].trim().toLowerCase();
  const expectedTypes = assertion.content_types || [assertion.content_type];
  checks.push({ type: 'source_response_content_type', expected: expectedTypes, actual: mime,
    result: expectedTypes.some(value => typeof value === 'string' && mime === value.toLowerCase()) ? 'pass' : 'fail' });
  for (const item of assertion.body_patterns || []) {
    try {
      const matched = typeof response.body === 'string' && new RegExp(item.pattern, item.flags || '').test(response.body);
      checks.push({ type: 'source_response_body_pattern', pattern: item.pattern, flags: item.flags || '', result: matched ? 'pass' : 'fail' });
    } catch (error) { checks.push({ type: 'source_response_body_pattern', result: 'not_evaluated', reason: `Invalid independent source pattern: ${error.message}` }); }
  }
  const unresolved = [...(contract.page?.unresolved_conditions || []), ...(contract.page?.source_conflicts || [])];
  return { result: checks.some(check => check.result === 'fail') ? 'fail'
    : checks.some(check => check.result === 'not_evaluated') || unresolved.length ? 'not_evaluated' : 'pass', checks, unresolved,
    acceptance_kind: contract.page?.acceptance_kind, phtml_widget_execution_claimed: false };
}

// Fixed regression for the existing main-store hero, not a general interaction DSL.
function verifyHeroControlsGeometry(dom) {
  const root = '.wc-theme_widget_hero_slider';
  const controls = ['.slider-prev', '.slider-next', '[data-slider-pause]'].map(selector => `${root} ${selector}`);
  const content = ['.slide-kicker', '.slide-title', '.slide-subtitle', '.slide-cta-group'].map(selector => `${root} .slide.active ${selector}`);
  const elements = selector => dom.geometry?.find(group => group.selector === selector)?.elements || [];
  const checks = verifyGeometry(controls.map(selector => ({ selector, contained_by: `${root} .slider-container`, basis: 'Mobile hero controls remain inside their visible container.' })), dom);
  for (const control of controls) for (const text of content) {
    const controlNodes = elements(control);
    const textNodes = elements(text);
    const intersections = controlNodes.flatMap(a => textNodes.map(b => ({ width: Math.max(0, Math.min(a.rect.right, b.rect.right) - Math.max(a.rect.left, b.rect.left)),
      height: Math.max(0, Math.min(a.rect.bottom, b.rect.bottom) - Math.max(a.rect.top, b.rect.top)) })));
    checks.push({ type: 'hero_control_content_overlap', control, content: text,
      result: controlNodes.length === 1 && textNodes.length === 1 && controlNodes[0].visible && textNodes[0].visible && intersections.every(rect => rect.width <= 1 || rect.height <= 1) ? 'pass' : 'fail',
      control_rects: controlNodes.map(node => node.rect), content_rects: textNodes.map(node => node.rect), intersections });
  }
  return checks;
}

module.exports = { verifyContract, verifyGeometry, verifyNavigation, verifyResponseContract, verifyHeroControlsGeometry };
