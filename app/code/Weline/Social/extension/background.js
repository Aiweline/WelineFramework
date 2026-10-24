'use strict';

importScripts('shared/schema.js');

const ALARM_NAME = 'weline-social-poller';
const POLL_PERIOD_MINUTES = 1;
const HEARTBEAT_INTERVAL_MS = 60000;
const STORAGE = Object.freeze({
  settings: 'weline_social_settings',
  token: 'weline_social_token',
  status: 'weline_social_status',
  activeJob: 'weline_social_active_job',
  siteId: 'weline_social_site_id',
});
const ENDPOINTS = Object.freeze({
  authorize: '/weline_social/frontend/extension/authorize',
  claim: '/weline_social/frontend/extension/claim',
  heartbeat: '/weline_social/frontend/extension/heartbeat',
  step: '/weline_social/frontend/extension/step',
  report: '/weline_social/frontend/extension/report',
  release: '/weline_social/frontend/extension/release',
});
const PLATFORM_HOSTS = Object.freeze({
  facebook: ['facebook.com'],
  x: ['x.com'],
  tiktok: ['tiktok.com'],
});
const createdJobTabs = new Map();
const MAX_STEPS = WelineSocialSchema.MAX_STEPS;
const STEP_TIMEOUT_MS = WelineSocialSchema.STEP_TIMEOUT_MS;
const JOB_TIMEOUT_MS = WelineSocialSchema.JOB_TIMEOUT_MS;

let polling = false;
let activeJob = null;
let memoryStatus = {
  connected: false,
  phase: 'idle',
  last_poll_at: '',
  last_job_id: '',
  last_result: '',
  last_error: '',
  token_hint: '',
};

function delay(ms) {
  return new Promise(function (resolve) {
    setTimeout(resolve, ms);
  });
}

function asObject(value) {
  if (value && typeof value === 'object' && !Array.isArray(value)) {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    try {
      const parsed = JSON.parse(value);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        return parsed;
      }
    } catch {
      return null;
    }
  }
  return null;
}

function asString(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function isLocalHttpHost(hostname) {
  const host = String(hostname || '').toLowerCase();
  if (host === 'localhost' || host === '127.0.0.1' || host === '::1' || host === '[::1]') {
    return true;
  }
  return host === 'test.weline.com' || host.endsWith('.test.weline.com');
}

function normalizeBase(value) {
  const raw = String(value || '').trim();
  if (raw === '') {
    return '';
  }
  try {
    const url = new URL(raw);
    if (url.protocol === 'https:') {
      return url.href.replace(/\/+$/, '');
    }
    if (url.protocol === 'http:' && isLocalHttpHost(url.hostname)) {
      return url.href.replace(/\/+$/, '');
    }
    return '';
  } catch {
    return '';
  }
}

function normalizeWebsiteId(value) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed >= 0 ? parsed : 0;
}

async function getAuth() {
  const data = await chrome.storage.local.get([STORAGE.settings, STORAGE.token, STORAGE.siteId]);
  const settings = data[STORAGE.settings] && typeof data[STORAGE.settings] === 'object'
    ? data[STORAGE.settings]
    : {};
  return {
    apiBase: normalizeBase(settings.apiBase),
    deviceName: String(settings.deviceName || ''),
    websiteId: normalizeWebsiteId(settings.websiteId ?? settings.siteId ?? data[STORAGE.siteId]),
    token: String(data[STORAGE.token] || ''),
  };
}

async function saveStatus(patch) {
  memoryStatus = {
    ...memoryStatus,
    ...patch,
    updated_at: new Date().toISOString(),
  };
  await chrome.storage.local.set({ [STORAGE.status]: memoryStatus });
  return memoryStatus;
}

async function getStatus() {
  const data = await chrome.storage.local.get(STORAGE.status);
  if (data[STORAGE.status] && typeof data[STORAGE.status] === 'object') {
    memoryStatus = { ...memoryStatus, ...data[STORAGE.status] };
  }
  return { ...memoryStatus };
}

