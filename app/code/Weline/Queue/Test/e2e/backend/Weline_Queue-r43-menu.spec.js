/** @weline-e2e-spec { module: Weline_Queue, type: flow, layer: backend } */
const { test, loginAsAdmin, moduleDescribe, moduleCase, installBackendBrowserGuards, openBackendMenuBySource } = require('../../../../../../../tests/e2e/framework');
const MODULE = 'Weline_Queue';
const PARENT = 'Weline_Queue::message_service';
const ITEMS = [
  ['Weline_Queue::listing_manager', '队列列表', 'queue-list-management', 'CK-R43-QUEUE-001'],
  ['Weline_Queue::type_manager', '队列类型', 'queue-type-management', 'CK-R43-QUEUE-002'],
  ['Weline_Queue::consumer_diagnostics', '消费者', 'queue-consumers-management', 'CK-R43-QUEUE-003'],
  ['Weline_Queue::retry_diagnostics', '重试诊断', 'queue-retries-management', 'CK-R43-QUEUE-004'],
  ['Weline_Queue::inbox_diagnostics', 'Inbox', 'queue-inbox-management', 'CK-R43-QUEUE-005'],
  ['Weline_Queue::outbox_diagnostics', 'Outbox', 'queue-outbox-management', 'CK-R43-QUEUE-006'],
];
moduleDescribe(test, MODULE, 'R4.3 队列后台菜单', () => {
  for (const [source, title, anchor, caseId] of ITEMS) moduleCase(test, { module: MODULE, id: caseId }, `从后台菜单进入${title}`, async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page);
    await openBackendMenuBySource(page, source, { parentSources: [PARENT], title, pageAnchor: `[data-testid="${anchor}"]` });
    guards.assertClean();
  });
});
