// @weline-e2e-runtime wls
// @weline-e2e-transport direct
const { test, expect, loginAsAdmin, moduleDescribe, moduleCase } = require('../../../../../../../tests/e2e/framework');
moduleDescribe(test, 'Weline_I18n', '后台语言切换器国旗', () => {
  moduleCase(test, {module:'Weline_I18n',id:'UC-I18N-BACKEND-COUNTRY-FLAGS'}, '已认证后台首页及语言面板国旗正常加载', async ({page}) => {
    const failures=[];
    page.on('response', response=>{
      if(response.status()>=400) failures.push({path:new URL(response.url()).pathname,status:response.status()});
    });
    await loginAsAdmin(page,{timeout:60000,settleMs:1000,useProxy:false});
    const trigger=page.locator('[data-i18n-switcher] .w-language-switcher__trigger').first();
    const panelId=await trigger.getAttribute('aria-controls');
    await trigger.click();
    const panel=page.locator(`[id="${panelId}"]`);
    await expect(panel).toBeVisible();
    const flags=panel.locator('[data-country-flag]:not([data-country-flag=""])');
    expect(await flags.count()).toBeGreaterThan(0);
    await expect.poll(()=>flags.evaluateAll(elements=>elements.every(el=>{
      const img=el.querySelector('img');return img?.complete && img.naturalWidth>0;
    })),{timeout:30000}).toBe(true);
    expect(failures,'后台语言面板不得留下页面、资源或接口错误').toHaveLength(0);
  });
});
