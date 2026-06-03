#!/usr/bin/env node
'use strict';

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, '..', '..', '..');
const playwrightNodeModules = process.env.WELINE_PLAYWRIGHT_NODE_MODULES
  || path.join(repoRoot, 'tests', 'e2e', 'node_modules');
const requirePlaywright = createRequire(path.join(playwrightNodeModules, '_weline-once-through.cjs'));
const { chromium, request } = requirePlaywright('playwright');
const baseUrl = (process.env.WELINE_BASE_URL || 'https://p11005ce4.weline.test').replace(/\/+$/, '');
const prefix = (process.env.WELINE_ROUTE_PREFIX || 'U0Ma5pkoi8tl3wiDiIh6FV0XCo1Tg1E8').replace(/^\/+|\/+$/g, '');
const adminUser = process.env.WELINE_ADMIN_USER || 'admin';
const adminPassword = process.env.WELINE_ADMIN_PASSWORD || 'admin';
const runId = process.env.WELINE_ONCE_RUN_ID || new Date().toISOString().replace(/[-:T.Z]/g, '').slice(0, 14);
const outDir = path.join(repoRoot, 'dev', 'ai', 'codex', 'tasks', '2026-06-01', '2026-06-01-1859-ai-site-browser-once-through', 'runs', runId);

const pageTypes = (process.env.WELINE_ONCE_PAGE_TYPES || [
  'home_page',
  'about_page',
  'contact_page',
  'privacy_policy',
  'terms_of_service',
  'refund_policy',
  'shipping_policy',
  'cookie_policy',
  'blog_post',
  'blog_category',
  'blog_list',
  'custom_page',
].join(',')).split(',').map((value) => value.trim()).filter(Boolean);

const planDeadlineMs = Number(process.env.WELINE_PLAN_TIMEOUT_MS || 45 * 60 * 1000);
const buildDeadlineMs = Number(process.env.WELINE_BUILD_TIMEOUT_MS || 90 * 60 * 1000);
const publishDeadlineMs = Number(process.env.WELINE_PUBLISH_TIMEOUT_MS || 20 * 60 * 1000);
const pollMs = Number(process.env.WELINE_POLL_MS || 5000);

function log(message) {
  const line = `${new Date().toISOString()} ${message}`;
  console.log(line);
  fs.mkdirSync(outDir, { recursive: true });
  fs.appendFileSync(path.join(outDir, 'run.log'), `${line}\n`);
}

function route(relativePath) {
  const rel = `/${String(relativePath || '').replace(/^\/+/, '')}`;
  return `${prefix ? `/${prefix}` : ''}${rel}`;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function jsonSummary(value, max = 800) {
  try {
    return JSON.stringify(value).slice(0, max);
  } catch {
    return String(value).slice(0, max);
  }
}

async function okJson(response, label) {
  const status = response.status();
  const text = await response.text();
  let data = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch {
    throw new Error(`${label} returned non-json HTTP ${status}: ${text.slice(0, 500)}`);
  }
  if (status < 200 || status >= 300 || (Object.prototype.hasOwnProperty.call(data, 'success') && !data.success)) {
    throw new Error(`${label} failed HTTP ${status}: ${jsonSummary(data, 1000)}`);
  }
  return data;
}

function isTransientApiPostError(error) {
  const message = String(error?.message || error || '').toLowerCase();
  return [
    'socket hang up',
    'econnreset',
    'econnrefused',
    'etimedout',
    'timeout',
    'net::err_connection_reset',
    'net::err_connection_closed',
  ].some((needle) => message.includes(needle));
}

async function apiPost(api, url, options, label, attempts = 3) {
  let lastError;
  const requestOptions = {
    ...(options || {}),
    timeout: Number(options?.timeout || process.env.WELINE_API_POST_TIMEOUT_MS || 60000),
  };
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      return await api.post(url, requestOptions);
    } catch (error) {
      lastError = error;
      if (attempt >= attempts || !isTransientApiPostError(error)) {
        throw error;
      }
      const delayMs = 800 * attempt;
      log(`api_post_retry label=${label} attempt=${attempt + 1}/${attempts} delay_ms=${delayMs} error=${String(error.message || error).slice(0, 180)}`);
      await sleep(delayMs);
    }
  }
  throw lastError;
}

