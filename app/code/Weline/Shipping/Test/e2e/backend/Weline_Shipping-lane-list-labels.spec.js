/**
 * 航线列表显示承运商/模板/发货地址名称（非裸数字）
 *
 * @weline-e2e-spec { module: Weline_Shipping, type: flow, layer: backend }
 */
const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Shipping';
const ROOT = path.join(__dirname, '../../..');
const TPL = path.join(ROOT, 'view/templates/Backend/ShippingService/index.phtml');

moduleDescribe(test, MODULE, '航线列表名称标签', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-LANE-LABELS-001' },
    '模板映射承运商/模板/发货锚点名且禁止裸 echo ID',
    async () => {
      const tpl = fs.readFileSync(TPL, 'utf8');
      expect(tpl).toContain('carrier_names_by_id');
      expect(tpl).toContain('template_names_by_id');
      expect(tpl).toContain('origin_names_by_id');
      expect(tpl).toContain('data-testid="lane-carrier-label"');
      expect(tpl).toContain('data-testid="lane-template-label"');
      expect(tpl).toContain('data-testid="lane-origin-label"');
      expect(tpl).not.toMatch(
        /<td>\s*<\?=\s*\(int\)\$service->getData\(ShippingService::schema_fields_CARRIER_ID\)\s*\?>\s*<\/td>/,
      );
    },
  );
});
