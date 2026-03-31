// @weline-e2e-runtime wls
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildBackendUrl } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CHAT_TECHNICAL_PATTERN = /鏈敞鍐岀殑鏌ヨ鍣▅QueryProviderInterface|Too few arguments|ArgumentCountError|executeAgentStream|executeAgent\(/i;

const PAGES = [
  { route: 'bot/backend/role/listing', title: /Role Management/i },
  { route: 'bot/backend/skill/listing', title: /Skill Management/i },
  { route: 'bot/backend/schedule/listing', title: /Schedule Management/i },
  { route: 'bot/backend/session/listing', title: /Session Management/i },
  { route: 'bot/backend/memory/listing', title: /Memory Management/i },
  { route: 'bot/backend/chat/index', title: /Bot Chat Console/i },
];

function parseJsonSafe(raw, status = 0) {
  try {
    return JSON.parse(raw);
  } catch (error) {
    return {
      success: false,
      status,
      raw,
    };
  }
}

async function submitFormAsJson(page, selector) {
  const response = await page.locator(selector).evaluate(async form => {
    const formData = new FormData(form);
    const body = new URLSearchParams();

    for (const [key, value] of formData.entries()) {
      body.append(key, typeof value === 'string' ? value : '');
    }

    const response = await fetch(form.action, {
      method: (form.method || 'POST').toUpperCase(),
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: body.toString(),
    });

    return {
      status: response.status,
      raw: await response.text(),
    };
  });

  return parseJsonSafe(response.raw, response.status);
}

async function requestJson(page, url, { method = 'GET', body = null } = {}) {
  const response = await page.evaluate(async payload => {
    try {
      const response = await fetch(payload.url, {
        method: payload.method,
        headers: payload.body === null ? {
          'X-Requested-With': 'XMLHttpRequest',
        } : {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: payload.body,
      });

      return {
        status: response.status,
        raw: await response.text(),
      };
    } catch (error) {
      return {
        status: 0,
        raw: JSON.stringify({
          success: false,
          msg: error instanceof Error ? error.message : String(error),
        }),
      };
    }
  }, { url, method, body });

  return parseJsonSafe(response.raw, response.status);
}

async function gotoRoleAddForm(page) {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await gotoBackend(page, 'bot/backend/role/add', {
      timeout: 60000,
      settleMs: 1200,
    });

    const codeInput = page.locator('#code');
    if (await codeInput.count()) {
      await expect(codeInput).toBeVisible({ timeout: 15000 });
      return;
    }

    await loginAsAdmin(page, { timeout: 90000 });
  }

  await expect(page.locator('#code')).toBeVisible();
}

async function ensureCsrfToken(page, sourceRoute = 'bot/backend/role/add') {
  const tryReadToken = async () => {
    const tokenInput = page.locator('input[name="form_key"], input[name="csrf"]').first();
    if (!(await tokenInput.count())) {
      return null;
    }

    const name = await tokenInput.getAttribute('name').catch(() => '');
    const value = await tokenInput.inputValue().catch(() => '');
    if (!name || !value) {
      return null;
    }

    return { name, value };
  };

  const currentToken = await tryReadToken();
  if (currentToken) {
    return currentToken;
  }

  if (sourceRoute === 'bot/backend/role/add') {
    await gotoRoleAddForm(page);
  } else {
    await gotoBackend(page, sourceRoute, {
      timeout: 60000,
      settleMs: 1200,
    });
  }

  const refreshedToken = await tryReadToken();
  if (refreshedToken) {
    return refreshedToken;
  }

  await loginAsAdmin(page, { timeout: 90000 });
  if (sourceRoute === 'bot/backend/role/add') {
    await gotoRoleAddForm(page);
  } else {
    await gotoBackend(page, sourceRoute, {
      timeout: 60000,
      settleMs: 1200,
    });
  }

  return await tryReadToken();
}

async function postWithCsrf(page, url, extraFields = {}, options = {}) {
  const token = await ensureCsrfToken(page, options.sourceRoute);
  const body = new URLSearchParams();

  if (token) {
    body.append(token.name, token.value);
  }

  for (const [key, value] of Object.entries(extraFields)) {
    body.append(key, String(value));
  }

  return await requestJson(page, url, {
    method: 'POST',
    body: body.toString(),
  });
}

async function showRoleAdvancedFields(page) {
  const advancedFields = page.locator('#advancedFields');
  if (!(await advancedFields.isVisible())) {
    await page.locator('#toggleAdvancedBtn').click();
  }
  await expect(advancedFields).toBeVisible();
}

async function getChatHistory(page, sessionId, limit = 50) {
  const url = `/bot/api/v1/chat/history?session_id=${sessionId}&limit=${limit}`;
  return await requestJson(page, url);
}

async function getChatSessions(page, contextId, limit = 20) {
  const url = `/bot/api/v1/chat/sessions?channel=web&context_id=${encodeURIComponent(contextId)}&limit=${limit}`;
  return await requestJson(page, url);
}

async function clearChatHistory(page, sessionId) {
  const url = '/bot/api/v1/chat/clear';
  const body = new URLSearchParams();
  body.append('session_id', String(sessionId));
  return await requestJson(page, url, {
    method: 'POST',
    body: body.toString(),
  });
}

async function deleteRole(page, roleId) {
  return await postWithCsrf(page, buildBackendUrl('bot/backend/role/delete'), { id: roleId });
}

async function deleteSchedule(page, scheduleId) {
  return await postWithCsrf(
    page,
    buildBackendUrl('bot/backend/schedule/delete'),
    { id: scheduleId },
    { sourceRoute: 'bot/backend/schedule/add' }
  );
}

async function createRole(page, suffix, overrides = {}) {
  await gotoRoleAddForm(page);

  const code = overrides.code || `e2e_role_${suffix}`;
  const name = overrides.name || `E2E Role ${suffix}`;
  const description = overrides.description || `E2E role ${suffix}`;
  const prompt = overrides.systemPrompt || `Handle E2E flow ${suffix} safely.`;

  await page.locator('#code').fill(code);
  await page.locator('#name').fill(name);
  await page.locator('#description').fill(description);
  await page.locator('#system_prompt').fill(prompt);

  const result = await submitFormAsJson(page, 'form[action*="bot/backend/role/save"]');
  expect(result.success).toBeTruthy();
  expect(Number(result.data && result.data.id)).toBeGreaterThan(0);

  return {
    id: Number(result.data.id),
    code,
    name,
  };
}

async function createSessionViaApi(page, roleCode, suffix) {
  const apiUrl = '/bot/api/v1/chat/send';
  const payload = {
    message: `E2E session message ${suffix}`,
    role_code: roleCode,
    channel: 'web',
    context_id: `ctx_${suffix}`,
  };

  const response = await page.evaluate(async ({ url, body }) => {
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
      });

      return {
        status: response.status,
        raw: await response.text(),
      };
    } catch (error) {
      return {
        status: 0,
        raw: JSON.stringify({
          success: false,
          msg: error instanceof Error ? error.message : String(error),
        }),
      };
    }
  }, { url: apiUrl, body: payload });

  return {
    requestPayload: payload,
    response: parseJsonSafe(response.raw, response.status),
  };
}

