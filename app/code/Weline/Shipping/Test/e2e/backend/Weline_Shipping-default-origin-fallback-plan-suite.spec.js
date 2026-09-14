/**
 * 默认发货锚点回退计划链路组
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

moduleDescribe(test, MODULE, '默认发货锚点回退计划链路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SHIPPING-DEFAULT-ORIGIN-FALLBACK-SUITE-001' },
    '完整功能通路 e2e 组：回退契约 + 模块版本',
    async () => {
      const lane = fs.readFileSync(
        path.join(ROOT, 'Service/ServiceLaneMatchService.php'),
        'utf8',
      );
      const mod = fs.readFileSync(path.join(ROOT, 'etc/module.php'), 'utf8');
      expect(lane).toContain('resolveDefaultOriginId');
      expect(mod).toContain('2.4.92');
    },
  );
});
