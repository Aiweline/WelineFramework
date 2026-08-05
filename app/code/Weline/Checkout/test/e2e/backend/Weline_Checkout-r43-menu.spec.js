/** @weline-e2e-spec { module: Weline_Checkout, type: flow, layer: backend } */
const {test,expect,loginAsAdmin,moduleDescribe,moduleCase,installBackendBrowserGuards,openBackendMenuBySource,BACKEND_FATAL_PATTERN}=require('../../../../../../../tests/e2e/framework');
async function openMenu(page,e){
 const guards=installBackendBrowserGuards(page);
 await loginAsAdmin(page,{timeout:90000,settleMs:800});
 await openBackendMenuBySource(page,e.source,{title:e.title,parentSources:[e.parent],urlIncludes:e.url});
 await expect(page.locator(e.anchor)).toHaveCount(1); await expect(page.locator(e.anchor)).toBeVisible();
 await expect(page.locator(e.anchor).locator('.alert-danger')).toHaveCount(0);
 await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
 guards.assertClean();
}

const MODULE='Weline_Checkout';
moduleDescribe(test,MODULE,'R4.3 Checkout 后台菜单',()=>{for(const e of[
{id:'CK-R43-CHECKOUT-001',source:'Weline_Checkout::checkout_sessions',title:'结账会话',parent:'Weline_Backend::order_group',url:'/checkout/backend/session/index',anchor:'[data-testid="checkout-session-management"]'},
{id:'CK-R43-CHECKOUT-002',source:'Weline_Checkout::checkout_diagnostics',title:'结账诊断',parent:'Weline_Backend::order_group',url:'/checkout/backend/session/diagnostics',anchor:'[data-testid="checkout-diagnostics"]'}
])moduleCase(test,{module:MODULE,id:e.id},'从侧栏进入'+e.title,async({page})=>openMenu(page,e));});