test.describe('Weline_Bot backend management', () => {
  test.describe.configure({ retries: 1 });
  test.setTimeout(180000);

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  for (const pageCase of PAGES) {
    test(`renders ${pageCase.route}`, async ({ page }) => {
      await gotoBackend(page, pageCase.route, {
        timeout: 60000,
        settleMs: 1200,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible();
      await expect(body).toContainText(pageCase.title);
      await expect(body).not.toContainText(FATAL_PATTERN);
    });
  }

  test('renders schedule create form', async ({ page }) => {
    await gotoBackend(page, 'bot/backend/schedule/add', {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();
    await expect(body).toContainText(/Create Schedule|Edit Schedule/i);
    await expect(page.locator('form[action*="bot/backend/schedule/save"]')).toBeVisible();
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('creates guided role and saves it successfully', async ({ page }) => {
    const suffix = Date.now();

    await gotoRoleAddForm(page);

    await page.locator('#guide_brief').fill(`Manage program ${suffix} with daily summaries`);
    await page.locator('#target_outcome').fill(`Create priority board ${suffix}`);
    await page.locator('#generateSuggestionBtn').click();

    await expect(page.locator('#suggestionSource')).toBeVisible();
    await expect(page.locator('#system_prompt')).toHaveValue(new RegExp(String(suffix)));

    const roleCode = `e2e_guided_${suffix}`;
    const roleName = `E2E Guided ${suffix}`;
    await page.locator('#code').fill(roleCode);
    await page.locator('#name').fill(roleName);

    const result = await submitFormAsJson(page, 'form[action*="bot/backend/role/save"]');
    expect(result.success).toBeTruthy();
    expect(Number(result.data && result.data.id)).toBeGreaterThan(0);

    await gotoBackend(page, 'bot/backend/role/listing', {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toContainText(roleCode);
    await expect(body).toContainText(roleName);
    await expect(body).not.toContainText(FATAL_PATTERN);
  });

  test('persists advanced role config and exercises role lifecycle endpoints', async ({ page }) => {
    const suffix = Date.now();
    const code = `e2e_adv_role_${suffix}`;
    const name = `E2E Advanced Role ${suffix}`;
    const description = `Advanced role ${suffix}`;
    const permissionsText = `fs.read:/tmp/e2e/${suffix}\nhttp.request:https://example.com/${suffix}`;
    const modelConfigText = JSON.stringify({ temperature: 0.2, suffix }, null, 2);

    await gotoRoleAddForm(page);
    await showRoleAdvancedFields(page);

    let selectedSkillCode = '';
    const skillCheckboxes = page.locator('.role-skill-checkbox');
    if (await skillCheckboxes.count()) {
      selectedSkillCode = String(await skillCheckboxes.first().getAttribute('value'));
      await skillCheckboxes.first().check();
    }

    await page.locator('#code').fill(code);
    await page.locator('#name').fill(name);
    await page.locator('#description').fill(description);
    await page.locator('#system_prompt').fill(`Advanced prompt ${suffix}`);
    await page.locator('#permissions_text').fill(permissionsText);
    await page.locator('#model_config_text').fill(modelConfigText);

    const saveResult = await submitFormAsJson(page, 'form[action*="bot/backend/role/save"]');
    expect(saveResult.success).toBeTruthy();
    const roleId = Number(saveResult.data && saveResult.data.id);
    expect(roleId).toBeGreaterThan(0);

    await gotoBackend(page, `bot/backend/role/edit?id=${roleId}`, {
      timeout: 60000,
      settleMs: 1200,
    });
    await showRoleAdvancedFields(page);

    await expect(page.locator('#code')).toHaveValue(code);
    await expect(page.locator('#name')).toHaveValue(name);
    await expect(page.locator('#description')).toHaveValue(description);
    await expect(page.locator('#permissions_text')).toHaveValue(permissionsText);
    await expect(page.locator('#model_config_text')).toContainText(`"suffix": ${suffix}`);
    if (selectedSkillCode) {
      await expect(page.locator(`.role-skill-checkbox[value="${selectedSkillCode}"]`)).toBeChecked();
    }

    const toggleResult = await postWithCsrf(page, buildBackendUrl('bot/backend/role/toggle'), { id: roleId });
    expect(toggleResult.success).toBeTruthy();
    expect(toggleResult.data && toggleResult.data.status).toBe('disabled');

    await gotoBackend(page, 'bot/backend/role/listing', {
      timeout: 60000,
      settleMs: 1200,
    });
    const disabledRow = page.locator('tr').filter({ hasText: code }).first();
    await expect(disabledRow).toBeVisible();
    await expect(disabledRow).toContainText(/Disabled/i);

    const deleteResult = await deleteRole(page, roleId);
    expect(deleteResult.success).toBeTruthy();

    await gotoBackend(page, 'bot/backend/role/listing', {
      timeout: 60000,
      settleMs: 1200,
    });
    await expect(page.locator('tr').filter({ hasText: code })).toHaveCount(0);
  });

  test('filters, views, and toggles a skill', async ({ page }) => {
    await gotoBackend(page, 'bot/backend/skill/listing', {
      timeout: 60000,
      settleMs: 1200,
    });

    const emptyState = page.getByText('No skill records found.');
    if (await emptyState.isVisible().catch(() => false)) {
      await expect(emptyState).toBeVisible();

      await gotoRoleAddForm(page);
      await showRoleAdvancedFields(page);
      await expect(page.locator('.role-skill-checkbox')).toHaveCount(0);
      return;
    }

    const categorySelect = page.locator('select[name="category"]');
    const categoryOptions = await categorySelect.locator('option').evaluateAll(options =>
      options.map(option => option.value).filter(Boolean)
    );
    if (categoryOptions.length) {
      await categorySelect.selectOption(categoryOptions[0]);
      await page.locator('form[action*="bot/backend/skill/listing"] button[type="submit"]').click();
      await expect(categorySelect).toHaveValue(categoryOptions[0]);
    }

    let skillLinks = page.locator('a[href*="skill/view?id="]');
    if (!(await skillLinks.count())) {
      await gotoBackend(page, 'bot/backend/skill/listing', {
        timeout: 60000,
        settleMs: 1200,
      });
      skillLinks = page.locator('a[href*="skill/view?id="]');
    }

    if (!(await skillLinks.count())) {
      await expect(page.getByText('No skill records found.')).toBeVisible();
      return;
    }

    const firstSkill = await skillLinks.first().evaluate(link => {
      const row = link.closest('tr');
      const cells = row ? Array.from(row.querySelectorAll('td')).map(cell => cell.textContent?.trim() || '') : [];
      return {
        id: Number(cells[0] || 0),
        code: cells[1] || '',
        name: cells[2] || '',
        category: cells[3] || '',
        activeLabel: cells[5] || '',
      };
    });

    expect(firstSkill.id).toBeGreaterThan(0);
    expect(firstSkill.code).not.toBe('');
    expect(firstSkill.name).not.toBe('');

    await gotoBackend(page, `bot/backend/skill/view?id=${firstSkill.id}`, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toContainText(/Skill Detail/i);
    await expect(body).toContainText(firstSkill.code);
    await expect(body).toContainText(firstSkill.name);
    await expect(body).toContainText(/Parameters/i);
    await expect(body).toContainText(/Permission Required/i);
    await expect(body).not.toContainText(FATAL_PATTERN);

    const toggleResult = await postWithCsrf(page, buildBackendUrl('bot/backend/skill/toggle'), { id: firstSkill.id });
    expect(toggleResult.success).toBeTruthy();

    await gotoBackend(page, 'bot/backend/skill/listing', {
      timeout: 60000,
      settleMs: 1200,
    });
    const toggledRow = page.locator('tr').filter({ hasText: firstSkill.code }).first();
    await expect(toggledRow).toBeVisible();
    await expect(toggledRow).toContainText(/Enabled/i.test(firstSkill.activeLabel) ? /Disabled/i : /Enabled/i);

    const restoreResult = await postWithCsrf(page, buildBackendUrl('bot/backend/skill/toggle'), { id: firstSkill.id });
    expect(restoreResult.success).toBeTruthy();
  });

  test('creates, edits, runs, toggles, and deletes a schedule', async ({ page }) => {
    const suffix = Date.now();
    const role = await createRole(page, `${suffix}`);
    const scheduleName = `E2E Schedule ${suffix}`;
    const updatedName = `E2E Schedule Updated ${suffix}`;

    await gotoBackend(page, 'bot/backend/schedule/add', {
      timeout: 60000,
      settleMs: 1200,
    });

    await page.locator('input[name="name"]').fill(scheduleName);
    await page.locator('select[name="role_id"]').selectOption(String(role.id));
    await page.locator('input[name="trigger_expr"]').fill('15 * * * *');
    await page.locator('textarea[name="prompt"]').fill(`Run schedule ${suffix}`);
    await page.locator('textarea[name="context"]').fill(JSON.stringify({ scope: `e2e-${suffix}` }));

    const saveResult = await submitFormAsJson(page, 'form[action*="bot/backend/schedule/save"]');
    expect(saveResult.success).toBeTruthy();
    const scheduleId = Number(saveResult.data && saveResult.data.id);
    expect(scheduleId).toBeGreaterThan(0);

    await gotoBackend(page, `bot/backend/schedule/edit?id=${scheduleId}`, {
      timeout: 60000,
      settleMs: 1200,
    });
    await page.locator('input[name="name"]').fill(updatedName);
    await page.locator('textarea[name="prompt"]').fill(`Updated schedule prompt ${suffix}`);
    const updateResult = await submitFormAsJson(page, 'form[action*="bot/backend/schedule/save"]');
    expect(updateResult.success).toBeTruthy();

    const runResult = await postWithCsrf(
      page,
      buildBackendUrl('bot/backend/schedule/run'),
      { id: scheduleId },
      { sourceRoute: 'bot/backend/schedule/add' }
    );
    expect(runResult.success).toBeTruthy();

    const toggleResult = await postWithCsrf(
      page,
      buildBackendUrl('bot/backend/schedule/toggle'),
      { id: scheduleId },
      { sourceRoute: 'bot/backend/schedule/add' }
    );
    expect(toggleResult.success).toBeTruthy();
    expect(toggleResult.data && toggleResult.data.status).toBe('disabled');

    await gotoBackend(page, 'bot/backend/schedule/listing', {
      timeout: 60000,
      settleMs: 1200,
    });
    const row = page.locator('tr').filter({ hasText: updatedName }).first();
    await expect(row).toBeVisible();
    await expect(row).toContainText(/disabled/i);

    const deleteResult = await deleteSchedule(page, scheduleId);
    expect(deleteResult.success).toBeTruthy();

    await gotoBackend(page, 'bot/backend/schedule/listing', {
      timeout: 60000,
      settleMs: 1200,
    });
    await expect(page.locator('tr').filter({ hasText: updatedName })).toHaveCount(0);

    await deleteRole(page, role.id);
  });

  test('creates chat session and verifies console, history, clear, archive, and delete flows', async ({ page }) => {
    const suffix = Date.now();
    const role = await createRole(page, `session_${suffix}`);
    const apiResult = await createSessionViaApi(page, role.code, `${suffix}`);

    expect(
      Number(apiResult.response?.data?.session_id || 0),
      JSON.stringify(apiResult.response)
    ).toBeGreaterThan(0);
    expect(JSON.stringify(apiResult.response)).not.toMatch(CHAT_TECHNICAL_PATTERN);

    const sessionId = Number(apiResult.response.data.session_id);
    const historyResult = await getChatHistory(page, sessionId);
    expect(historyResult.success).toBeTruthy();
    expect(Number(historyResult.data && historyResult.data.session && historyResult.data.session.session_id)).toBe(sessionId);
    expect(JSON.stringify(historyResult.data && historyResult.data.messages)).toContain(apiResult.requestPayload.message);

    const sessionsResult = await getChatSessions(page, apiResult.requestPayload.context_id);
    expect(sessionsResult.success).toBeTruthy();
    expect(JSON.stringify(sessionsResult.data && sessionsResult.data.sessions)).toContain(`"session_id":${sessionId}`);

    await gotoBackend(page, 'bot/backend/chat/index', {
      timeout: 60000,
      settleMs: 1200,
    });
    await expect(page.locator('body')).toContainText(/Bot Chat Console/i);
    await expect(page.locator('body')).toContainText(role.name);
    await expect(page.locator('body')).toContainText(role.code);
    await expect(page.getByRole('link', { name: /Go To Role Management/i })).toBeVisible();

    await gotoBackend(page, `bot/backend/session/view?id=${sessionId}`, {
      timeout: 60000,
      settleMs: 1200,
    });
    const body = page.locator('body');
    await expect(body).toContainText(/Session Detail/i);
    await expect(body).toContainText(apiResult.requestPayload.message);
    await expect(body).not.toContainText(FATAL_PATTERN);

    const clearResult = await clearChatHistory(page, sessionId);
    expect(clearResult.success).toBeTruthy();

    await gotoBackend(page, `bot/backend/session/view?id=${sessionId}`, {
      timeout: 60000,
      settleMs: 1200,
    });
    await expect(page.locator('body')).toContainText(/No messages in this session yet\./i);

    const archiveResult = await postWithCsrf(page, buildBackendUrl('bot/backend/session/archive'), { id: sessionId });
    expect(archiveResult.success).toBeTruthy();

    await gotoBackend(page, 'bot/backend/session/listing?status=archived&channel=web', {
      timeout: 60000,
      settleMs: 1200,
    });
    const archivedRow = page.locator('tr').filter({ hasText: String(sessionId) }).first();
    await expect(archivedRow).toBeVisible();
    await expect(archivedRow).toContainText(/archived/i);

    const deleteResult = await postWithCsrf(page, buildBackendUrl('bot/backend/session/delete'), { id: sessionId });
    expect(deleteResult.success).toBeTruthy();

    await gotoBackend(page, 'bot/backend/session/listing?status=deleted&channel=web', {
      timeout: 60000,
      settleMs: 1200,
    });
    const deletedRow = page.locator('tr').filter({ hasText: String(sessionId) }).first();
    await expect(deletedRow).toBeVisible();
    await expect(deletedRow).toContainText(/deleted/i);

    await deleteRole(page, role.id);
  });

  test('renders memory filters and stable empty-or-data states', async ({ page }) => {
    await gotoBackend(page, 'bot/backend/memory/listing?type=fact&status=active', {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toContainText(/Memory Management/i);
    await expect(body).toContainText(/Memory Nodes/i);
    await expect(body).toContainText(/Recent Memory Edges/i);
    await expect(page.locator('select[name="type"]')).toHaveValue('fact');
    await expect(page.locator('select[name="status"]')).toHaveValue('active');
    await expect(body).not.toContainText(FATAL_PATTERN);

    const pageText = await body.innerText();
    expect(
      /No memory nodes found\.|No memory edges found\.|Recent Memory Edges/i.test(pageText),
      pageText
    ).toBeTruthy();
  });
});
