// Explicit single-page QA workflow: normal module loads and rules dialog only.
async function verifyHelpPayModule(page) {
  const checks = [];
  const check = (name, valid, observed) => checks.push({ name, result: valid ? 'pass' : 'fail', observed });
  try {
    const loading = await page.evaluate(async () => {
      const scripts = () => Array.from(document.scripts).filter(s => s.src.includes('/helppay-share.js')).map(s => s.src);
      const exported = window.WelineModules?.helpPayShare;
      const before = scripts();
      const first = await window.Weline.load('helpPayShare');
      const afterFirst = scripts();
      const second = await window.Weline.load('helpPayShare');
      return { exported_functions: Object.keys(exported || {}).filter(k => typeof exported[k] === 'function'), first_same_export: first === exported,
        second_same_export: second === exported, before, after_first: afterFirst, after_second: scripts(), anchor_count: document.querySelectorAll('[data-widget-script]').length };
    });
    check('actual_export_and_normal_load_identity', loading.exported_functions.includes('boot') && loading.first_same_export && loading.second_same_export, loading);
    check('normal_load_twice_no_duplicate_script', JSON.stringify(loading.before) === JSON.stringify(loading.after_first) && JSON.stringify(loading.before) === JSON.stringify(loading.after_second), loading);
    const trigger = page.locator('[data-testid="product-help-pay"] [data-helppay-open]');
    await trigger.click({ timeout: 10000 });
    const dialog = page.locator('.w-helppay-dialog');
    const rules = dialog.locator('[data-helppay-step="rules"]');
    const opened = await dialog.isVisible() && await rules.isVisible();
    check('safe_rules_dialog_opened_by_real_click', opened);
    await dialog.locator('[data-helppay-close]').click({ timeout: 10000 });
    check('safe_rules_dialog_closed', !await dialog.isVisible());
    const mechanism = await page.evaluate(async () => {
      let calls = 0;
      const key = 'qa-isolated-anchor-module-20261002';
      const anchor = document.createElement('span'); anchor.hidden = true; anchor.dataset.widgetScript = key;
      window.WelineWidgetAssets.register(key, () => { calls += 1; });
      const delivery = () => new Promise(resolve => setTimeout(resolve, 0));
      try { document.body.appendChild(anchor); await delivery(); anchor.remove(); document.body.appendChild(anchor); await delivery(); return { initializer_calls: calls, role: 'Isolated runtime mechanism diagnostic; not an original layout widget' }; }
      finally { anchor.remove(); }
    });
    return { result: checks.every(c => c.result === 'pass') ? 'pass' : 'fail', checks, added_anchor_mechanism_diagnostic: mechanism };
  } catch (error) { check('module_workflow_completed', false, { message: error.message }); return { result: 'fail', checks }; }
}
module.exports = { verifyHelpPayModule };
