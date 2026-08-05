/** @weline-e2e-spec { module: Weline_Customer, type: flow, layer: backend } */
const path=require('path');
const {spawnSync}=require('child_process');
const {test,expect,loginAsAdmin,moduleDescribe,moduleCase,installBackendBrowserGuards,openBackendMenuBySource,BACKEND_FATAL_PATTERN}=require('../../../../../../../tests/e2e/framework');
const ROOT_DIR=path.resolve(__dirname,'../../../../../../..');
const FIXTURE=path.resolve(__dirname,'customer-r43-write-fixture.php');
function fixture(action,token){
 const result=spawnSync('php',[FIXTURE],{cwd:ROOT_DIR,input:JSON.stringify({action,token}),encoding:'utf8',stdio:['pipe','pipe','pipe']});
 if(result.error)throw result.error;
 const output=String(result.stdout||'');
 const value=JSON.parse(String(output).trim().split(/\n/).filter(Boolean).pop()||'{}');
 if(!value.ok||result.status!==0)throw new Error(value.error||String(result.stderr||'')||`fixture ${action} exited ${result.status}`); return value;
}
function token(){return Date.now().toString(36)+Math.random().toString(36).slice(2,8);}
async function openMenu(page,e,guards=installBackendBrowserGuards(page)){
 await loginAsAdmin(page,{timeout:90000,settleMs:800});
 await openBackendMenuBySource(page,e.source,{title:e.title,parentSources:[e.parent],urlIncludes:e.url});
 await expect(page.locator(e.anchor)).toHaveCount(1); await expect(page.locator(e.anchor)).toBeVisible();
 await expect(page.locator(e.anchor).locator('.alert-danger')).toHaveCount(0);
 await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
 guards.assertClean();
}

const MODULE='Weline_Customer';
moduleDescribe(test,MODULE,'R4.3 Customer 后台菜单',()=>{
 moduleCase(test,{module:MODULE,id:'CK-R43-CUSTOMER-001'},'从侧栏进入客户列表',async({page})=>openMenu(page,{source:'Weline_Customer::customer_index',title:'客户列表',parent:'Weline_Backend::customer_group',url:'/customer/backend/customer/index',anchor:'[data-testid="customer-management"]'}));
 moduleCase(test,{module:MODULE,id:'CK-R43-CUSTOMER-101'},'通过后台 UI 创建客户并验证 PostgreSQL 持久化',async({page})=>{
  const ownedToken=token(); const seed=fixture('prepare',ownedToken); const guards=installBackendBrowserGuards(page);
  try{
   await openMenu(page,{source:'Weline_Customer::customer_index',title:'客户列表',parent:'Weline_Backend::customer_group',url:'/customer/backend/customer/index',anchor:'[data-testid="customer-management"]'},guards);
   await page.locator('[data-testid="customer-create"]').click();
   await expect(page.locator('[data-testid="customer-form"]')).toBeVisible();
   await page.locator('#customer_username').fill(seed.username);
   await page.locator('#customer_password').fill(seed.password);
   await page.locator('#customer_avatar').fill(seed.avatar);
   await page.locator('#customer_is_sandbox').check();
   await page.locator('#customerForm button[type="submit"]').click();
   await expect(page.getByText(seed.username,{exact:true})).toBeVisible({timeout:20000});
   await expect.poll(()=>fixture('inspect',ownedToken).rows.length,{timeout:15000}).toBe(1);
   const persisted=fixture('inspect',ownedToken).rows[0];
   expect(persisted).toMatchObject({username:seed.username,avatar:seed.avatar,is_sandbox:1});
   guards.assertClean();
  }finally{fixture('cleanup',ownedToken);}
 });
});