async function postOkJson(api, relativePath, options, label, attempts = 3) {
  return okJson(await apiPost(api, route(relativePath), options, label, attempts), label);
}

function pickState(payload) {
  if (payload && typeof payload === 'object') {
    if (payload.data && typeof payload.data === 'object') {
      return payload.data;
    }
    if (payload.state && typeof payload.state === 'object') {
      return payload.state;
    }
  }
  return payload && typeof payload === 'object' ? payload : {};
}

function activeStatus(state, operation) {
  const active = state.active_operations && state.active_operations[operation]
    ? state.active_operations[operation]
    : (state.active_operation && state.active_operation.operation === operation ? state.active_operation : {});
  return String(active.status || active.queue_status || '').toLowerCase();
}

function queueStatus(state, operation) {
  const info = state[`${operation}_queue_info`] || {};
  return String(info.status || info.queue_status || info.job_status || '').toLowerCase();
}

function isRunningStatus(status) {
  return ['pending', 'queued', 'running', 'processing', 'retrying'].includes(String(status || '').toLowerCase());
}

function isFailureStatus(status) {
  return ['error', 'failed', 'failure', 'stop', 'stopped', 'cancelled', 'canceled'].includes(String(status || '').toLowerCase());
}

function stateMessage(state, operation) {
  const active = state.active_operations && state.active_operations[operation]
    ? state.active_operations[operation]
    : (state.active_operation || {});
  const info = state[`${operation}_queue_info`] || {};
  return String(active.message || info.process || info.message || state.message || '').trim();
}

function normalizeDomain(value) {
  return String(value || '').trim().replace(/^https?:\/\//i, '').replace(/\/.*$/, '').toLowerCase();
}

function publishedUrlFromDomain(domain) {
  const normalized = normalizeDomain(domain);
  return normalized ? `https://${normalized}/` : '';
}

function slugifyDomainLabel(value) {
  const label = String(value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 42)
    .replace(/-+$/g, '');
  return label || 'ai-site-once';
}

function planConfirmedFromPayload(payload) {
  return Number(
    payload?.data?.plan_confirmed
    ?? payload?.data?.state?.plan_confirmed
    ?? payload?.state?.plan_confirmed
    ?? payload?.plan_confirmed
    ?? 0
  ) === 1;
}

function planRequiresStaleConfirmation(payload) {
  return Boolean(
    payload?.requires_confirmation
    || payload?.data?.requires_confirmation
    || payload?.state?.requires_confirmation
    || payload?.confirmation_code === 'PLAN_INPUT_STALE_CONFIRM'
    || payload?.data?.confirmation_code === 'PLAN_INPUT_STALE_CONFIRM'
    || payload?.code === 'PLAN_INPUT_STALE'
    || payload?.error_code === 'PLAN_INPUT_STALE'
  );
}

function buildAlreadyStarted(state) {
  const workspaceStatus = String(state.workspace_status || state.stage || '').toLowerCase();
  return ['can_publish', 'published', 'publishing'].includes(workspaceStatus)
    || isRunningStatus(activeStatus(state, 'build'))
    || isRunningStatus(queueStatus(state, 'build'))
    || ['done', 'complete', 'completed', 'success'].includes(activeStatus(state, 'build'))
    || ['done', 'complete', 'completed', 'success'].includes(queueStatus(state, 'build'));
}

function hasPlanPayload(state) {
  const candidates = [
    state?.plan_json,
    state?.plan?.json,
    state?.plan?.plan_json,
    state?.scope?.plan_json,
    state?.data?.plan_json,
  ];
  return candidates.some((candidate) => candidate && typeof candidate === 'object' && Object.keys(candidate).length > 0);
}

async function workspaceState(api, publicId) {
  const data = await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-workspace-state', {
    data: { public_id: publicId, state_mode: 'queue_poll' },
  }, 'workspace state');
  return pickState(data);
}