async function apiFetch(apiBase, path, payload, token, options) {
  const headers = { 'Content-Type': 'application/json' };
  if (token) {
    headers.Authorization = 'Bearer ' + token;
    headers['X-Social-Ext-Token'] = token;
  }

  const controller = new AbortController();
  const timer = setTimeout(function () {
    controller.abort();
  }, STEP_TIMEOUT_MS);

  const fetchOptions = {
    method: 'POST',
    headers,
    body: JSON.stringify(payload || {}),
    credentials: 'omit',
    signal: controller.signal,
  };
  if (options && options.keepalive === true) {
    fetchOptions.keepalive = true;
  }

  let response;
  try {
    response = await fetch(apiBase + path, fetchOptions);
  } finally {
    clearTimeout(timer);
  }

  const text = await response.text();
  let data = {};
  if (text !== '') {
    try {
      data = JSON.parse(text);
    } catch {
      data = { raw: text };
    }
  }
  if (!data || typeof data !== 'object') {
    data = {};
  }

  if (!response.ok) {
    const error = new Error(String(data.error || data.message || ('http_' + response.status)));
    error.status = response.status;
    error.data = data;
    throw error;
  }
  if (data && data.ok === false) {
    const error = new Error(String(data.error || 'request_failed'));
    error.status = response.status;
    error.data = data;
    throw error;
  }
  return data;
}

async function authorize(payload) {
  const apiBase = normalizeBase(payload && payload.apiBase);
  if (apiBase === '') {
    throw new Error('api_base_invalid');
  }
  const code = String((payload && payload.code) || '').trim();
  if (code === '') {
    throw new Error('authorization_code_required');
  }
  const deviceName = String((payload && payload.deviceName) || '').trim() || 'Weline Social Browser';
  const currentAuth = await getAuth();
  const requestedWebsiteId = normalizeWebsiteId(
    payload && payload.website_id !== undefined && payload.website_id !== null
      ? payload.website_id
      : (payload && payload.websiteId !== undefined && payload.websiteId !== null
        ? payload.websiteId
        : currentAuth.websiteId)
  );

  const data = await apiFetch(apiBase, ENDPOINTS.authorize, {
    code,
    name: deviceName,
    website_id: requestedWebsiteId,
    version: chrome.runtime.getManifest().version,
  }, '');
  const token = String(
    data.token
    || data.access_token
    || data.device_token
    || (data.data && (data.data.token || data.data.access_token || data.data.device_token))
    || ''
  );
  if (token === '') {
    throw new Error(String(data.error || 'authorization_failed'));
  }
  const resolvedWebsiteId = normalizeWebsiteId(
    data.website_id !== undefined && data.website_id !== null
      ? data.website_id
      : (data.data && data.data.website_id !== undefined ? data.data.website_id : requestedWebsiteId)
  );

  const stored = await chrome.storage.local.get(STORAGE.settings);
  const existingSettings = stored[STORAGE.settings] && typeof stored[STORAGE.settings] === 'object'
    ? stored[STORAGE.settings]
    : {};
  await chrome.storage.local.set({
    [STORAGE.token]: token,
    [STORAGE.settings]: {
      ...existingSettings,
      apiBase,
      deviceName,
      websiteId: resolvedWebsiteId,
    },
    [STORAGE.siteId]: resolvedWebsiteId,
  });
  await saveStatus({
    connected: true,
    phase: 'idle',
    last_error: '',
    token_hint: token.slice(-4),
  });
  return {
    ok: true,
    apiBase,
    device_name: deviceName,
    website_id: resolvedWebsiteId,
    token_hint: token.slice(-4),
  };
}

async function disconnect() {
  await releaseActiveJob('device_disconnected');
  await chrome.storage.local.remove(STORAGE.token);
  await saveStatus({
    connected: false,
    phase: 'idle',
    last_job_id: '',
    last_result: '',
    last_error: '',
    token_hint: '',
  });
  return { ok: true };
}

