/**
 * 航线列表名称显示计划链路组
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

moduleDescribe(test, MODULE, '航线列表名称计划链路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-LANE-LABELS-SUITE-001' },
    '完整功能通路 e2e 组：名称映射模板 + 契约测试文件',
    async () => {
      const tpl = fs.readFileSync(
        path.join(ROOT, 'view/templates/Backend/ShippingService/index.phtml'),
        'utf8',
      );
      const ut = fs.readFileSync(
        path.join(ROOT, 'Test/Unit/View/ShippingServiceLaneListLabelsContractTest.php'),
        'utf8',
      );
      expect(tpl).toContain('lane-carrier-label');
      expect(tpl).toContain('lane-template-label');
      expect(tpl).toContain('lane-origin-label');
      expect(ut).toContain('testLaneTableMapsIdsToReadableNames');
    },
  );
});