async function waitForPlanReady(api, publicId) {
  const deadline = Date.now() + planDeadlineMs;
  let last = '';
  while (Date.now() <= deadline) {
    let state = await workspaceState(api, publicId).catch(() => ({}));
    const planActive = activeStatus(state, 'plan');
    const planQueue = queueStatus(state, 'plan');
    const progress = state.plan_queue_info?.stage1_page_progress || {};
    const stateConfirmed = Number(state.plan_confirmed ?? state.build_plan_confirmed ?? state.scope?.plan_confirmed ?? 0) === 1;
    const lineBeforeConfirm = `plan confirmed=${stateConfirmed ? 1 : 0} queue=${planQueue} active=${planActive} pages=${progress.done_count || 0}/${progress.total || 0} running=${progress.running_count || 0} failed=${progress.failed_count || 0} msg=${stateMessage(state, 'plan').slice(0, 180)}`;
    if (lineBeforeConfirm !== last) {
      log(lineBeforeConfirm);
      last = lineBeforeConfirm;
    }
    if (stateConfirmed || buildAlreadyStarted(state)) {
      return state;
    }
    if (isFailureStatus(planActive) || isFailureStatus(planQueue)) {
      throw new Error(`plan failed: ${stateMessage(state, 'plan') || jsonSummary(state.plan_queue_info || state, 1000)}`);
    }
    if (isRunningStatus(planActive) || isRunningStatus(planQueue) || !hasPlanPayload(state)) {
      await sleep(pollMs);
      continue;
    }

    const confirm = await apiPost(api, route('/pagebuilder/backend/ai-site-agent/post-confirm-plan'), {
      data: { public_id: publicId },
    }, 'confirm plan');
    const status = confirm.status();
    const text = await confirm.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      data = { success: false, message: text.slice(0, 300) };
    }
    if (planRequiresStaleConfirmation(data)) {
      state = await workspaceState(api, publicId).catch(() => state);
      if (isRunningStatus(activeStatus(state, 'plan')) || isRunningStatus(queueStatus(state, 'plan'))) {
        data = {
          ...data,
          message: data.message || data?.data?.message || 'stale confirmation waiting for plan queue to finish',
        };
      } else {
        log('plan stale confirmation requested after plan queue finished; sending force_confirm_stale_plan=1');
        const forced = await apiPost(api, route('/pagebuilder/backend/ai-site-agent/post-confirm-plan'), {
          data: { public_id: publicId, force_confirm_stale_plan: '1' },
        }, 'confirm stale plan');
        const forcedText = await forced.text();
        try {
          data = forcedText ? JSON.parse(forcedText) : {};
        } catch {
          data = { success: false, message: forcedText.slice(0, 300) };
        }
        state = await workspaceState(api, publicId).catch(() => state);
      }
    }
    const confirmed = planConfirmedFromPayload(data);
    const msg = String(data.message || data?.data?.message || '').trim();
    const line = `plan confirmed=${confirmed ? 1 : 0} status=${status} queue=${queueStatus(state, 'plan')} active=${activeStatus(state, 'plan')} pages=${progress.done_count || 0}/${progress.total || 0} running=${progress.running_count || 0} failed=${progress.failed_count || 0} msg=${(msg || stateMessage(state, 'plan')).slice(0, 180)}`;
    if (line !== last) {
      log(line);
      last = line;
    }
    if (confirmed || stateConfirmed || buildAlreadyStarted(state)) {
      return state;
    }
    await sleep(pollMs);
  }
  throw new Error('plan did not confirm before timeout');
}