async function statusPayload() {
  const auth = await getAuth();
  const status = await getStatus();
  const current = await getActiveJob();
  return {
    ...status,
    connected: auth.token !== '' && auth.apiBase !== '',
    apiBase: auth.apiBase,
    deviceName: auth.deviceName,
    websiteId: auth.websiteId,
    has_token: auth.token !== '',
    token_hint: auth.token !== '' ? auth.token.slice(-4) : '',
    has_active_job: Boolean(current),
    active_job_id: current ? String(current.job_id || '') : '',
    version: chrome.runtime.getManifest().version,
  };
}

async function claimJob() {
  const auth = await getAuth();
  if (auth.token === '' || auth.apiBase === '') {
    return null;
  }
  const data = await apiFetch(auth.apiBase, ENDPOINTS.claim, {
    version: chrome.runtime.getManifest().version,
  }, auth.token);

  const envelope = asObject(data.data) || data;
  const job = asObject(envelope.job) || asObject(data.job);
  if (!job) {
    return null;
  }

  const payload = asObject(job.payload_json) || asObject(job.payload) || {};
  const recipeCandidate = asObject(envelope.recipe)
    || asObject(data.recipe)
    || asObject(job.recipe)
    || asObject(payload.recipe);
  const recipe = recipeCandidate && Object.keys(recipeCandidate).length > 0 ? recipeCandidate : null;
  const entry = asString(envelope.entry)
    || asString(data.entry)
    || asString(job.entry)
    || asString(payload.entry)
    || (recipe ? asString(recipe.entry) : '');

  return { job, recipe, entry };
}

async function heartbeatJob(job) {
  const auth = await getAuth();
  if (auth.token === '' || auth.apiBase === '') {
    return null;
  }
  return apiFetch(auth.apiBase, ENDPOINTS.heartbeat, {
    job_id: job.job_id,
    claim_token: job.claim_token || '',
    version: chrome.runtime.getManifest().version,
  }, auth.token);
}

async function reportJob(job, status, extra) {
  const auth = await getAuth();
  if (auth.token === '' || auth.apiBase === '') {
    return null;
  }
  const details = extra || {};
  const result = details.result || {};
  return apiFetch(auth.apiBase, ENDPOINTS.report, {
    job_id: job.job_id,
    claim_token: job.claim_token || '',
    status,
    remote_url: details.remote_url || '',
    error_code: details.error_code || '',
    result,
    steps: details.steps !== undefined ? details.steps : (result.steps || 0),
  }, auth.token, { keepalive: true });
}

async function releaseJob(job, reason) {
  const auth = await getAuth();
  if (auth.token === '' || auth.apiBase === '') {
    return null;
  }
  return apiFetch(auth.apiBase, ENDPOINTS.release, {
    job_id: job.job_id,
    claim_token: job.claim_token || '',
    reason: String(reason || '').slice(0, 500),
  }, auth.token, { keepalive: true });
}

async function requestStep(claim, stepNo, snapshot) {
  const auth = await getAuth();
  if (auth.token === '' || auth.apiBase === '') {
    throw new Error('not_authorized');
  }
  const job = claim.job;
  const data = await apiFetch(auth.apiBase, ENDPOINTS.step, {
    job_id: job.job_id,
    claim_token: job.claim_token || '',
    step_no: stepNo,
    snapshot,
    recipe: claim.recipe || {},
    entry: claim.entry || '',
    version: chrome.runtime.getManifest().version,
  }, auth.token);

  const nested = asObject(data.data);
  let actions = [];
  if (Array.isArray(data.actions)) {
    actions = data.actions;
  } else if (nested && Array.isArray(nested.actions)) {
    actions = nested.actions;
  } else if (data.action && typeof data.action === 'object') {
    actions = [data.action];
  } else if (typeof data.action === 'string') {
    const args = asObject(data.args) || {};
    actions = [{
      ...args,
      type: data.action,
      done: data.done === true,
      error: data.error || '',
    }];
  } else if (data.decision && typeof data.decision === 'object') {
    actions = [data.decision];
  } else if (Array.isArray(data.tool_calls)) {
    actions = data.tool_calls.map(function (item) {
      return item && item.action ? item.action : item;
    });
  }

  const firstAction = actions.length > 0 && actions[0] && typeof actions[0] === 'object' ? actions[0] : {};

  return {
    actions,
    done: data.done === true || (nested && nested.done === true),
    remote_url: asString(data.remote_url)
      || (nested ? asString(nested.remote_url) : '')
      || asString(firstAction.remote_url),
    error: asString(data.error) || (nested ? asString(nested.error) : ''),
  };
}

