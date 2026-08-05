/** @weline-e2e-spec { module: Weline_Cart, type: flow, layer: backend } */
const path=require('path');
const {execFileSync}=require('child_process');
const {test,expect,gotoFrontend,loginAsAdmin,moduleDescribe,moduleCase,installBackendBrowserGuards,openBackendMenuBySource,BACKEND_FATAL_PATTERN}=require('../../../../../../../tests/e2e/framework');
const ROOT_DIR=path.resolve(__dirname,'../../../../../../..');
const FIXTURE=path.resolve(__dirname,'cart-r43-inspection-fixture.php');
function fixture(action,token=''){
 const out=execFileSync('php',[FIXTURE],{cwd:ROOT_DIR,input:JSON.stringify({action,token}),encoding:'utf8'});
 const value=JSON.parse(String(out).trim().split(/\n/).filter(Boolean).pop()||'{}');
 if(!value.ok)throw new Error(value.error||out); return value;
}
function payload(result){return result&&result.data&&typeof result.data==='object'?result.data:result||{};}
function succeeded(result){return !!(result&&(result.success===true||(result.data&&result.data.success===true)));}
async function cartApi(page,operation,params={}){
 return page.evaluate(async({operation,params})=>{
  try{
   let api=window.Weline&&window.Weline.Api;
   if((!api||typeof api.resource!=='function')&&window.Weline&&typeof window.Weline.load==='function')api=await window.Weline.load('api');
   const cart=await api.resource('cart');
   return await cart[operation](params,{useProxy:false});
  }catch(error){return {success:false,error:String(error&&(error.message||error))};}
 },{operation,params});
}
const MODULE='Weline_Cart';
moduleDescribe(test,MODULE,'R4.3 Cart 后台检查',()=>moduleCase(test,{module:MODULE,id:'CK-R43-CART-001'},'从侧栏查询真实 Cart V2 持久缓存',async({page})=>{
 const seed=fixture('prepare'); const guards=installBackendBrowserGuards(page);
 let guestToken=''; let cartCleared=false;
 try{
  await gotoFrontend(page,'/',{timeout:60000,settleMs:500});
  const issued=await cartApi(page,'issueGuestToken');
  expect(succeeded(issued),JSON.stringify(issued)).toBeTruthy();
  guestToken=String(payload(issued).guest_token||issued.guest_token||'');
  expect(guestToken).not.toBe('');
  const added=await cartApi(page,'addV2',{provider_code:seed.provider_code,global_offer_uuid:seed.offer_uuid,guest_token:guestToken,qty:1});
  expect(succeeded(added),JSON.stringify(added)).toBeTruthy();
  const scopeKey=String(payload(added).scope_key||added.scope_key||'');
  expect(scopeKey).not.toBe('');
  await loginAsAdmin(page,{timeout:90000,settleMs:800});
  await openBackendMenuBySource(page,'Weline_Cart::cart_inspection',{title:'购物车检查',parentSources:['Weline_Backend::order_group'],urlIncludes:'/cart/backend/inspection/index'});
  const root=page.locator('[data-testid="cart-inspection-management"]'); await expect(root).toHaveCount(1); await expect(root).toBeVisible();
  await page.locator('[data-testid="cart-scope-key"]').fill(scopeKey);
  await page.locator('[data-testid="cart-inspection-submit"]').click();
  await expect(page).toHaveURL(/cart\/backend\/inspection\/index.*scope_key=/);
  await expect(page.locator('[data-testid="cart-result-count"]')).toContainText('1');
  await expect(page.locator('[data-testid="cart-inspection-row"]')).toHaveCount(1);
  await expect(root.locator('.alert-danger')).toHaveCount(0);
  await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
  guards.assertClean();
 }finally{
  if(guestToken){
   await gotoFrontend(page,'/',{timeout:60000,settleMs:300});
   const cleared=await cartApi(page,'clearV2');
   expect(succeeded(cleared),JSON.stringify(cleared)).toBeTruthy();
   expect(payload(cleared).is_empty).toBe(true);
   cartCleared=true;
  }
  const cleaned=fixture('cleanup',seed.token);expect(cleaned.missing).toBe(true);
  if(guestToken)expect(cartCleared).toBe(true);
 }
}));