async function waitForBuildReady(api, publicId) {
  const deadline = Date.now() + buildDeadlineMs;
  let last = '';
  while (Date.now() <= deadline) {
    const state = await workspaceState(api, publicId);
    const gate = state.build_completion_gate || state.completion_gate || {};
    const legacyBuildSummary = state.build_summary && typeof state.build_summary === 'object' ? state.build_summary : {};
    const legacyGate = legacyBuildSummary.completion_gate && typeof legacyBuildSummary.completion_gate === 'object'
      ? legacyBuildSummary.completion_gate
      : {};
    const buildSummary = state.build_task_summary || state.task_summary || {};
    const done = Number(buildSummary.done ?? buildSummary.completed ?? gate.done_count ?? 0);
    const total = Number(buildSummary.total ?? gate.total_count ?? 0);
    const failed = Number(buildSummary.failed ?? gate.failed_count ?? 0);
    const legacyDone = Number(legacyGate.done_count ?? legacyBuildSummary.done_count ?? legacyBuildSummary.done ?? 0);
    const legacyTotal = Number(legacyGate.total_count ?? legacyBuildSummary.total_count ?? legacyBuildSummary.total ?? 0);
    const legacyFailed = Number(legacyGate.failed_count ?? legacyBuildSummary.failed_count ?? legacyBuildSummary.failed ?? 0);
    const canPublish = Boolean(state.can_publish)
      || Number(state.can_publish || 0) === 1
      || Boolean(legacyBuildSummary.can_publish)
      || Number(legacyBuildSummary.can_publish || 0) === 1
      || Boolean(legacyGate.passed);
    const line = `build can_publish=${canPublish ? 1 : 0} active=${activeStatus(state, 'build')} queue=${queueStatus(state, 'build')} tasks=${done || legacyDone}/${total || legacyTotal} failed=${failed || legacyFailed} stage=${state.stage || state.workspace_stage || state.workspace_status || ''} msg=${stateMessage(state, 'build').slice(0, 180)}`;
    if (line !== last) {
      log(line);
      last = line;
    }
    if (canPublish
      || (total > 0 && done >= total && failed === 0)
      || (legacyTotal > 0 && legacyDone >= legacyTotal && legacyFailed === 0)
    ) {
      return state;
    }
    if (failed > 0 || legacyFailed > 0 || isFailureStatus(activeStatus(state, 'build')) || isFailureStatus(queueStatus(state, 'build'))) {
      throw new Error(`build failed: ${stateMessage(state, 'build') || jsonSummary(state.build_queue_info || state, 1000)}`);
    }
    await sleep(pollMs);
  }
  throw new Error('build did not become publishable before timeout');
}

async function waitForPublishDone(api, publicId) {
  const deadline = Date.now() + publishDeadlineMs;
  let last = '';
  while (Date.now() <= deadline) {
    const state = await workspaceState(api, publicId);
    const status = activeStatus(state, 'publish') || queueStatus(state, 'publish') || String(state.workspace_status || '').toLowerCase();
    const finalUrl = String(state.final_url || state.published_url || state.website_url || state.site_url || '').trim();
    const line = `publish status=${status} final_url=${finalUrl || '-'} msg=${stateMessage(state, 'publish').slice(0, 180)}`;
    if (line !== last) {
      log(line);
      last = line;
    }
    if (finalUrl || ['published', 'done', 'complete', 'completed', 'success'].includes(status)) {
      return state;
    }
    if (isFailureStatus(status)) {
      throw new Error(`publish failed: ${stateMessage(state, 'publish') || jsonSummary(state, 1000)}`);
    }
    await sleep(pollMs);
  }
  throw new Error('publish did not finish before timeout');
}