function isRecipeMissing(claim) {
  const recipe = claim.recipe;
  if (!recipe || typeof recipe !== 'object') {
    return true;
  }
  if (Array.isArray(recipe.give_up) && recipe.give_up.includes('recipe_missing')) {
    return true;
  }
  const version = Number(recipe.version);
  return !Number.isInteger(version) || version < 1;
}

function resolveEntry(claim) {
  const recipe = claim.recipe && typeof claim.recipe === 'object' ? claim.recipe : {};
  const entry = asString(claim.entry) || asString(recipe.entry);
  const lower = entry.toLowerCase();
  if (
    entry === ''
    || entry.includes('{')
    || entry.includes('}')
    || lower.includes('%7b')
    || lower.includes('%7d')
  ) {
    return '';
  }
  return WelineSocialSchema.isAllowedHostUrl(entry) ? entry : '';
}

async function findPlatformTab(platformCode) {
  const allowed = PLATFORM_HOSTS[String(platformCode || '').toLowerCase()] || [];
  if (allowed.length === 0) {
    return null;
  }
  const tabs = await chrome.tabs.query({});
  for (const tab of tabs) {
    if (!tab.id || !tab.url) {
      continue;
    }
    try {
      const host = new URL(tab.url).hostname.toLowerCase();
      const matched = allowed.some(function (domain) {
        return host === domain || host.endsWith('.' + domain);
      });
      if (matched) {
        return tab;
      }
    } catch {
      continue;
    }
  }
  return null;
}

function waitForTab(tabId) {
  return new Promise(function (resolve, reject) {
    let settled = false;
    const finish = function (error) {
      if (settled) {
        return;
      }
      settled = true;
      clearTimeout(timer);
      chrome.tabs.onUpdated.removeListener(onUpdated);
      chrome.tabs.onRemoved.removeListener(onRemoved);
      if (error) {
        reject(error);
      } else {
        resolve();
      }
    };
    const timer = setTimeout(function () {
      finish(new Error('tab_load_timeout'));
    }, STEP_TIMEOUT_MS);
    const onUpdated = function (updatedTabId, info) {
      if (updatedTabId === tabId && info.status === 'complete') {
        finish();
      }
    };
    const onRemoved = function (removedTabId) {
      if (removedTabId === tabId) {
        finish(new Error('tab_closed'));
      }
    };
    chrome.tabs.onUpdated.addListener(onUpdated);
    chrome.tabs.onRemoved.addListener(onRemoved);
    chrome.tabs.get(tabId).then(function (tab) {
      if (tab && tab.status === 'complete') {
        finish();
      }
    }).catch(function () {
      finish(new Error('tab_unavailable'));
    });
  });
}

async function ensureContentScript(tabId) {
  try {
    const pong = await chrome.tabs.sendMessage(tabId, { type: 'weline-social/ping' });
    if (pong && pong.ok) {
      return;
    }
  } catch {
    // Content script not injected yet; inject the same files the manifest declares.
  }

  await chrome.scripting.executeScript({
    target: { tabId },
    files: ['shared/schema.js', 'content/snapshot.js', 'content/actions.js'],
  });

  const pong = await chrome.tabs.sendMessage(tabId, { type: 'weline-social/ping' });
  if (!pong || !pong.ok) {
    throw new Error('content_script_unavailable');
  }
}

function sameDocument(url, entry) {
  try {
    const a = new URL(String(url || ''));
    const b = new URL(String(entry || ''));
    const normA = a.origin.toLowerCase() + a.pathname.replace(/\/+$/, '');
    const normB = b.origin.toLowerCase() + b.pathname.replace(/\/+$/, '');
    return normA === normB;
  } catch {
    return false;
  }
}

