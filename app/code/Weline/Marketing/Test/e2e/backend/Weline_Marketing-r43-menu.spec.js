/** @weline-e2e-spec { module: Weline_Marketing, type: flow, layer: backend } */
const path=require('path');
const {execFileSync}=require('child_process');
const {test,expect,loginAsAdmin,moduleDescribe,moduleCase,installBackendBrowserGuards,openBackendMenuBySource,BACKEND_FATAL_PATTERN}=require('../../../../../../../tests/e2e/framework');
const ROOT_DIR=path.resolve(__dirname,'../../../../../../..');
const FIXTURE=path.resolve(__dirname,'marketing-r43-write-fixture.php');
function fixture(action,entity,token){
 const output=execFileSync('php',[FIXTURE],{cwd:ROOT_DIR,input:JSON.stringify({action,entity,token}),encoding:'utf8',stdio:['pipe','pipe','pipe']});
 const value=JSON.parse(String(output).trim().split(/\n/).filter(Boolean).pop()||'{}');
 if(!value.ok)throw new Error(value.error||output); return value;
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

const MODULE='Weline_Marketing';
moduleDescribe(test,MODULE,'R4.3 Marketing 后台菜单',()=>{for(const e of[
{id:'CK-R43-MARKETING-001',source:'Weline_Marketing::rule_list',title:'营销规则',url:'/marketing/backend/rule/index',anchor:'[data-testid="marketing-rule-management"]'},
{id:'CK-R43-MARKETING-002',source:'Weline_Marketing::coupon_list',title:'优惠券管理',url:'/marketing/backend/coupon/index',anchor:'[data-testid="marketing-coupon-management"]'},
{id:'CK-R43-MARKETING-003',source:'Weline_Marketing::campaign_list',title:'促销活动',url:'/marketing/backend/campaign/index',anchor:'[data-testid="marketing-campaign-management"]'}
])moduleCase(test,{module:MODULE,id:e.id},'从侧栏进入'+e.title,async({page})=>openMenu(page,{...e,parent:'Weline_Backend::marketing_group'}));

moduleCase(test,{module:MODULE,id:'CK-R43-MARKETING-101'},'通过后台 UI 创建营销规则并验证 PostgreSQL',async({page})=>{
 const ownedToken=token(); const seed=fixture('prepare','rule',ownedToken); const guards=installBackendBrowserGuards(page);
 try{
  await openMenu(page,{source:'Weline_Marketing::rule_list',title:'营销规则',url:'/marketing/backend/rule/index',anchor:'[data-testid="marketing-rule-management"]',parent:'Weline_Backend::marketing_group'},guards);
  await page.locator('[data-testid="marketing-rule-create"]').click();
  await expect(page.locator('[data-testid="marketing-rule-form"]')).toBeVisible();
  await page.locator('[data-testid="marketing-rule-name"]').fill(seed.rule_name);
  await page.locator('[data-testid="marketing-rule-type"]').selectOption('coupon');
  await page.locator('[data-testid="marketing-rule-status"]').selectOption('active');
  await page.locator('#marketing-rule-priority').fill('43');
  await page.locator('[data-testid="marketing-rule-submit"]').click();
  await expect(page).toHaveURL(/marketing\/backend\/rule\/index/,{timeout:20000});
  await expect(page.getByText(seed.rule_name,{exact:true})).toBeVisible();
  await expect.poll(()=>fixture('inspect','rule',ownedToken).rows.length,{timeout:15000}).toBe(1);
  expect(fixture('inspect','rule',ownedToken).rows[0]).toMatchObject({name:seed.rule_name,rule_type:'coupon',status:'active'});
  guards.assertClean();
 }finally{fixture('cleanup','rule',ownedToken);}
});

moduleCase(test,{module:MODULE,id:'CK-R43-MARKETING-102'},'通过后台 UI 创建优惠券并验证 PostgreSQL',async({page})=>{
 const ownedToken=token(); const seed=fixture('prepare','coupon',ownedToken); const guards=installBackendBrowserGuards(page);
 try{
  await openMenu(page,{source:'Weline_Marketing::coupon_list',title:'优惠券管理',url:'/marketing/backend/coupon/index',anchor:'[data-testid="marketing-coupon-management"]',parent:'Weline_Backend::marketing_group'},guards);
  await page.locator('[data-testid="marketing-coupon-create"]').click();
  await expect(page.locator('[data-testid="marketing-coupon-form"]')).toBeVisible();
  await page.locator('[data-testid="marketing-coupon-rule"]').selectOption(String(seed.rule_id));
  await page.locator('[data-testid="marketing-coupon-code"]').fill(seed.coupon_code);
  await page.locator('[data-testid="marketing-coupon-type"]').selectOption('percentage');
  await page.locator('[data-testid="marketing-coupon-value"]').fill('15');
  await page.locator('[data-testid="marketing-coupon-submit"]').click();
  await expect(page).toHaveURL(/marketing\/backend\/coupon\/index/,{timeout:20000});
  await expect(page.getByText(seed.coupon_code,{exact:true})).toBeVisible();
  await expect.poll(()=>fixture('inspect','coupon',ownedToken).rows.length,{timeout:15000}).toBe(1);
  expect(fixture('inspect','coupon',ownedToken).rows[0]).toMatchObject({rule_id:seed.rule_id,code:seed.coupon_code,type:'percentage',discount_value:15,status:'active'});
  guards.assertClean();
 }finally{fixture('cleanup','coupon',ownedToken);}
});

moduleCase(test,{module:MODULE,id:'CK-R43-MARKETING-103'},'通过后台 UI 创建促销活动并验证 PostgreSQL',async({page})=>{
 const ownedToken=token(); const seed=fixture('prepare','campaign',ownedToken); const guards=installBackendBrowserGuards(page);
 try{
  await openMenu(page,{source:'Weline_Marketing::campaign_list',title:'促销活动',url:'/marketing/backend/campaign/index',anchor:'[data-testid="marketing-campaign-management"]',parent:'Weline_Backend::marketing_group'},guards);
  await page.locator('[data-testid="marketing-campaign-create"]').click();
  await expect(page.locator('[data-testid="marketing-campaign-form"]')).toBeVisible();
  await page.locator('[data-testid="marketing-campaign-name"]').fill(seed.campaign_name);
  await page.locator('[data-testid="marketing-campaign-rule"]').selectOption(String(seed.rule_id));
  await page.locator('#marketing-campaign-status').selectOption('active');
  await page.locator('[data-testid="marketing-campaign-budget"]').fill('1250');
  await page.locator('[data-testid="marketing-campaign-start"]').fill('2026-08-04T09:00');
  await page.locator('[data-testid="marketing-campaign-end"]').fill('2026-08-10T18:00');
  await page.locator('[data-testid="marketing-campaign-submit"]').click();
  await expect(page).toHaveURL(/marketing\/backend\/campaign\/index/,{timeout:20000});
  await expect(page.getByText(seed.campaign_name,{exact:true})).toBeVisible();
  await expect.poll(()=>fixture('inspect','campaign',ownedToken).rows.length,{timeout:15000}).toBe(1);
  expect(fixture('inspect','campaign',ownedToken).rows[0]).toMatchObject({rule_id:seed.rule_id,name:seed.campaign_name,status:'active',budget:1250});
  guards.assertClean();
 }finally{fixture('cleanup','campaign',ownedToken);}
});
});