async function main() {
  fs.mkdirSync(outDir, { recursive: true });
  log(`out_dir=${outDir}`);
  log(`base=${baseUrl} prefix=${prefix}`);

  const api = await request.newContext({
    baseURL: baseUrl,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: { 'X-Requested-With': 'XMLHttpRequest' },
  });

  log('login page');
  const loginPage = await api.get(route('/admin/login'));
  const loginHtml = await loginPage.text();
  const formKey = /name="form_key"\s+value="([^"]+)"/.exec(loginHtml)?.[1] || '';
  log(`form_key=${formKey ? 'yes' : 'no'}`);
  const loginPost = await apiPost(api, route('/USD/en_US/admin/login/post'), {
    form: { form_key: formKey, username: adminUser, password: adminPassword, remember: 'on' },
    maxRedirects: 0,
  }, 'login post');
  if (![200, 302, 303].includes(loginPost.status())) {
    throw new Error(`login failed HTTP ${loginPost.status()}: ${(await loginPost.text()).slice(0, 500)}`);
  }
  const storageState = await api.storageState();
  const hasSessionCookie = storageState.cookies.some((cookie) => cookie.name === 'WELINE_SESSID');
  if (!hasSessionCookie) {
    throw new Error('login did not produce WELINE_SESSID');
  }
  log('login ok');

  let browser;
  let context;
  let page;
  try {
    const tag = runId.slice(8, 12);
    const defaultBrief = [
      'Create a polished English official recommendation website for India card-game APK downloads.',
      'The site helps Indian mobile players compare and download trusted Teen Patti, Rummy, Poker, and casual card game APKs with clear safety notes, feature comparisons, bonus explanations, and responsible-play guidance.',
      'Tone: premium, energetic, trustworthy, mobile-first, and entertainment-focused. Avoid generic marketing repetition. Use varied page structures, rich imagery, and strong editorial hierarchy.',
      'Audience: Indian Android users looking for reliable card-game APK download recommendations, safe installation guidance, and game comparison content.',
    ].join(' ');
    const siteTitleBase = String(process.env.WELINE_ONCE_SITE_TITLE || 'India Card Game APK Guide').trim();
    const siteTitle = String(process.env.WELINE_ONCE_SITE_TITLE_EXACT || `${siteTitleBase} ${tag}`).trim();
    const siteTagline = String(process.env.WELINE_ONCE_SITE_TAGLINE || 'Trusted APK picks for India card-game players').trim();
    const brief = String(process.env.WELINE_ONCE_BRIEF || defaultBrief).trim();
    const fallbackDomain = normalizeDomain(
      process.env.WELINE_ONCE_FALLBACK_DOMAIN || `${slugifyDomainLabel(siteTitleBase)}-${tag}.weline.test`
    );

    log('create session');
    const create = await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-create-session', {
      data: {
        site_title: siteTitle,
        brief_description: brief,
        default_locale: 'en_US',
        design_direction_mode: 'auto',
      },
    }, 'create session');
    const publicId = String(create.public_id || create.data?.public_id || '').trim();
    if (!publicId) {
      throw new Error(`missing public_id: ${jsonSummary(create)}`);
    }
    log(`public_id=${publicId}`);

    log('recommend domain');
    const recommend = await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-recommend-domain', {
      data: {
        description: brief,
        defer_availability_check: true,
      },
    }, 'recommend domain');
    const rawCandidates = recommend.candidate_domains || recommend.data?.candidate_domains || recommend.recommended_domain_list || [];
    const recommendedDomain = normalizeDomain(rawCandidates[0] || recommend.domain || recommend.data?.domain || fallbackDomain);
    const candidates = [recommendedDomain, ...rawCandidates.map((domain) => normalizeDomain(domain))]
      .filter((domain, index, list) => domain && list.indexOf(domain) === index);
    const workspaceDomain = recommendedDomain;
    log(`recommended_domain=${recommendedDomain}`);
    log(`workspace_domain=${workspaceDomain}`);

    log('merge scope');
    await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-merge-scope', {
      data: {
        public_id: publicId,
        scope_patch: {
          site_title: siteTitle,
          site_tagline: siteTagline,
          target_domain: workspaceDomain,
          selected_domain: workspaceDomain,
          recommended_domain_list: [recommendedDomain, ...candidates.filter((domain) => domain !== recommendedDomain).slice(0, 4)],
          brief_description: brief,
          user_description: brief,
          default_locale: 'en_US',
          plan_locale: 'en_US',
          page_types: pageTypes,
          selected_skill_codes: ['claude-design'],
        },
      },
    }, 'merge scope');

    browser = await chromium.launch({
      headless: process.env.WELINE_HEADED === '0',
    });
    context = await browser.newContext({
      ignoreHTTPSErrors: true,
      storageState,
      viewport: { width: 1440, height: 1000 },
    });
    page = await context.newPage();

    const workspaceUrl = `${baseUrl}${route(`/USD/en_US/pagebuilder/backend/ai-site-agent/workspace?public_id=${encodeURIComponent(publicId)}&codex_once=${runId}`)}`;
    log(`workspace=${workspaceUrl}`);
    await page.goto(workspaceUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.screenshot({ path: path.join(outDir, 'workspace-initial.png'), fullPage: false });

    log('start plan');
    await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-start-plan', {
      data: {
        public_id: publicId,
        selected_skill_codes: ['claude-design'],
        scope_patch: {
          page_types: pageTypes,
          target_domain: workspaceDomain,
          selected_domain: workspaceDomain,
          default_locale: 'en_US',
          plan_locale: 'en_US',
        },
      },
    }, 'start plan');
    const planState = await waitForPlanReady(api, publicId);
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.screenshot({ path: path.join(outDir, 'plan-confirmed.png'), fullPage: true });

    log('start build');
    if (buildAlreadyStarted(planState)) {
      log('build already queued by plan confirmation');
    } else {
      await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-start-build', {
        data: { public_id: publicId },
      }, 'start build');
    }
    const buildState = await waitForBuildReady(api, publicId);
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.screenshot({ path: path.join(outDir, 'build-ready-workspace.png'), fullPage: true });

    const previewPages = ['home_page', 'about_page', 'contact_page', 'blog_list'].filter((pageType) => pageTypes.includes(pageType));
    const previewAudit = [];
    for (const pageType of previewPages) {
      const previewUrl = `${baseUrl}${route(`/pagebuilder/backend/ai-site-agent/workspace-preview?public_id=${encodeURIComponent(publicId)}&page_type=${encodeURIComponent(pageType)}&preview=1`)}`;
      await page.goto(previewUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.screenshot({ path: path.join(outDir, `preview-${pageType}.png`), fullPage: true });
      const audit = await page.evaluate(() => {
        const headings = Array.from(document.querySelectorAll('h1,h2,h3')).map((el) => el.textContent.trim()).filter(Boolean);
        const sections = Array.from(document.querySelectorAll('section,[data-pb-block-id],[data-component-code]')).length;
        const bodyText = document.body ? document.body.innerText.replace(/\s+/g, ' ').trim() : '';
        const titleCounts = headings.reduce((acc, text) => {
          const key = text.toLowerCase();
          acc[key] = (acc[key] || 0) + 1;
          return acc;
        }, {});
        const repeatedHeadings = Object.entries(titleCounts).filter(([, count]) => count > 1).map(([text, count]) => ({ text, count }));
        return {
          title: document.title,
          sections,
          headings: headings.slice(0, 16),
          repeatedHeadings,
          textLength: bodyText.length,
        };
      });
      previewAudit.push({ pageType, previewUrl, ...audit });
      log(`preview ${pageType} sections=${audit.sections} text=${audit.textLength} repeated_headings=${audit.repeatedHeadings.length}`);
    }
    fs.writeFileSync(path.join(outDir, 'preview-audit.json'), JSON.stringify(previewAudit, null, 2));

    log('publish checklist');
    const checklist = await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-publish-checklist', {
      data: { public_id: publicId },
    }, 'publish checklist');
    log(`publish_checklist=${jsonSummary(checklist, 1000)}`);

    log('start publish');
    const publishStart = await postOkJson(api, '/pagebuilder/backend/ai-site-agent/post-start-publish', {
      data: { public_id: publicId, confirm_visual_theme: 1 },
    }, 'start publish');
    log(`publish_start=${jsonSummary(publishStart, 1000)}`);
    const publishState = await waitForPublishDone(api, publicId);

    const finalDomain = normalizeDomain(
      publishState.target_domain
      || publishState.selected_domain
      || recommendedDomain
      || buildState.target_domain
      || buildState.selected_domain
    );
    const finalUrl = String(
      publishState.final_url
      || publishState.published_url
      || publishState.website_url
      || publishState.site_url
      || publishStart.final_url
      || publishStart.data?.final_url
      || publishedUrlFromDomain(finalDomain)
      || ''
    ).trim();
    if (finalUrl) {
      await page.goto(finalUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch((error) => {
        log(`final_url_load_warning=${error.message}`);
      });
      await page.screenshot({ path: path.join(outDir, 'published-site.png'), fullPage: true }).catch(() => {});
    }

    const result = {
      success: true,
      public_id: publicId,
      workspace_url: workspaceUrl,
      recommended_domain: recommendedDomain,
      workspace_domain: workspaceDomain,
      final_url: finalUrl,
      page_types: pageTypes,
      out_dir: outDir,
      preview_audit: previewAudit,
      build_can_publish: Boolean(buildState.can_publish) || Number(buildState.can_publish || 0) === 1,
    };
    fs.writeFileSync(path.join(outDir, 'result.json'), JSON.stringify(result, null, 2));
    log(`RESULT ${JSON.stringify(result)}`);
  } finally {
    if (context) {
      await context.close().catch(() => {});
    }
    if (browser) {
      await browser.close().catch(() => {});
    }
    await api.dispose().catch(() => {});
  }
}

main().catch((error) => {
  log(`ERROR ${error.stack || error.message || String(error)}`);
  process.exitCode = 1;
});