async function ensureJobTab(claim) {
  const job = claim.job;
  const entry = resolveEntry(claim);
  if (entry === '') {
    throw new Error('entry_missing');
  }
  const existing = await findPlatformTab(job.platform_code);
  if (existing && existing.id && sameDocument(existing.url, entry)) {
    // 只复用已经停留在本单入口页的标签；任意 /status/ 页会伪造发布成功（R1）。
    await waitForTab(existing.id);
    await ensureContentScript(existing.id);
    return existing.id;
  }

  // 非入口页（包括用户正在阅读的页面）不改写，另开专用标签，任务结束即关闭（R9/R10）。
  const tab = await chrome.tabs.create({ url: entry, active: false });
  if (!tab.id) {
    throw new Error('tab_create_failed');
  }
  createdJobTabs.set(String(job.job_id), tab.id);
  await waitForTab(tab.id);
  await waitForTabUrl(tab.id, entry, 10000);
  await ensureContentScript(tab.id);
  return tab.id;
}

function waitForTabUrl(tabId, entry, timeoutMs) {
  const deadline = Date.now() + Math.max(500, Number(timeoutMs) || 10000);
  return new Promise(function (resolve) {
    const poll = function () {
      chrome.tabs.get(tabId).then(function (tab) {
        if (tab && sameDocument(tab.url, entry)) {
          resolve(true);
          return;
        }
        if (Date.now() >= deadline) {
          resolve(false);
          return;
        }
        setTimeout(poll, 250);
      }).catch(function () {
        resolve(false);
      });
    };
    poll();
  });
}

async function captureSnapshot(tabId) {
  const response = await chrome.tabs.sendMessage(tabId, { type: 'weline-social/snapshot' });
  if (!response || !response.ok || !response.snapshot) {
    throw new Error((response && response.error) || 'snapshot_failed');
  }
  const check = WelineSocialSchema.validateSnapshot(response.snapshot);
  if (!check.ok) {
    throw new Error(check.error || 'snapshot_invalid');
  }
  return response.snapshot;
}

async function captureWithRetry(tabId, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastError = null;
  while (Date.now() < deadline) {
    try {
      return await captureSnapshot(tabId);
    } catch (error) {
      lastError = error;
      await delay(750);
    }
  }
  throw lastError || new Error('snapshot_failed');
}

function normalizeIncomingAction(raw) {
  if (!raw || typeof raw !== 'object') {
    return raw;
  }
  let base = raw;
  if (raw.action && typeof raw.action === 'object') {
    base = raw.action;
  }
  const args = base.args && typeof base.args === 'object' ? base.args : {};
  const action = { ...args, ...base };
  if (typeof action.type !== 'string') {
    if (typeof action.action === 'string') {
      action.type = action.action;
    } else if (typeof action.name === 'string') {
      action.type = action.name;
    }
  }
  delete action.args;

  if (action.type === 'give_up' && typeof action.reason !== 'string') {
    if (typeof action.error === 'string' && action.error.trim() !== '') {
      action.reason = action.error;
    } else if (typeof action.message === 'string' && action.message.trim() !== '') {
      action.reason = action.message;
    }
  }

  if (action.handle !== undefined && action.handle !== null && action.handle !== '') {
    const handle = Number(action.handle);
    if (Number.isInteger(handle) && handle > 0) {
      action.handle = handle;
    }
  }
  if (action.target && typeof action.target === 'object' && action.target.handle !== undefined) {
    const targetHandle = Number(action.target.handle);
    if (Number.isInteger(targetHandle) && targetHandle > 0) {
      action.target = { ...action.target, handle: targetHandle };
    }
  }
  if (action.type === 'upload' && action.asset && typeof action.asset === 'object') {
    if (typeof action.mime !== 'string' && typeof action.asset.mime === 'string') {
      action.mime = action.asset.mime;
    }
    if (typeof action.data_url !== 'string' && typeof action.asset.data_url === 'string') {
      action.data_url = action.asset.data_url;
    }
  }
  return action;
}

