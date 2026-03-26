// @weline-e2e-runtime wls
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildTargetUrl, buildBackendUrl } = require('../../framework');

const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CHAT_TECHNICAL_PATTERN = /未注册的查询器|QueryProviderInterface|Too few arguments|ArgumentCountError|executeAgentStream|executeAgent\(/i;

const PAGES = [
  { route: 'bot/backend/role/listing', title: /Role Management/i },
  { route: 'bot/backend/skill/listing', title: /Skill Management/i },
  { route: 'bot/backend/schedule/listing', title: /Schedule Management/i },
  { route: 'bot/backend/session/listing', title: /Session Management/i },
  { route: 'bot/backend/memory/listing', title: /Memory Management/i },
  { route: 'bot/backend/chat/index', title: /Bot Chat Console/i },
];

async function submitFormAsJson(page, selector) {
  return await page.locator(selector).evaluate(async form => {
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

    const raw = await response.text();
    try {
      return JSON.parse(raw);
    } catch (error) {
      return {
        success: false,
        status: response.status,
        raw,
      };
    }
  });
}

async function postWithCsrf(page, url, selector, extraFields = {}) {
  return await page.locator(selector).evaluate(async (form, payload) => {
    const csrfInput = form.querySelector('input[name="csrf"]');
    const body = new URLSearchParams();
    if (csrfInput && csrfInput.value) {
      body.append('csrf', csrfInput.value);
    }

    for (const [key, value] of Object.entries(payload.extraFields || {})) {
      body.append(key, String(value));
    }

    const response = await fetch(payload.url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: body.toString(),
    });

    const raw = await response.text();
    try {
      return JSON.parse(raw);
    } catch (error) {
      return {
        success: false,
        status: response.status,
        raw,
      };
    }
  }, { url, extraFields });
}

async function createRole(page, suffix) {
  await gotoBackend(page, 'bot/backend/role/add', {
    timeout: 60000,
    settleMs: 1200,
  });

  const code = `e2e_role_${suffix}`;
  const name = `E2E Role ${suffix}`;

  await page.locator('#code').fill(code);
  await page.locator('#name').fill(name);
  await page.locator('#description').fill(`E2E role ${suffix}`);
  await page.locator('#system_prompt').fill(`Handle E2E flow ${suffix} safely.`);

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
  const apiUrl = buildTargetUrl('/bot/api/v1/chat/send');
  const payload = {
    message: `E2E session message ${suffix}`,
    role_code: roleCode,
    channel: 'web',
    context_id: `ctx_${suffix}`,
  };

  const raw = await page.evaluate(async ({ url, body }) => {
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
      text: await response.text(),
    };
  }, { url: apiUrl, body: payload });

  let parsed;
  try {
    parsed = JSON.parse(raw.text);
  } catch (error) {
    parsed = { success: false, raw: raw.text, status: raw.status };
  }

  return {
    requestPayload: payload,
    response: parsed,
  };
}

test.describe('Weline_Bot backend management', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
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

    await gotoBackend(page, 'bot/backend/role/add', {
      timeout: 60000,
      settleMs: 1200,
    });

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

  test('creates schedule and toggles status', async ({ page }) => {
    const suffix = Date.now();
    const role = await createRole(page, `${suffix}`);

    await gotoBackend(page, 'bot/backend/schedule/add', {
      timeout: 60000,
      settleMs: 1200,
    });

    await page.locator('input[name="name"]').fill(`E2E Schedule ${suffix}`);
    await page.locator('select[name="role_id"]').selectOption(String(role.id));
    await page.locator('input[name="trigger_expr"]').fill('15 * * * *');
    await page.locator('textarea[name="prompt"]').fill(`Run schedule ${suffix}`);
    await page.locator('textarea[name="context"]').fill(JSON.stringify({ scope: `e2e-${suffix}` }));

    const saveResult = await submitFormAsJson(page, 'form[action*="bot/backend/schedule/save"]');
    expect(saveResult.success).toBeTruthy();
    expect(Number(saveResult.data && saveResult.data.id)).toBeGreaterThan(0);

    const scheduleId = Number(saveResult.data.id);
    const toggleUrl = buildBackendUrl('bot/backend/schedule/toggle', { useProxy: false });
    const toggleResult = await postWithCsrf(
      page,
      toggleUrl,
      'form[action*="bot/backend/schedule/save"]',
      { id: scheduleId }
    );

    expect(toggleResult.success).toBeTruthy();
    expect(toggleResult.data && toggleResult.data.status).toBe('disabled');

    await gotoBackend(page, 'bot/backend/schedule/listing', {
      timeout: 60000,
      settleMs: 1200,
    });

    const row = page.locator('tr').filter({ hasText: `E2E Schedule ${suffix}` }).first();
    await expect(row).toBeVisible();
    await expect(row).toContainText(/disabled/i);
  });

  test('creates chat session and renders session detail', async ({ page }) => {
    const suffix = Date.now();
    const role = await createRole(page, `session_${suffix}`);
    const apiResult = await createSessionViaApi(page, role.code, `${suffix}`);

    expect(
      Number(apiResult.response?.data?.session_id || 0),
      JSON.stringify(apiResult.response)
    ).toBeGreaterThan(0);
    expect(JSON.stringify(apiResult.response)).not.toMatch(CHAT_TECHNICAL_PATTERN);

    const sessionId = Number(apiResult.response.data.session_id);
    await gotoBackend(page, `bot/backend/session/view?id=${sessionId}`, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toContainText(/Session Detail/i);
    await expect(body).toContainText(apiResult.requestPayload.message);
    await expect(body).not.toContainText(FATAL_PATTERN);
  });
});
