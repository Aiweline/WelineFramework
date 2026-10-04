// Pure comparison of independently frozen source assertions with real Browser observations.

// 冻结逻辑地址保持原值；入口变更必须匹配独立配置证明。
function sourceRequestUrl(item) {
  try {
    const actual = new URL(item.url);
    if (!item.logical_url || new URL(item.logical_url).toString() === actual.toString()) return { available: true, url: actual.toString(), mapped: false };
    const logical = new URL(item.logical_url);
    const proof = item.transport_proof;
    if (proof?.kind === 'configured_https_nginx_public.v1') {
      const fs = require('node:fs'), crypto = require('node:crypto');
      const pinned = proof.configuration_proof;
      const digest = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
      const bytes = fs.readFileSync(pinned.file);
      if (digest(bytes) !== pinned.sha256) return { available: false, reason: 'HTTPS configuration proof SHA drift' };
      const config = JSON.parse(bytes);
      const conf = fs.readFileSync(config.configuration.file, 'utf8').replace(/#.*$/gm, '');
      const owner = JSON.parse(fs.readFileSync(config.owner.file));
      const values = pattern => [...new Set([...conf.matchAll(pattern)].map(match => match[1].trim()))].sort();
      const semantics = {
        tls_ports: values(/\blisten\s+(\d+)\s+ssl\b/g),
        upstreams: values(/\bserver\s+(127\.0\.0\.1:\d+)\s*;/g),
        server_names: values(/\bserver_name\s+([^;]+);/g),
        host: values(/proxy_set_header\s+Host\s+([^;]+);/g),
        scheme: values(/proxy_set_header\s+X-Forwarded-Proto\s+([^;]+);/g),
        port: values(/proxy_set_header\s+X-Forwarded-Port\s+([^;]+);/g),
        authority_preserves_host: /map\s+\$http_host\s+\$wls_upstream_authority\s*\{\s*default\s+\$http_host;\s*\"\"\s+\"\$host:\$server_port\";\s*\}/.test(conf),
        server_blocks: [...conf.matchAll(/^\s*server\s*\{/gm)].length,
        owner_instance: owner.instance_name, owner_upstream_host: owner.upstream_host,
        owner_upstream_port: owner.upstream_port, owner_https_port: owner.listen_https,
        owner_server_names: owner.server_names
      };
      if (JSON.stringify(semantics) !== JSON.stringify(config.transport_semantics)) return { available: false, reason: 'HTTPS configuration transport semantics drift', observed: semantics };
      const validHttps = config.schema === 'theme-public-https-transport.v1' && config.configuration_verified === true
        && logical.protocol === 'https:' && actual.protocol === 'https:'
        && !logical.username && !logical.password && !actual.username && !actual.password
        && ['hostname', 'pathname', 'search', 'hash'].every(key => logical[key] === actual[key])
        && logical.port === '9555' && (actual.port || '443') === '443'
        && config.logical_port === '9555' && config.public_port === '443' && config.public_scheme === 'https:'
        && config.upstream_host === '127.0.0.1' && config.upstream_port === 9555 && config.allowed_hosts.includes(actual.hostname)
        && proof.expected_server_address === '127.0.0.1' && proof.expected_server_port === 443;
      return validHttps ? { available: true, url: logical.toString(), mapped: true } : { available: false, reason: 'Logical request and configured public HTTPS transport do not match exactly' };
    }
    const valid = logical.protocol === 'https:' && actual.protocol === 'http:'
      && !logical.username && !logical.password && !actual.username && !actual.password
      && ['hostname', 'port', 'pathname', 'search', 'hash'].every(key => logical[key] === actual[key])
      && proof?.kind === 'current_http_wls' && proof.verified_http === true && proof.expected_server_address === '127.0.0.1';
    return valid ? { available: true, url: logical.toString(), mapped: true } : { available: false, reason: 'Logical request and verified HTTP transport do not match exactly' };
  } catch { return { available: false, reason: 'Invalid logical or transport URL' }; }
}

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

function matchesDeclaredParents(node, assertion, nodes) {
  if (!assertion.parent_widget && !assertion.parent_slot) return true;
  const parents = [];
  for (let parent = nodes.get(node.parent); parent; parent = nodes.get(parent.parent)) parents.push(parent);
  const wrapper = parents.find(parent => (parent.attributes.class || '').split(/\s+/).includes('widget-wrapper'));
  const slot = parents.find(isDeclaredSlotParent);
  return (!assertion.parent_widget || (wrapper && matchNode(wrapper, assertion.parent_widget)))
    && (!assertion.parent_slot || (slot && matchNode(slot, assertion.parent_slot)));
}

function originalOrderResult(order, references, nodes) {
  const before = references.get(order.before) || [], after = references.get(order.after) || [];
  const parentKey = node => {
    const parents = [];
    for (let parent = nodes.get(node.parent); parent; parent = nodes.get(parent.parent)) parents.push(parent);
    return `${parents.find(parent => (parent.attributes.class || '').split(/\s+/).includes('widget-wrapper'))?.index ?? ''}|${parents.find(isDeclaredSlotParent)?.index ?? ''}`;
  };
  const sameParent = !order.same_parent || new Set([...before, ...after].map(parentKey)).size === 1;
  const valid = before.length > 0 && after.length > 0 && sameParent && Math.max(...before.map(node => node.index)) < Math.min(...after.map(node => node.index));
  return { ...order, result: valid ? 'pass' : 'fail', before_indexes: before.map(node => node.index), after_indexes: after.map(node => node.index) };
}

// 只支持源码席冻结的结账摘要协议；缺少真实 SSR 或未知协议必须失败。
function verifyCheckoutSummaryOrder(order, assertions, references, dom) {
  const protocol = assertions[order.stage_contract];
  const result = { ...order, protocol: protocol?.protocol, result: 'fail', checks: [] };
  const check = (name, valid, observed) => result.checks.push({ name, result: valid ? 'pass' : 'fail', observed });
  if (order.stage !== 'ssr_and_source_conditioned_hydrated' || !['checkout-summary-source-stages.v1', 'checkout-summary-source-stages.v2', 'checkout-summary-source-stages.v3'].includes(protocol?.protocol)
    || protocol.ssr?.before !== order.before || protocol.ssr?.after !== order.after || protocol.ssr?.same_parent !== order.same_parent) {
    check('supported_source_stage_protocol', false); return result;
  }
  const ssr = dom.ssr_document;
  if (!ssr?.available || !Array.isArray(ssr.topology) || !ssr.source_sha_checks?.length || !ssr.source_sha_checks.every(entry => entry.result === 'pass')) {
    check('actual_ssr_document_and_pinned_source_bytes', false); return result;
  }
  const sourceNodes = new Map(ssr.topology.map(node => [node.index, node]));
  const sourceHosts = id => {
    const declaration = assertions.slots?.find(entry => entry.id === id);
    if (!declaration || declaration.count !== 1) return [];
    return ssr.topology.filter(node => matchNode(node, declaration.match) && matchesDeclaredParents(node, declaration, sourceNodes));
  };
  const before = sourceHosts(protocol.ssr.before), after = sourceHosts(protocol.ssr.after);
  check('ssr_original_note_before_credit_count_and_parent', before.length === 1 && after.length === 1 && before[0].index < after[0].index
    && (!protocol.ssr.same_parent || before[0].parent === after[0].parent), { before_indexes: before.map(node => node.index), after_indexes: after.map(node => node.index), body_sha256: ssr.body_sha256 });
  const when = protocol.hydrated?.when;
  const stagedBranches = protocol.protocol !== 'checkout-summary-source-stages.v1' ? protocol.hydrated?.branches : null;
  if (!when || (protocol.protocol !== 'checkout-summary-source-stages.v1' ? !Array.isArray(stagedBranches) : !protocol.hydrated?.checks) || !Array.isArray(dom.checkout_stage_nodes)) { check('frozen_hydrated_guard_and_observations', false); return result; }
  const nodes = new Map(dom.topology.map(node => [node.index, node]));
  const within = (node, ancestor) => {
    for (let parent = nodes.get(node?.parent); parent; parent = nodes.get(parent.parent)) if (parent.index === ancestor?.index) return true;
    return false;
  };
  const observed = selector => {
    const group = dom.checkout_stage_nodes.find(entry => entry.selector === selector);
    return group ? group.indexes.map(index => nodes.get(index)).filter(Boolean) : null;
  };
  const root = observed(when.root_selector);
  const selected = selector => {
    const group = observed(selector);
    return group && root?.length === 1 ? group.filter(node => within(node, root[0])) : null;
  };
  const extras = selected(when.extras_selector), content = selected(when.credit_content_selector);
  const shell = selected(when.shell_selector)?.filter(node => extras?.length === 1 && within(node, extras[0]));
  const credit = references.get(when.credit_host) || [];
  if (!root || !extras || !content || !shell || root.length !== 1 || extras.length !== 1 || credit.length !== 1) {
    check('unique_source_guard_hosts', false); return result;
  }
  const hidden = node => Object.hasOwn(node.attributes, 'hidden');
  const attrsMatch = (node, attrs) => Object.entries(attrs).every(([key, value]) => node.attributes[key] === value);
  if (protocol.protocol === 'checkout-summary-source-stages.v3') {
    const prerequisites = protocol.hydrated.preconditions;
    const supported = prerequisites && ['root_count', 'extras_count', 'credit_host_count', 'credit_content_count'].every(key => prerequisites[key] === 1)
      && prerequisites.credit_content_within_credit_host === true && prerequisites.extras_within_root === true
      && prerequisites.root_mode_attribute === 'data-cart-type'
      && JSON.stringify(prerequisites.root_mode_allowed_values) === JSON.stringify(['toc', 'tob'])
      && JSON.stringify(prerequisites.shell_count_allowed_values) === JSON.stringify([0, 1]);
    check('known_rendered_credit_source_preconditions', Boolean(supported));
    check('complete_unique_rendered_credit_hosts_and_known_settled_mode', content.length === 1 && within(content[0], credit[0]) && within(extras[0], root[0])
      && ['toc', 'tob'].includes(root[0].attributes['data-cart-type']) && shell.length <= 1,
    { root_count: root.length, extras_count: extras.length, credit_host_count: credit.length, credit_content_count: content.length, root_mode: root[0].attributes['data-cart-type'], shell_count: shell.length });
    if (result.checks.some(entry => entry.result === 'fail')) return result;
  }
  const branch = attrsMatch(root[0], when.root_attribute) && attrsMatch(extras[0], when.extras_attribute)
    && hidden(credit[0]) === when.credit_host_hidden && content.length === 1 && hidden(content[0]) === when.credit_content_hidden && shell.length === 1;
  result.hydrated_branch = branch ? 'source_toc_hidden_credit_tabs' : 'original_note_before_credit';
  if (!branch) {
    const original = originalOrderResult(order, references, nodes);
    check('hydrated_original_note_before_credit', original.result === 'pass', original);
  } else {
    let checks = protocol.hydrated.checks, migrated = false;
    if (stagedBranches) {
      const locations = stagedBranches.filter(entry => {
        if (entry.id === 'toc_hidden_credit_direct_extras_child' && entry.location?.credit_host_parent === 'extras') return credit[0].parent === extras[0].index;
        if (entry.id !== 'toc_hidden_credit_already_migrated_panel' || entry.location?.credit_host_parent !== 'bound_credit_panel'
          || entry.location?.credit_panel_parent !== 'panels' || entry.location?.panels_parent !== 'shell' || entry.location?.shell_parent !== 'extras') return false;
        const containers = selected(entry.checks?.panels_selector), panels = selected(entry.checks?.panel_selector);
        const panel = panels?.find(node => node.index === credit[0].parent);
        return panel && containers?.length === 1 && panel.parent === containers[0].index && containers[0].parent === shell[0].index && shell[0].parent === extras[0].index;
      });
      check('exactly_one_known_source_credit_location', locations.length === 1, { locations: locations.map(entry => entry.id) });
      if (locations.length !== 1) return result;
      checks = locations[0].checks;
      migrated = locations[0].id === 'toc_hidden_credit_already_migrated_panel';
      result.hydrated_branch = locations[0].id;
    }
    const commonFlags = ['root_extras_shell_unique', 'shell_direct_child_of_extras', 'panels_direct_child_of_shell', 'candidate_host_direct_child_of_panel', 'panel_direct_child_of_panels', 'candidate_hosts_not_hidden'];
    const requiredFlags = [...commonFlags, ...(migrated ? ['credit_host_direct_child_of_credit_panel', 'credit_panel_direct_child_of_panels', 'credit_panel_hidden', 'credit_host_hidden', 'credit_content_hidden', 'credit_tab_hidden', 'credit_tab_not_active', 'all_three_tabs_have_bidirectional_aria_binding', 'credit_not_direct_child_of_extras'] : ['credit_direct_child_of_extras', 'credit_not_in_panel', 'credit_precedes_appended_shell'])];
    const expectedCandidates = ['host-checkout-summary-discount', 'host-checkout-summary-note', ...(migrated ? ['host-checkout-summary-credit'] : [])];
    const supported = requiredFlags.every(key => checks[key] === true) && checks.candidate_panel_tab_binding?.tab_attribute === 'aria-controls'
      && checks.candidate_panel_tab_binding?.panel_attribute === 'aria-labelledby' && checks.candidate_panel_tab_binding?.equals === 'panel.id'
      && checks.candidate_panel_tab_binding?.equals_tab_id === true && JSON.stringify(checks.candidate_host_ids_in_panel_order) === JSON.stringify(expectedCandidates)
      && (!migrated || (JSON.stringify(checks.candidate_hosts_not_hidden_except) === JSON.stringify(['host-checkout-summary-credit']) && checks.credit_tab_aria_hidden === 'true' && checks.credit_tab_aria_selected === 'false'));
    check('supported_source_branch_checks', supported);
    check('extras_and_credit_content_belong_to_source_hosts', within(extras[0], root[0]) && within(content[0], credit[0]));
    const panels = selected(checks.panels_selector), panelNodes = selected(checks.panel_selector), tabs = selected(checks.candidate_panel_tab_binding?.tab_selector);
    check('source_credit_location_and_shell_parent', shell[0].parent === extras[0].index && (migrated ? credit[0].parent !== extras[0].index : credit[0].parent === extras[0].index && credit[0].index < shell[0].index));
    check('panels_are_shell_direct_child', panels?.length === 1 && panels[0].parent === shell[0].index);
    const candidates = (checks.candidate_host_ids_in_panel_order || []).map(id => references.get(id) || []);
    const candidatePanels = candidates.map(hosts => hosts.length === 1 ? panelNodes?.find(panel => panel.index === hosts[0].parent) : null);
    check('all_source_candidates_have_direct_panel_hosts', candidates.every((hosts, index) => hosts.length === 1 && (migrated && index === 2 ? hidden(hosts[0]) : !hidden(hosts[0])))
      && candidatePanels.every(panel => panel && panels?.length === 1 && panel.parent === panels[0].index));
    check('all_panels_retain_source_candidate_order_including_hidden', candidatePanels.length === expectedCandidates.length && candidatePanels.every(Boolean) && candidatePanels.every((panel, index) => index === 0 || candidatePanels[index - 1].index < panel.index));
    if (!migrated) check('credit_remains_outside_all_candidate_panels', !panelNodes?.some(panel => credit[0].parent === panel.index));
    check('candidate_tabs_have_bidirectional_aria_binding', candidatePanels.every(panel => {
      if (!panel?.attributes.id || !panel.attributes['aria-labelledby']) return false;
      const matchingTabs = tabs?.filter(tab => within(tab, shell[0]) && tab.attributes['aria-controls'] === panel.attributes.id && tab.attributes.id === panel.attributes['aria-labelledby']);
      return matchingTabs?.length === 1;
    }));
    if (migrated) {
      const creditPanel = candidatePanels[2];
      const creditTabs = tabs?.filter(tab => within(tab, shell[0]) && creditPanel && tab.attributes['aria-controls'] === creditPanel.attributes.id && tab.attributes.id === creditPanel.attributes['aria-labelledby']);
      check('migrated_credit_panel_and_bound_tab_hidden_inactive', Boolean(creditPanel && hidden(creditPanel) && creditTabs?.length === 1 && hidden(creditTabs[0])
        && creditTabs[0].attributes['aria-hidden'] === 'true' && creditTabs[0].attributes['aria-selected'] === 'false' && !(creditTabs[0].attributes.class || '').split(/\s+/).includes('is-active')));
    }
  }
  result.result = result.checks.every(entry => entry.result === 'pass') ? 'pass' : 'fail';
  return result;
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
    const matches = pool.filter(node => matchNode(node, assertion.match) && matchesDeclaredParents(node, assertion, nodes));
    references.set(assertion.id, matches);
    const expected = assertion.applicability.state === 'absent' ? 0 : assertion.count;
    const countValid = Number.isInteger(expected) && matches.length === expected;
    const visibilityValid = assertion.visible === undefined || matches.every(node => node.visible === assertion.visible);
    checks.push({ id: assertion.id, result: countValid && visibilityValid ? 'pass' : 'fail', expected_count: expected, actual_count: matches.length,
      expected_visible: assertion.visible, indexes: matches.map(node => node.index), source: assertion.source, applicability: assertion.applicability });
  }
  for (const order of assertions.order || []) {
    if (order.stage) { checks.push(verifyCheckoutSummaryOrder(order, assertions, references, dom)); continue; }
    checks.push(originalOrderResult(order, references, nodes));
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

module.exports = { verifyContract, verifyGeometry, verifyNavigation, verifyResponseContract, verifyHeroControlsGeometry, sourceRequestUrl };