function withTimeout(promise, timeoutMs, code) {
  return new Promise(function (resolve, reject) {
    const timer = setTimeout(function () {
      reject(new Error(code));
    }, timeoutMs);
    promise.then(function (value) {
      clearTimeout(timer);
      resolve(value);
    }, function (error) {
      clearTimeout(timer);
      reject(error);
    });
  });
}

async function setActiveJob(job, tabId, windowId) {
  activeJob = {
    job_id: String(job.job_id || ''),
    claim_token: String(job.claim_token || ''),
    tab_id: Number(tabId) || 0,
    window_id: Number(windowId) || 0,
    platform_code: String(job.platform_code || ''),
  };
  try {
    await chrome.storage.local.set({ [STORAGE.activeJob]: activeJob });
  } catch {
    // Memory state is enough for the current worker.
  }
  return activeJob;
}

async function getActiveJob() {
  if (activeJob && activeJob.job_id) {
    return activeJob;
  }
  try {
    const data = await chrome.storage.local.get(STORAGE.activeJob);
    const stored = data[STORAGE.activeJob];
    if (stored && typeof stored === 'object' && stored.job_id) {
      activeJob = stored;
    }
  } catch {
    return null;
  }
  return activeJob && activeJob.job_id ? activeJob : null;
}

async function clearActiveJob() {
  activeJob = null;
  try {
    await chrome.storage.local.remove(STORAGE.activeJob);
  } catch {
    // Ignore storage cleanup failures.
  }
}

async function releaseActiveJob(reason) {
  const current = await getActiveJob();
  if (!current) {
    return { ok: true, skipped: true };
  }
  await clearActiveJob();
  try {
    await releaseJob(current, reason);
    return { ok: true };
  } catch (error) {
    return { ok: false, error: error.message || 'release_failed' };
  }
}

async function finish(job, status, extra) {
  const details = extra || {};
  let reportOk = false;
  try {
    const response = await reportJob(job, status, details);
    reportOk = !response || response.ok !== false;
  } catch (error) {
    try {
      await saveStatus({ last_error: error.message || 'report_failed' });
    } catch {
      // Ignore status write failures.
    }
  }

  if (!reportOk) {
    try {
      await releaseJob(job, 'report_failed');
    } catch {
      // Lease expiry will release the job server-side.
    }
  }
  await clearActiveJob();
  const dedicatedTab = createdJobTabs.get(String(job.job_id));
  if (dedicatedTab) {
    createdJobTabs.delete(String(job.job_id));
    try {
      await chrome.tabs.remove(dedicatedTab);
    } catch {
      // Tab may already be closed by the user.
    }
  }

  return {
    status,
    remote_url: details.remote_url || '',
    error_code: details.error_code || '',
    steps: details.steps !== undefined
      ? Number(details.steps) || 0
      : (details.result && details.result.steps ? Number(details.result.steps) || 0 : 0),
  };
}

function startHeartbeat(job) {
  const state = {
    lost: false,
    stopped: false,
    inFlight: false,
    stop: function () {},
  };
  let timer = null;

  const beat = function () {
    if (state.stopped || state.inFlight) {
      return;
    }
    state.inFlight = true;
    heartbeatJob(job)
      .then(function (result) {
        if (result && result.ok === false) {
          state.lost = true;
        }
      })
      .catch(function (error) {
        const code = String((error && error.message) || '');
        if (
          (error && (error.status === 401 || error.status === 403))
          || ['invalid_claim_token', 'forbidden', 'job_finished', 'job_not_claimed', 'job_stale'].includes(code)
        ) {
          state.lost = true;
        }
      })
      .finally(function () {
        state.inFlight = false;
      });
  };

  void beat();
  timer = setInterval(beat, HEARTBEAT_INTERVAL_MS);
  state.stop = function () {
    state.stopped = true;
    if (timer !== null) {
      clearInterval(timer);
    }
  };
  return state;
}

async function executeJob(claim) {
  const heartbeat = startHeartbeat(claim.job);
  try {
    return await executeJobInner(claim, heartbeat);
  } finally {
    heartbeat.stop();
  }
}

async function executeJobInner(claim, heartbeat) {
  const job = claim.job;
  const started = Date.now();
  let stepNo = 1;
  let tabId = 0;

  try {
    tabId = await ensureJobTab(claim);
  } catch (error) {
    return finish(job, 'failed', {
      error_code: error.message || 'tab_unavailable',
      result: { steps: 0 },
    });
  }

  let tab = null;
  try {
    tab = await chrome.tabs.get(tabId);
  } catch {
    tab = null;
  }
  await setActiveJob(job, tabId, tab && tab.windowId);

  while (stepNo <= MAX_STEPS && Date.now() - started < JOB_TIMEOUT_MS) {
    if (heartbeat.lost) {
      return finish(job, 'failed', {
        error_code: 'lease_lost',
        result: { steps: stepNo - 1 },
      });
    }

    let currentSnapshot = null;
    try {
      currentSnapshot = await captureWithRetry(tabId, STEP_TIMEOUT_MS);
    } catch (error) {
      return finish(job, 'failed', {
        error_code: error.message || 'snapshot_failed',
        result: { steps: stepNo - 1 },
      });
    }

    let decision = null;
    try {
      decision = await requestStep(claim, stepNo, currentSnapshot);
    } catch (error) {
      return finish(job, 'failed', {
        error_code: error.message || 'step_request_failed',
        result: { steps: stepNo - 1 },
      });
    }

    if (decision.done) {
      return finish(job, 'succeeded', {
        remote_url: decision.remote_url,
        result: { steps: stepNo },
      });
    }
    if (decision.actions.length === 0) {
      return finish(job, 'failed', {
        error_code: decision.error || 'no_action',
        result: { steps: stepNo },
      });
    }

    for (const rawAction of decision.actions) {
      const action = normalizeIncomingAction(rawAction);
      const check = WelineSocialSchema.validateAction(action);
      if (!check.ok) {
        return finish(job, 'failed', {
          error_code: 'action_not_allowed',
          result: { steps: stepNo, reason: check.error, action: action && action.type },
        });
      }

      let result = null;
      try {
        result = await withTimeout(
          chrome.tabs.sendMessage(tabId, { type: 'weline-social/action', action }),
          STEP_TIMEOUT_MS,
          'step_timeout'
        );
      } catch (error) {
        return finish(job, 'failed', {
          error_code: error.message || 'action_failed',
          result: { steps: stepNo, action: action.type },
        });
      }

      if (action.type === 'done') {
        return finish(job, 'succeeded', {
          remote_url: action.remote_url,
          result: { steps: stepNo },
        });
      }
      if (action.type === 'give_up') {
        return finish(job, 'failed', {
          error_code: 'give_up',
          result: { steps: stepNo, reason: action.reason, action: 'give_up' },
        });
      }
      if (result && result.ok === false) {
        const unverified = action.type === 'assert' || result.error === 'assert_failed';
        return finish(job, unverified ? 'unverified' : 'failed', {
          error_code: result.error || 'action_failed',
          result: { steps: stepNo, action: action.type },
        });
      }
    }

    stepNo += 1;
    await delay(500);
  }

  const timedOut = Date.now() - started >= JOB_TIMEOUT_MS;
  return finish(job, 'failed', {
    error_code: timedOut ? 'job_timeout' : 'step_budget',
    result: { steps: Math.min(stepNo - 1, MAX_STEPS) },
  });
}

async function runPoll() {
  if (polling) {
    return { ok: false, error: 'poll_in_progress' };
  }
  polling = true;
  try {
    const auth = await getAuth();
    if (auth.token === '' || auth.apiBase === '') {
      await saveStatus({ connected: false, phase: 'idle' });
      return { ok: true, skipped: 'not_authorized' };
    }

    const stale = await getActiveJob();
    if (stale) {
      await releaseActiveJob('stale_active_job');
    }

    await saveStatus({
      connected: true,
      phase: 'claiming',
      last_poll_at: new Date().toISOString(),
      last_error: '',
    });
    const claim = await claimJob();
    if (!claim) {
      await saveStatus({ phase: 'idle', last_job_id: '' });
      return { ok: true, claimed: false };
    }

    const job = claim.job;
    let result;
    if (isRecipeMissing(claim)) {
      result = await finish(job, 'failed', {
        error_code: 'recipe_missing',
        result: { steps: 0, action: 'give_up', reason: 'recipe_missing' },
      });
    } else if (!claim.entry) {
      result = await finish(job, 'failed', {
        error_code: 'entry_missing',
        result: { steps: 0, action: 'give_up', reason: 'entry_missing' },
      });
    } else {
      await saveStatus({ phase: 'running', last_job_id: String(job.job_id || '') });
      try {
        result = await executeJob(claim);
      } catch (error) {
        result = await finish(job, 'failed', { error_code: error.message || 'execute_failed' });
      }
    }

    await saveStatus({
      phase: 'idle',
      last_result: result.status,
      last_error: result.error_code || '',
    });
    return { ok: true, claimed: true, result };
  } catch (error) {
    await saveStatus({ phase: 'error', last_error: error.message || 'poll_failed' });
    return { ok: false, error: error.message || 'poll_failed' };
  } finally {
    polling = false;
  }
}

async function ensureAlarm() {
  const existing = await chrome.alarms.get(ALARM_NAME);
  if (!existing || existing.periodInMinutes !== POLL_PERIOD_MINUTES) {
    chrome.alarms.create(ALARM_NAME, { periodInMinutes: POLL_PERIOD_MINUTES });
  }
}

async function releaseIfActiveTab(tabId) {
  const current = await getActiveJob();
  if (current && Number(current.tab_id) === Number(tabId)) {
    await releaseActiveJob('tab_closed');
  }
}

async function releaseIfActiveWindow(windowId) {
  const current = await getActiveJob();
  if (current && Number(current.window_id) !== 0 && Number(current.window_id) === Number(windowId)) {
    await releaseActiveJob('window_closed');
  }
}

chrome.runtime.onInstalled.addListener(function () {
  void ensureAlarm().catch(function () {});
  void runPoll().catch(function () {});
});

chrome.runtime.onStartup.addListener(function () {
  void ensureAlarm().catch(function () {});
});

chrome.alarms.onAlarm.addListener(function (alarm) {
  if (alarm.name === ALARM_NAME) {
    void runPoll().catch(function () {});
  }
});

chrome.tabs.onRemoved.addListener(function (tabId) {
  void releaseIfActiveTab(tabId).catch(function () {});
});

if (chrome.windows && chrome.windows.onRemoved) {
  chrome.windows.onRemoved.addListener(function (windowId) {
    void releaseIfActiveWindow(windowId).catch(function () {});
  });
}

if (chrome.runtime.onSuspend) {
  chrome.runtime.onSuspend.addListener(function () {
    void releaseActiveJob('service_worker_suspend').catch(function () {});
  });
}

chrome.runtime.onMessage.addListener(function (message, _sender, sendResponse) {
  if (!message || typeof message.type !== 'string') {
    return false;
  }
  if (message.type === 'weline-social/status') {
    statusPayload().then(sendResponse).catch(function () {
      sendResponse({ connected: false, phase: 'error', last_error: 'status_failed' });
    });
    return true;
  }
  if (message.type === 'weline-social/authorize') {
    authorize(message.payload || {}).then(sendResponse).catch(function (error) {
      sendResponse({ ok: false, error: error.message || 'authorization_failed' });
    });
    return true;
  }
  if (message.type === 'weline-social/disconnect') {
    disconnect().then(sendResponse).catch(function () {
      sendResponse({ ok: false, error: 'disconnect_failed' });
    });
    return true;
  }
  if (message.type === 'weline-social/poll-now') {
    runPoll().then(sendResponse).catch(function (error) {
      sendResponse({ ok: false, error: error.message || 'poll_failed' });
    });
    return true;
  }
  return false;
});

void ensureAlarm().catch(function () {});
