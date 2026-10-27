<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

// 本测试直接触 ObjectManager / Changed 管线（依赖 BP 等引导常量）；Pest 裸跑无 TestCore
// 预引导时按模块测试约定显式引入 app 引导（同 Theme test/Unit/Ui/DesignSystemContractTest.php）。
require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Event\Async\DeliveryCoalescer;
use PHPUnit\Framework\Attributes\DataProvider;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Async\Admin\DeliveryAccessPolicy;
use Weline\Framework\Event\Async\Admin\DeliveryReplayService;
use Weline\Framework\Event\Async\AsyncErrorRedactor;
use Weline\Framework\Event\Async\CanonicalJson;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\Async\DeliveryRetryPolicy;
use Weline\Framework\Event\Async\DeliveryStateMachine;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Event\Changed\ChangedCapabilityInterface;
use Weline\Framework\Event\Changed\ChangedTypeInterface;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Event\Changed\UrlMatrixExpander;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceChangePayloadMapper;
use Weline\Framework\Extends\Module\Weline_Framework\Query\AsyncEventDeliveryQueryProvider;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Model\Event\Delivery;
use Weline\Framework\Test\ProjectRoot;
use Weline\Framework\Test\TestCore;
use Weline\Product\Extends\Module\Weline_Framework\Changed\Type\ProductSearchProjectionChangedType;
use Weline\Theme\Extends\Module\Weline_Framework\Changed\Type\ThemeChangedType;

/**
 * Aggregated contract suite (merged from 12 *ContractTest files).
 * Observe one overall pass/fail via testAllEventContractsPass().
 */
final class EventContractsTest extends TestCase
{
    public function testAllEventContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AsyncEvent::testEvt02CanonicalJsonIsStableAndRejectsUnsafePayloads' => function () {
            $this->assert_AsyncEvent_testEvt02CanonicalJsonIsStableAndRejectsUnsafePayloads();
        },
            'AsyncEvent::testEvt02ContextAllowsZeroWebsiteButRejectsPrivilegeRestorationFields' => function () {
            $this->assert_AsyncEvent_testEvt02ContextAllowsZeroWebsiteButRejectsPrivilegeRestorationFields();
        },
            'AsyncEvent::testContextCaptureCanonicalizesRestTransportAreas' => function () {
            $this->assert_AsyncEvent_testContextCaptureCanonicalizesRestTransportAreas();
        },
            'AsyncEvent::testRc01ResourceChangeRoundTripsDefaultWebsiteAndDeleteTombstone' => function () {
            $this->assert_AsyncEvent_testRc01ResourceChangeRoundTripsDefaultWebsiteAndDeleteTombstone();
        },
            'AsyncEvent::testRc01RejectsIdentityTombstoneAndContextDrift' => function () {
            $this->assert_AsyncEvent_testRc01RejectsIdentityTombstoneAndContextDrift();
        },
            'AsyncEventDeliveryQueryProvider::testUi01DescriptorPublishesThreeBackendAclOperations' => function () {
            $this->assert_AsyncEventDeliveryQueryProvider_testUi01DescriptorPublishesThreeBackendAclOperations();
        },
            'AsyncEventDeliveryQueryProvider::testUi01WebsiteZeroAndUi02ReplayReasonBoundsArePublished' => function () {
            $this->assert_AsyncEventDeliveryQueryProvider_testUi01WebsiteZeroAndUi02ReplayReasonBoundsArePublished();
        },
            'AsyncEventDeliveryQueryProvider::testUi02UnknownOperationFailsClosedBeforeDataAccess' => function () {
            $this->assert_AsyncEventDeliveryQueryProvider_testUi02UnknownOperationFailsClosedBeforeDataAccess();
        },
            'DeliveryCoalescer::testOnlyMatchingUpsertsAreMergeCompatible' => function () {
            $this->assert_DeliveryCoalescer_testOnlyMatchingUpsertsAreMergeCompatible();
        },
            'DeliveryCoalescer::testUnionIsStableDeduplicatedAndDropsEmptyValues' => function () {
            $this->assert_DeliveryCoalescer_testUnionIsStableDeduplicatedAndDropsEmptyValues();
        },
            'DeliveryPolicy::testQueue03RetryAttemptsAndDeterministicJitterStayWithinPolicy' => function () {
            $this->assert_DeliveryPolicy_testQueue03RetryAttemptsAndDeterministicJitterStayWithinPolicy();
        },
            'DeliveryPolicy::testQueue03TransportProvisionBackoffIsBounded' => function () {
            $this->assert_DeliveryPolicy_testQueue03TransportProvisionBackoffIsBounded();
        },
            'DeliveryPolicy::testUi02ErrorRedactorRemovesCredentialShapesBeforePersistence' => function () {
            $this->assert_DeliveryPolicy_testUi02ErrorRedactorRemovesCredentialShapesBeforePersistence();
        },
            'DeliveryPolicy::testUi03TerminalStateContractKeepsDeadAndSupersededImmutable' => function () {
            $this->assert_DeliveryPolicy_testUi03TerminalStateContractKeepsDeadAndSupersededImmutable();
        },
            'ChangedPipeline::testEffectPhasesFrozen' => function () {
            $this->assert_ChangedPipeline_testEffectPhasesFrozen();
        },
            'ChangedPipeline::testInvalidPhaseRejected' => function () {
            $this->assert_ChangedPipeline_testInvalidPhaseRejected();
        },
            'ChangedPipeline::testProductRecipeRequiresUrlsMaterialOnUpsert' => function () {
            $this->assert_ChangedPipeline_testProductRecipeRequiresUrlsMaterialOnUpsert();
        },
            'ChangedPipeline::testThemePublishUsesPurgeAllWithWhitelistModule' => function () {
            $this->assert_ChangedPipeline_testThemePublishUsesPurgeAllWithWhitelistModule();
        },
            'ChangedPipeline::testThemePreviewForbidsPurgeAll' => function () {
            $this->assert_ChangedPipeline_testThemePreviewForbidsPurgeAll();
        },
            'ChangedPipeline::testHardCutCacheObserversRemoved' => function () {
            $this->assert_ChangedPipeline_testHardCutCacheObserversRemoved();
        },
            'UrlMatrixExpanderPathLocale::testExpandEmitsDefaultAndEnUsPathNotQueryWl' => function () {
            $this->setUp_UrlMatrixExpanderPathLocale();
            $this->assert_UrlMatrixExpanderPathLocale_testExpandEmitsDefaultAndEnUsPathNotQueryWl();
        },
            'UrlMatrixExpanderPathLocale::testMatrixPurgeClearsBakedZhAndEnColdDisk' => function () {
            $this->setUp_UrlMatrixExpanderPathLocale();
            $this->assert_UrlMatrixExpanderPathLocale_testMatrixPurgeClearsBakedZhAndEnColdDisk();
        },
            'AsyncEventConfiguration::testResourceChangedDeclaresVersionedAsyncContractMetadata' => function () {
            $this->assert_AsyncEventConfiguration_testResourceChangedDeclaresVersionedAsyncContractMetadata();
        },
            'EventLifecycleCloseout::testVisitorLoginPixelListensToCustomerLoginAfter' => function () {
            $this->assert_EventLifecycleCloseout_testVisitorLoginPixelListensToCustomerLoginAfter();
        },
            'EventLifecycleCloseout::testCheckoutHasNoDanglingPaymentStatusSyncObserver' => function () {
            $this->assert_EventLifecycleCloseout_testCheckoutHasNoDanglingPaymentStatusSyncObserver();
        },
            'EventLifecycleCloseout::testThemeHasNoDanglingLayoutCriticalCssObservers' => function () {
            $this->assert_EventLifecycleCloseout_testThemeHasNoDanglingLayoutCriticalCssObservers();
        },
            'EventLifecycleCloseout::testServerDispatchesStartAfterOnReady' => function () {
            $this->assert_EventLifecycleCloseout_testServerDispatchesStartAfterOnReady();
        },
            'EventLifecycleCloseout::testRegisteredObserverClassesExistInTouchedModules' => function () {
            $this->assert_EventLifecycleCloseout_testRegisteredObserverClassesExistInTouchedModules();
        },
            'ResourceChangeCacheOps::testCacheOpsOptionalAcceptsValidOps' => function () {
            $this->assert_ResourceChangeCacheOps_testCacheOpsOptionalAcceptsValidOps();
        },
            'ResourceChangeCacheOps::testLegacyImpactWithoutCacheOpsStillValid' => function () {
            $this->assert_ResourceChangeCacheOps_testLegacyImpactWithoutCacheOpsStillValid();
        },
            'ResourceChangeCacheOps::testRejectsEmptyKeysAndBadPoolAndKeyCap' => function () {
            $this->assert_ResourceChangeCacheOps_testRejectsEmptyKeysAndBadPoolAndKeyCap();
        },
            'ResourceChangeImpactWebsiteIds::testFactoryPreservesWebsiteIdsAndUrlMatrixReady' => function () {
            $this->assert_ResourceChangeImpactWebsiteIds_testFactoryPreservesWebsiteIdsAndUrlMatrixReady();
        },
            'ResourceRevisionLockForUpdate::testLockForUpdateExistsWithoutBumpingRevisionInSignatureDocs' => function () {
            $this->assert_ResourceRevisionLockForUpdate_testLockForUpdateExistsWithoutBumpingRevisionInSignatureDocs();
        },
            'UpgradeAfterObserverProgress::testEventDispatchPrintsUpgradeAfterObserverProgress' => function () {
            $this->assert_UpgradeAfterObserverProgress_testEventDispatchPrintsUpgradeAfterObserverProgress();
        },
            'UpgradeAfterObserverProgress::testSubsequentSilentObserversNowPrintProgress' => function () {
            $this->assert_UpgradeAfterObserverProgress_testSubsequentSilentObserversNowPrintProgress();
        },
        ];
    }

private function assert_AsyncEvent_testEvt02CanonicalJsonIsStableAndRejectsUnsafePayloads(): void
{
    $canonical = new CanonicalJson();
    self::assertSame('{"a":{"x":1,"y":2},"b":2,"list":[3,2,1]}', $canonical->encode(['b' => 2, 'list' => [3, 2, 1], 'a' => ['y' => 2, 'x' => 1]]));
    self::assertSame($canonical->hash(['b' => 2, 'a' => 1]), $canonical->hash(['a' => 1, 'b' => 2]));
    $invalidPayloads = ['object' => ['value' => new \stdClass()], 'resource' => ['value' => fopen('php://memory', 'r')], 'non_finite' => ['value' => INF], 'sensitive_key' => ['api_token' => 'must-not-persist'], 'oversized' => ['value' => str_repeat('x', CanonicalJson::MAX_BYTES)], 'too_deep' => $this->nestedPayload_AsyncEvent(CanonicalJson::MAX_DEPTH + 1)];
    foreach ($invalidPayloads as $label => $payload) {
        try {
            $canonical->encode($payload);
            self::fail($label . ' payload must be rejected before Outbox persistence.');
        } catch (AsyncEventValidationException) {
            self::assertTrue(true, $label);
        } finally {
            if ($label === 'resource' && is_resource($payload['value'])) {
                fclose($payload['value']);
            }
        }
    }
}

private function assert_AsyncEvent_testEvt02ContextAllowsZeroWebsiteButRejectsPrivilegeRestorationFields(): void
{
    $snapshot = new ContextSnapshot();
    $context = $this->context_AsyncEvent();
    $snapshot->validate($context);
    self::assertSame(0, $context['website_id']);
    self::assertSame('system', $context['user']['type']);
    foreach (['negative_website' => array_replace($context, ['website_id' => -1]), 'unknown_root_field' => array_replace($context, ['csrf' => 'forbidden']), 'privilege_field' => array_replace($context, ['user' => ['type' => 'admin', 'id' => 1, 'permissions' => ['*']]])] as $label => $invalid) {
        try {
            $snapshot->validate($invalid);
            self::fail($label . ' context must be rejected.');
        } catch (AsyncEventValidationException) {
            self::assertTrue(true, $label);
        }
    }
}

private function assert_AsyncEvent_testContextCaptureCanonicalizesRestTransportAreas(): void
{
    $previousArea = WelineEnv::getArea();
    try {
        WelineEnv::setArea('rest_backend');
        WelineEnv::set('website_id', 0, 'test');
        WelineEnv::set('website_code', 'default', 'test');
        self::assertSame('backend', (new ContextSnapshot())->capture(0, 'default')['area']);
        WelineEnv::setArea('rest_frontend');
        self::assertSame('frontend', (new ContextSnapshot())->capture(0, 'default')['area']);
    } finally {
        WelineEnv::setArea($previousArea);
    }
}

private function assert_AsyncEvent_testRc01ResourceChangeRoundTripsDefaultWebsiteAndDeleteTombstone(): void
{
    $payload = $this->resourcePayload_AsyncEvent();
    $change = ResourceChange::fromArray($payload);
    $mapper = new ResourceChangePayloadMapper(new CanonicalJson(), new ContextSnapshot());
    self::assertSame(ResourceChange::EVENT_NAME, $mapper->eventName());
    self::assertSame(1, $mapper->schemaVersion());
    self::assertSame('website', $change->resourceType());
    self::assertSame('0', $change->resourceId());
    self::assertSame(0, $change->websiteId());
    self::assertSame('website:0', $change->coalesceKey());
    self::assertNull($change->toArray()['after']);
    self::assertSame($payload, $mapper->toPayload($change));
    self::assertSame($payload, $mapper->fromPayload($payload)->toArray());
}

private function assert_AsyncEvent_testRc01RejectsIdentityTombstoneAndContextDrift(): void
{
    $valid = $this->resourcePayload_AsyncEvent();
    $invalidPayloads = [];
    $negativeWebsite = $valid;
    $negativeWebsite['website']['id'] = -1;
    $invalidPayloads['negative_website'] = $negativeWebsite;
    $nonNullDelete = $valid;
    $nonNullDelete['after'] = ['code' => 'default'];
    $invalidPayloads['delete_after'] = $nonNullDelete;
    $contextDrift = $valid;
    $contextDrift['context']['website_id'] = 7;
    $invalidPayloads['context_drift'] = $contextDrift;
    $unknownField = $valid;
    $unknownField['resource']['class'] = 'Injected\Handler';
    $invalidPayloads['unknown_resource_field'] = $unknownField;
    foreach ($invalidPayloads as $label => $payload) {
        try {
            ResourceChange::fromArray($payload);
            self::fail($label . ' ResourceChange must be rejected.');
        } catch (AsyncEventValidationException) {
            self::assertTrue(true, $label);
        }
    }
}

/** @return array<string, mixed> */
private function context_AsyncEvent(): array
{
    return ['website_id' => 0, 'website_code' => 'default', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'area' => 'cli', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]];
}

/** @return array<string, mixed> */
private function resourcePayload_AsyncEvent(): array
{
    return ['schema_version' => 1, 'event_id' => '0123456789abcdef0123456789abcdef', 'event_name' => ResourceChange::EVENT_NAME, 'occurred_at' => '2026-07-23T03:16:00.123456Z', 'resource' => ['type' => 'website', 'id' => '0', 'action' => 'delete', 'revision' => 3], 'website' => ['id' => 0, 'code' => 'default', 'previous_code' => 'legacy-default', 'site_id' => 0], 'impact' => ['namespaces' => [], 'previous_namespaces' => ['website/default', 'website/legacy-default'], 'urls' => [], 'previous_urls' => ['https://example.test/']], 'changed_fields' => ['code', 'url'], 'before' => ['code' => 'default', 'url' => 'https://example.test/'], 'after' => null, 'origin' => ['area' => 'backend', 'entry' => 'website.deleteDelete', 'request_id' => 'request-1', 'instance' => 'unit-test', 'trigger_by' => ['type' => 'admin', 'id' => 1]], 'context' => $this->context_AsyncEvent()];
}

/** @return array<string, mixed> */
private function nestedPayload_AsyncEvent(int $depth): array
{
    $payload = ['value' => 'leaf'];
    for ($i = 0; $i < $depth; $i++) {
        $payload = ['level_' . $i => $payload];
    }
    return $payload;
}

private function assert_AsyncEventDeliveryQueryProvider_testUi01DescriptorPublishesThreeBackendAclOperations(): void
{
    $descriptor = $this->provider_AsyncEventDeliveryQueryProvider()->getDescriptor();
    $operations = [];
    foreach ($descriptor['operations'] as $operation) {
        $operations[$operation['name']] = $operation;
    }
    self::assertSame('async_event_delivery', $descriptor['provider']);
    self::assertSame(['asyncEventDeliveryList', 'asyncEventDeliveryDetail', 'asyncEventDeliveryReplay'], array_keys($operations));
    foreach (['asyncEventDeliveryList', 'asyncEventDeliveryDetail'] as $name) {
        self::assertSame('backend', $operations[$name]['auth']);
        self::assertSame('read', $operations[$name]['mode']);
        self::assertSame(['kind' => 'source', 'source_id' => DeliveryAccessPolicy::ACL_VIEW], $operations[$name]['backend_acl']);
    }
    self::assertSame('write', $operations['asyncEventDeliveryReplay']['mode']);
    self::assertSame(['kind' => 'source', 'source_id' => DeliveryAccessPolicy::ACL_REPLAY], $operations['asyncEventDeliveryReplay']['backend_acl']);
    self::assertNotSame($operations['asyncEventDeliveryList']['backend_acl']['source_id'], $operations['asyncEventDeliveryReplay']['backend_acl']['source_id']);
}

private function assert_AsyncEventDeliveryQueryProvider_testUi01WebsiteZeroAndUi02ReplayReasonBoundsArePublished(): void
{
    $operations = [];
    foreach ($this->provider_AsyncEventDeliveryQueryProvider()->getDescriptor()['operations'] as $operation) {
        $operations[$operation['name']] = $operation;
    }
    foreach ($operations as $operation) {
        $params = [];
        foreach ($operation['params'] as $param) {
            $params[$param['name']] = $param;
        }
        if (isset($params['website_id'])) {
            self::assertSame(0, $params['website_id']['min']);
        }
    }
    $replayParams = [];
    foreach ($operations['asyncEventDeliveryReplay']['params'] as $param) {
        $replayParams[$param['name']] = $param;
    }
    self::assertTrue($replayParams['reason']['required']);
    self::assertSame(500, $replayParams['reason']['max_length']);
    self::assertSame(1, $replayParams['delivery_id']['min']);
}

private function assert_AsyncEventDeliveryQueryProvider_testUi02UnknownOperationFailsClosedBeforeDataAccess(): void
{
    // 同 testInvalidPhaseRejected：闭包外层 try/catch 使 expectException 失效，改直接断言。
    try {
        $this->provider_AsyncEventDeliveryQueryProvider()->execute('unsafeUnknownOperation', []);
        self::fail('未知 operation 必须在触达数据前 fail-closed');
    } catch (\InvalidArgumentException $e) {
        self::assertStringContainsString('不支持操作', $e->getMessage());
    }
}

private function provider_AsyncEventDeliveryQueryProvider(): AsyncEventDeliveryQueryProvider
{
    $replay = (new \ReflectionClass(DeliveryReplayService::class))->newInstanceWithoutConstructor();
    self::assertInstanceOf(DeliveryReplayService::class, $replay);
    return new AsyncEventDeliveryQueryProvider($this->createStub(Delivery::class), new DeliveryAccessPolicy(new AsyncErrorRedactor()), $replay);
}

private function assert_DeliveryCoalescer_testOnlyMatchingUpsertsAreMergeCompatible(): void
{
    $base = $this->payload_DeliveryCoalescer('upsert', 7);
    self::assertTrue($this->invoke_DeliveryCoalescer('compatible', $base, $this->payload_DeliveryCoalescer('upsert', 7)));
    self::assertFalse($this->invoke_DeliveryCoalescer('compatible', $base, $this->payload_DeliveryCoalescer('delete', 7)));
    self::assertFalse($this->invoke_DeliveryCoalescer('compatible', $base, $this->payload_DeliveryCoalescer('upsert', 8)));
    $otherSchema = $this->payload_DeliveryCoalescer('upsert', 7);
    $otherSchema['schema_version'] = 2;
    self::assertFalse($this->invoke_DeliveryCoalescer('compatible', $base, $otherSchema));
}

private function assert_DeliveryCoalescer_testUnionIsStableDeduplicatedAndDropsEmptyValues(): void
{
    self::assertSame(['cache/site/0', 'cache/site/1', 'cache/site/2'], $this->invoke_DeliveryCoalescer('union', ['cache/site/0', '', 'cache/site/1'], ['cache/site/1', 'cache/site/2']));
}

private function payload_DeliveryCoalescer(string $action, int $id): array
{
    return ['schema_version' => 1, 'resource' => ['type' => 'website', 'id' => (string) $id, 'action' => $action]];
}

private function invoke_DeliveryCoalescer(string $methodName, mixed ...$arguments): mixed
{
    $coalescer = (new \ReflectionClass(DeliveryCoalescer::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(DeliveryCoalescer::class, $methodName);
    return $method->invoke($coalescer, ...$arguments);
}

private function assert_DeliveryPolicy_testQueue03RetryAttemptsAndDeterministicJitterStayWithinPolicy(): void
{
    $policy = new DeliveryRetryPolicy($this->createStub(Delivery::class));
    self::assertSame(1, $policy->maxAttempts('none'));
    self::assertSame(6, $policy->maxAttempts('standard'));
    self::assertFalse($policy->shouldRetry('none', 1));
    self::assertTrue($policy->shouldRetry('standard', 5));
    self::assertFalse($policy->shouldRetry('standard', 6));
    $attemptBases = [1 => 30, 2 => 120, 3 => 600, 4 => 1800, 5 => 7200, 6 => 7200];
    foreach ($attemptBases as $attempt => $base) {
        $delay = $policy->retryDelaySeconds(2005, $attempt);
        self::assertSame($delay, $policy->retryDelaySeconds(2005, $attempt));
        self::assertGreaterThanOrEqual((int) round($base * 0.8), $delay, 'attempt ' . $attempt);
        self::assertLessThanOrEqual((int) round($base * 1.2), $delay, 'attempt ' . $attempt);
    }
    $now = 1784776000;
    self::assertSame(gmdate('Y-m-d H:i:s', $now + $policy->retryDelaySeconds(2005, 3)), $policy->nextRetryAt(2005, 3, $now));
}

private function assert_DeliveryPolicy_testQueue03TransportProvisionBackoffIsBounded(): void
{
    $policy = new DeliveryRetryPolicy($this->createStub(Delivery::class));
    self::assertSame(5, $policy->transportDelaySeconds(0));
    self::assertSame(5, $policy->transportDelaySeconds(1));
    self::assertSame(30, $policy->transportDelaySeconds(2));
    self::assertSame(120, $policy->transportDelaySeconds(3));
    self::assertSame(600, $policy->transportDelaySeconds(4));
    self::assertSame(600, $policy->transportDelaySeconds(99));
}

private function assert_DeliveryPolicy_testUi02ErrorRedactorRemovesCredentialShapesBeforePersistence(): void
{
    $redactor = new AsyncErrorRedactor();
    $input = implode("\n", ['Authorization: Bearer abc.def.ghi', 'Cookie: session=secret-cookie; csrf=secret-csrf', 'https://deploy-user:deploy-password@example.test/path', '{"api_token":"secret-json","safe":"visible"}', 'password=secret-query&message=visible', "-----BEGIN PRIVATE KEY-----\nsecret-pem\n-----END PRIVATE KEY-----"]);
    $redacted = $redactor->redact($input);
    foreach (['abc.def.ghi', 'secret-cookie', 'secret-csrf', 'deploy-password', 'secret-json', 'secret-query', 'secret-pem'] as $secret) {
        self::assertStringNotContainsString($secret, $redacted);
    }
    self::assertStringContainsString('Authorization: [redacted]', $redacted);
    self::assertStringContainsString('"api_token":"[redacted]"', $redacted);
    self::assertStringContainsString('message=visible', $redacted);
    self::assertStringContainsString('[redacted-pem]', $redacted);
    self::assertSame('abcd', $redactor->redact('abcdefgh', 4));
}

private function assert_DeliveryPolicy_testUi03TerminalStateContractKeepsDeadAndSupersededImmutable(): void
{
    self::assertSame(['succeeded', 'dead', 'superseded', 'skipped'], DeliveryStateMachine::TERMINAL);
    self::assertContains('dead', DeliveryStateMachine::TERMINAL);
    self::assertContains('superseded', DeliveryStateMachine::TERMINAL);
    self::assertSame('transport_termination_unconfirmed', DeliveryStateMachine::TERMINAL_REASON_TRANSPORT_TERMINATION_UNCONFIRMED);
}

private function assert_ChangedPipeline_testEffectPhasesFrozen(): void
{
    $sync = new InvalidationEffect(InvalidationEffect::CODE_BUMP_NAMESPACES, InvalidationEffect::PHASE_SYNC);
    $after = new InvalidationEffect(InvalidationEffect::CODE_PURGE_FPC_URLS, InvalidationEffect::PHASE_AFTER_COMMIT);
    self::assertSame('sync', $sync->phase);
    self::assertSame('after_commit', $after->phase);
}

private function assert_ChangedPipeline_testInvalidPhaseRejected(): void
{
    // 合同套件在闭包里逐项 try/catch，expectException 语义失效（异常被外层捕获计为失败）。
    // 改为直接断言异常类型。
    try {
        new InvalidationEffect('x', 'later');
        self::fail('InvalidationEffect 必须拒绝非法 phase');
    } catch (\InvalidArgumentException $e) {
        self::assertStringContainsString('sync 或 after_commit', $e->getMessage());
    }
}

private function assert_ChangedPipeline_testProductRecipeRequiresUrlsMaterialOnUpsert(): void
{
    $type = new ProductSearchProjectionChangedType();
    self::assertSame('product_search_projection', $type->code());
    $change = $this->change_ChangedPipeline('product_search_projection', 'upsert', ['namespaces' => ['website/default/catalog'], 'urls' => ['https://example.test/p/1'], 'previous_urls' => []]);
    $effects = $type->recipe($change);
    $codes = array_map(static fn(InvalidationEffect $e) => $e->code, $effects);
    self::assertContains(InvalidationEffect::CODE_BUMP_NAMESPACES, $codes);
    self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $codes);
    self::assertContains(InvalidationEffect::CODE_CDN_PURGE, $codes);
}

private function assert_ChangedPipeline_testThemePublishUsesPurgeAllWithWhitelistModule(): void
{
    $type = new ThemeChangedType();
    // 2026-10-13 起（0663decb44「系统发布零碰 FPC」）：带 website 上下文的主题发布
    // 走选择性 purge（purge_fpc_urls / cdn_purge），不再 purge_fpc_all；
    // 仅全局（无 website、无 urls）发布才允许 purge_fpc_all（白名单模块）。
    $websiteChange = $this->change_ChangedPipeline('theme', 'publish', ['namespaces' => ['website/default/theme'], 'urls' => [], 'previous_urls' => []]);
    $websiteCodes = array_map(static fn(InvalidationEffect $e) => $e->code, $type->recipe($websiteChange));
    self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $websiteCodes);
    self::assertContains(InvalidationEffect::CODE_PURGE_FPC_URLS, $websiteCodes);
    self::assertContains(InvalidationEffect::CODE_CDN_PURGE, $websiteCodes);

    $globalChange = ResourceChange::fromArray(array_replace_recursive($this->basePayload_ChangedPipeline('theme', 'publish'), [
        'website' => null,
        'context' => ['website_id' => null, 'website_code' => null, 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'area' => 'backend', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]],
        'impact' => ['namespaces' => ['global/storefront/theme'], 'urls' => [], 'previous_urls' => [], 'previous_namespaces' => []],
    ]));
    $purgeAll = null;
    foreach ($type->recipe($globalChange) as $effect) {
        if ($effect->code === InvalidationEffect::CODE_PURGE_FPC_ALL) {
            $purgeAll = $effect;
        }
    }
    self::assertInstanceOf(InvalidationEffect::class, $purgeAll);
    self::assertSame('Weline_Theme', $purgeAll->payload['source_module'] ?? null);
}

private function assert_ChangedPipeline_testThemePreviewForbidsPurgeAll(): void
{
    $type = new ThemeChangedType();
    $change = ResourceChange::fromArray(array_replace_recursive($this->basePayload_ChangedPipeline('theme', 'publish'), ['after' => ['preview' => true], 'impact' => ['namespaces' => ['website/default/theme'], 'urls' => [], 'previous_urls' => [], 'previous_namespaces' => []]]));
    $codes = array_map(static fn(InvalidationEffect $e) => $e->code, $type->recipe($change));
    self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
}

private function assert_ChangedPipeline_testHardCutCacheObserversRemoved(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline';
    self::assertFileDoesNotExist($root . '/Event/ResourceChange/Observer/CacheNamespaceObserver.php');
    self::assertFileDoesNotExist($root . '/Event/ResourceChange/Observer/CacheImpactObserver.php');
    self::assertStringContainsString('changed/capability', ChangedCapabilityInterface::EXTENDS_RELATIVE_PREFIX);
    self::assertStringContainsString('changed/type', ChangedTypeInterface::EXTENDS_RELATIVE_PREFIX);
}

/** @param array<string,mixed> $impact */
private function change_ChangedPipeline(string $type, string $action, array $impact): ResourceChange
{
    return ResourceChange::fromArray(array_replace_recursive($this->basePayload_ChangedPipeline($type, $action), ['impact' => $impact + ['previous_namespaces' => []]]));
}

/** @return array<string,mixed> */
private function basePayload_ChangedPipeline(string $type, string $action): array
{
    return ['schema_version' => 1, 'event_id' => str_repeat('ab', 16), 'event_name' => ResourceChange::EVENT_NAME, 'occurred_at' => '2026-09-21T02:00:00.000000Z', 'resource' => ['type' => $type, 'id' => '1', 'action' => $action, 'revision' => 1], 'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0], 'impact' => ['namespaces' => [], 'previous_namespaces' => [], 'urls' => [], 'previous_urls' => []], 'changed_fields' => ['x'], 'before' => [], 'after' => ['status' => 'published'], 'origin' => ['area' => 'backend', 'entry' => 'test', 'request_id' => '', 'instance' => '', 'trigger_by' => ['type' => 'system', 'id' => null]], 'context' => ['website_id' => 0, 'website_code' => 'default', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'area' => 'backend', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]]];
}

private function setUp_UrlMatrixExpanderPathLocale(): void
{
    ProjectRoot::defineBp(__DIR__);
    // UrlMatrixExpander 走 websites Query 读站点语言/币种（sandbox sqlite）。
    // 沙箱库缺 w_weline_websites_website_language / _website_currency 表时
    // WebsiteData 快照为空 → 只有默认 locale，en_US 变体永远不会展开（2026-10-26 定位）。
    // 与 Theme test/ThemeTestSchema::ensure 同规则：由模型 schema 推导 DDL，失败不阻塞。
    foreach ([
        \Weline\Websites\Model\Website::class,
        \Weline\Websites\Model\WebsiteLanguage::class,
        \Weline\Websites\Model\WebsiteCurrency::class,
    ] as $modelClass) {
        if (!class_exists($modelClass)) {
            continue;
        }
        try {
            $parser = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Database\Schema\SchemaParser::class
            );
            $executor = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Database\Service\Release\SchemaDiffExecutor::class
            );
            $schema = $parser->parse($modelClass);
            if ($schema !== null) {
                $executor->createBootstrapTable(
                    \Weline\Framework\Manager\ObjectManager::getInstance(
                        \Weline\Framework\Database\Connection\Adapter\Sqlite\Connector::class
                    ),
                    $schema,
                );
            }
        } catch (\Throwable) {
            // 夹具失败不阻塞用例
        }
    }
    // 种子语言/币种关联（沙箱库为空 → 只有默认 locale，en_US 路径变体不会展开）。
    try {
        $lang = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\WebsiteLanguage::class);
        $existing = (clone $lang)->reset()->where(\Weline\Websites\Model\WebsiteLanguage::schema_fields_WEBSITE_ID, 0)
            ->select()->fetch()->getItems();
        if ($existing === []) {
            $lang->addData([
                \Weline\Websites\Model\WebsiteLanguage::schema_fields_WEBSITE_ID => 0,
                \Weline\Websites\Model\WebsiteLanguage::schema_fields_LANGUAGE_CODE => 'zh_Hans_CN',
                \Weline\Websites\Model\WebsiteLanguage::schema_fields_SORT_ORDER => 1,
            ])->save();
            $lang->clearData()->addData([
                \Weline\Websites\Model\WebsiteLanguage::schema_fields_WEBSITE_ID => 0,
                \Weline\Websites\Model\WebsiteLanguage::schema_fields_LANGUAGE_CODE => 'en_US',
                \Weline\Websites\Model\WebsiteLanguage::schema_fields_SORT_ORDER => 2,
            ])->save();
        }
        $cur = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\WebsiteCurrency::class);
        $existingCur = (clone $cur)->reset()->where(\Weline\Websites\Model\WebsiteCurrency::schema_fields_WEBSITE_ID, 0)
            ->select()->fetch()->getItems();
        if ($existingCur === []) {
            $cur->clearData()->addData([
                \Weline\Websites\Model\WebsiteCurrency::schema_fields_WEBSITE_ID => 0,
                \Weline\Websites\Model\WebsiteCurrency::schema_fields_CURRENCY_CODE => 'USD',
                \Weline\Websites\Model\WebsiteCurrency::schema_fields_SORT_ORDER => 1,
            ])->save();
        }
    } catch (\Throwable) {
        // 种子失败不阻塞用例（断言会按真实数据行数收敛）
    }
}

private function assert_UrlMatrixExpanderPathLocale_testExpandEmitsDefaultAndEnUsPathNotQueryWl(): void
{
    $expander = new UrlMatrixExpander();
    $origin = 'https://shop.example';
    $slug = 'hanfu-robe';
    $result = $expander->expand([$origin . '/product/' . $slug], 0);
    $urls = $result['urls'];
    self::assertNotEmpty($urls);
    $normalized = [];
    foreach ($urls as $url) {
        $key = FpcPayloadPathIndex::normalizeIndexKey((string) $url);
        if ($key !== '') {
            $normalized[$key] = $key;
        }
        self::assertStringNotContainsString('_wl=', (string) $url);
        self::assertStringNotContainsString('_wc=', (string) $url);
    }
    $zh = FpcPayloadPathIndex::normalizeIndexKey($origin . '/product/' . $slug);
    $en = FpcPayloadPathIndex::normalizeIndexKey($origin . '/en_US/product/' . $slug);
    self::assertArrayHasKey($zh, $normalized, 'default unprefixed product URL must be purged');
    self::assertArrayHasKey($en, $normalized, 'en_US path variant must be purged for multi-lang cold disk');
}

private function assert_UrlMatrixExpanderPathLocale_testMatrixPurgeClearsBakedZhAndEnColdDisk(): void
{
    $origin = 'https://matrix-purge-' . \uniqid('', true) . '.example';
    $slug = 'probe-' . \substr(\md5((string) \microtime(true)), 0, 8);
    $urlZh = FpcPayloadPathIndex::normalizeIndexKey($origin . '/product/' . $slug);
    $urlEn = FpcPayloadPathIndex::normalizeIndexKey($origin . '/en_US/product/' . $slug);
    $payloadDir = VAR_DIR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads' . \DIRECTORY_SEPARATOR . 'mx';
    if (!\is_dir($payloadDir)) {
        \mkdir($payloadDir, 0775, true);
    }
    $bodyZh = $payloadDir . \DIRECTORY_SEPARATOR . 'zh-' . $slug . '.html';
    $bodyEn = $payloadDir . \DIRECTORY_SEPARATOR . 'en-' . $slug . '.html';
    \file_put_contents($bodyZh, '<html>中文-旧</html>');
    \file_put_contents($bodyEn, '<html>EN-old</html>');
    FpcPayloadPathIndex::remember($urlZh, 'k-zh-' . $slug, $bodyZh);
    FpcPayloadPathIndex::remember($urlEn, 'k-en-' . $slug, $bodyEn);
    self::assertNotEmpty(FpcPayloadPathIndex::lookup($urlZh));
    self::assertNotEmpty(FpcPayloadPathIndex::lookup($urlEn));
    $expander = new UrlMatrixExpander();
    $matrix = $expander->expand([$origin . '/product/' . $slug], 0);
    $purged = FpcPayloadPathIndex::purgePaths($matrix['urls']);
    self::assertGreaterThanOrEqual(2, \count($purged));
    self::assertSame([], FpcPayloadPathIndex::lookup($urlZh));
    self::assertSame([], FpcPayloadPathIndex::lookup($urlEn));
    self::assertFileDoesNotExist($bodyZh);
    self::assertFileDoesNotExist($bodyEn);
}

private function assert_AsyncEventConfiguration_testResourceChangedDeclaresVersionedAsyncContractMetadata(): void
{
    $config = ObjectManager::getInstance(XmlReader::class)->read();
    $eventConfig = null;
    foreach ($config as $events) {
        if (isset($events['Weline_Framework::resource_changed'])) {
            $eventConfig = $events['Weline_Framework::resource_changed'];
            break;
        }
    }
    // 2026-09 cache_namespace/cache_impact Observer 已硬切 Changed Extends Capability
    // （ThemeRuntimeCapability 等），event.xml 不再注册同步 Observer；事件级 async 契约
    // （schema_version / async_mapper / data_contract）由 event.php 声明并随注册表下发。
    self::assertIsArray($eventConfig);
    $contract = $eventConfig[0] ?? $eventConfig;
    self::assertSame(1, (int)($contract['event_schema_version'] ?? ($contract['schema_version'] ?? 0)));
    self::assertSame('Weline\Framework\Event\ResourceChange\ResourceChangePayloadMapper', $contract['event_async_mapper'] ?? null);
    self::assertSame('resource_change.v1', $contract['event_data_contract'] ?? null);
}

private function validPolicies_AsyncEventConfiguration(): iterable
{
    yield 'sync critical' => [['delivery' => 'sync', 'failure' => 'critical', 'retry_explicit' => false, 'coalesce_explicit' => false, 'timeout_explicit' => false]];
    yield 'sync isolated' => [['delivery' => 'sync', 'failure' => 'isolated', 'retry_explicit' => false, 'coalesce_explicit' => false, 'timeout_explicit' => false]];
    yield 'async standard latest' => [['delivery' => 'async', 'failure_explicit' => false, 'retry' => 'standard', 'coalesce' => 'latest', 'timeout' => 3600]];
    yield 'async no retry' => [['delivery' => 'async', 'failure_explicit' => false, 'retry' => 'none', 'coalesce' => 'none', 'timeout' => 1]];
}

private function invalidPolicies_AsyncEventConfiguration(): iterable
{
    yield 'unknown delivery' => [['delivery' => 'deferred', 'failure' => 'critical']];
    yield 'sync retry declaration' => [['delivery' => 'sync', 'failure' => 'critical', 'retry_explicit' => true, 'coalesce_explicit' => false, 'timeout_explicit' => false]];
    yield 'sync invalid failure' => [['delivery' => 'sync', 'failure' => 'ignored', 'retry_explicit' => false, 'coalesce_explicit' => false, 'timeout_explicit' => false]];
    yield 'async failure declaration' => [['delivery' => 'async', 'failure_explicit' => true, 'retry' => 'standard', 'coalesce' => 'none', 'timeout' => 30]];
    yield 'async invalid retry' => [['delivery' => 'async', 'failure_explicit' => false, 'retry' => 'forever', 'coalesce' => 'none', 'timeout' => 30]];
    yield 'async invalid coalesce' => [['delivery' => 'async', 'failure_explicit' => false, 'retry' => 'standard', 'coalesce' => 'append', 'timeout' => 30]];
    yield 'async zero timeout' => [['delivery' => 'async', 'failure_explicit' => false, 'retry' => 'standard', 'coalesce' => 'none', 'timeout' => 0]];
    yield 'async excessive timeout' => [['delivery' => 'async', 'failure_explicit' => false, 'retry' => 'standard', 'coalesce' => 'none', 'timeout' => 3601]];
}

private function invokePolicyValidation_AsyncEventConfiguration(array $policy): void
{
    $reader = (new \ReflectionClass(XmlReader::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(XmlReader::class, 'validateObserverPolicy');
    $method->invoke($reader, 'Weline_Test::contract', $policy);
}

private function assert_EventLifecycleCloseout_testVisitorLoginPixelListensToCustomerLoginAfter(): void
{
    $xml = (string) \file_get_contents(BP . 'app/code/Weline/Visitor/etc/event.xml');
    self::assertStringContainsString('Weline_Customer_Account_Login::login_after', $xml, 'LoginPixel must listen to Customer login_after');
    self::assertStringNotContainsString('Weline_Frontend_Account_Login::login_after', $xml, 'Deprecated Frontend login_after must not remain registered');
}

private function assert_EventLifecycleCloseout_testCheckoutHasNoDanglingPaymentStatusSyncObserver(): void
{
    $xml = (string) \file_get_contents(BP . 'app/code/Weline/Checkout/etc/event.xml');
    self::assertStringNotContainsString('PaymentStatusSyncObserver', $xml, 'Removed dangling PaymentStatusSyncObserver registration');
    self::assertFileDoesNotExist(BP . 'app/code/Weline/Checkout/Observer/PaymentStatusSyncObserver.php');
}

private function assert_EventLifecycleCloseout_testThemeHasNoDanglingLayoutCriticalCssObservers(): void
{
    $xml = (string) \file_get_contents(BP . 'app/code/Weline/Theme/etc/event.xml');
    self::assertStringNotContainsString('LayoutCriticalCss', $xml);
}

private function assert_EventLifecycleCloseout_testServerDispatchesStartAfterOnReady(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Server/Service/ServiceOrchestrator.php');
    self::assertStringContainsString("dispatch('Weline_Server::start_after'", $src);
    self::assertStringContainsString('dispatchServerStartAfterEvent', $src);
    $eventPhp = include BP . 'app/code/Weline/Server/event.php';
    self::assertIsArray($eventPhp);
    self::assertArrayHasKey('Weline_Server::start_after', $eventPhp);
}

private function assert_EventLifecycleCloseout_testRegisteredObserverClassesExistInTouchedModules(): void
{
    $files = [BP . 'app/code/Weline/Visitor/etc/event.xml', BP . 'app/code/Weline/Checkout/etc/event.xml', BP . 'app/code/Weline/Theme/etc/event.xml', BP . 'app/code/Weline/Framework/etc/event.xml'];
    foreach ($files as $file) {
        $xml = (string) \file_get_contents($file);
        if (!\preg_match_all('/instance="([^"]+)"/', $xml, $m)) {
            continue;
        }
        foreach ($m[1] as $fqcn) {
            $path = BP . 'app/code/' . \str_replace('\\', '/', $fqcn) . '.php';
            self::assertFileExists($path, "Observer class missing for {$fqcn} in {$file}");
        }
    }
}

private function assert_ResourceChangeCacheOps_testCacheOpsOptionalAcceptsValidOps(): void
{
    $payload = $this->basePayload_ResourceChangeCacheOps();
    $payload['impact']['cache_ops'] = [['pool' => 'system_config', 'keys' => ['system_config_rows_abc']], ['pool' => 'database', 'keys' => ['system_config_rows_abc']]];
    $change = ResourceChange::fromArray($payload);
    self::assertSame(2, count($change->toArray()['impact']['cache_ops']));
}

private function assert_ResourceChangeCacheOps_testLegacyImpactWithoutCacheOpsStillValid(): void
{
    $change = ResourceChange::fromArray($this->basePayload_ResourceChangeCacheOps());
    self::assertArrayNotHasKey('cache_ops', $change->toArray()['impact']);
}

private function assert_ResourceChangeCacheOps_testRejectsEmptyKeysAndBadPoolAndKeyCap(): void
{
    $emptyKeys = $this->basePayload_ResourceChangeCacheOps();
    $emptyKeys['impact']['cache_ops'] = [['pool' => 'system_config', 'keys' => []]];
    $this->expectValidation_ResourceChangeCacheOps($emptyKeys);
    $badPool = $this->basePayload_ResourceChangeCacheOps();
    $badPool['impact']['cache_ops'] = [['pool' => 'Bad Pool', 'keys' => ['a']]];
    $this->expectValidation_ResourceChangeCacheOps($badPool);
    $tooMany = $this->basePayload_ResourceChangeCacheOps();
    $keys = [];
    for ($i = 0; $i < ResourceChange::CACHE_OPS_MAX_KEYS + 1; $i++) {
        $keys[] = 'k' . $i;
    }
    $tooMany['impact']['cache_ops'] = [['pool' => 'system_config', 'keys' => $keys]];
    $this->expectValidation_ResourceChangeCacheOps($tooMany);
}

/** @param array<string,mixed> $payload */
private function expectValidation_ResourceChangeCacheOps(array $payload): void
{
    try {
        ResourceChange::fromArray($payload);
        self::fail('expected AsyncEventValidationException');
    } catch (AsyncEventValidationException) {
        self::assertTrue(true);
    }
}

/** @return array<string,mixed> */
private function basePayload_ResourceChangeCacheOps(): array
{
    return ['schema_version' => ResourceChange::SCHEMA_VERSION, 'event_id' => '0123456789abcdef0123456789abcdef', 'event_name' => ResourceChange::EVENT_NAME, 'occurred_at' => '2026-07-23T03:16:00.123456Z', 'resource' => ['type' => 'system_config', 'id' => 'demo', 'action' => 'upsert', 'revision' => 1], 'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0], 'impact' => ['namespaces' => ['global/storefront/config'], 'previous_namespaces' => [], 'urls' => [], 'previous_urls' => []], 'changed_fields' => ['captcha/google/api_key'], 'before' => [], 'after' => ['ok' => true], 'origin' => ['area' => 'backend', 'entry' => 'system_config.save', 'request_id' => 'request-1', 'instance' => 'unit-test', 'trigger_by' => ['type' => 'admin', 'id' => 1]], 'context' => ['website_id' => 0, 'website_code' => 'default', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'area' => 'cli', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]]];
}

private function assert_ResourceChangeImpactWebsiteIds_testFactoryPreservesWebsiteIdsAndUrlMatrixReady(): void
{
    $change = (new ResourceChangeFactory(new ContextSnapshot()))->create(resourceType: 'product_search_projection', resourceId: '0:9', action: 'upsert', revision: 9, websiteId: 0, websiteCode: 'default', before: [], after: ['contract' => 'product.search_projection_changed.v1'], changedFields: ['contract'], impact: ['namespaces' => ['website/default/catalog', 'website/daocharms/catalog'], 'urls' => ['https://www.daocharms.com/product/x'], 'previous_urls' => [], 'website_ids' => [158, 0], 'url_matrix_ready' => true], origin: ['entry' => 'unit'], siteId: 0);
    $impact = $change->toArray()['impact'];
    self::assertSame([0, 158], $impact['website_ids']);
    self::assertTrue($impact['url_matrix_ready']);
    self::assertContains('https://www.daocharms.com/product/x', $impact['urls']);
}

private function assert_ResourceRevisionLockForUpdate_testLockForUpdateExistsWithoutBumpingRevisionInSignatureDocs(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/ResourceChange/ResourceRevisionService.php');
    self::assertStringContainsString('function lockForUpdate(', $src);
    self::assertStringContainsString('但不推进版本号', $src);
    self::assertStringContainsString('function next(', $src);
    self::assertStringContainsString('assertIdentity', $src);
    self::assertStringContainsString('assertSoleActiveTransaction', $src);
}

private function assert_UpgradeAfterObserverProgress_testEventDispatchPrintsUpgradeAfterObserverProgress(): void
{
    $eventPhp = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/Event.php');
    self::assertStringContainsString("Weline_Framework_Setup::upgrade_after", $eventPhp);
    self::assertStringContainsString('printUpgradeAfterObserverProgress', $eventPhp);
    self::assertStringContainsString('升级后观察者开始', $eventPhp);
    self::assertStringContainsString('升级后观察者完成', $eventPhp);
}

private function assert_UpgradeAfterObserverProgress_testSubsequentSilentObserversNowPrintProgress(): void
{
    $themeRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme';
    $maintenanceRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Maintenance';
    $searchRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Search';
    $publish404 = (string) file_get_contents($themeRoot . '/Observer/SetupUpgradeAfterPublishNotFoundStatic.php');
    $generator = (string) file_get_contents($themeRoot . '/Service/StorefrontNotFoundStaticGenerator.php');
    $maintenance = (string) file_get_contents($maintenanceRoot . '/Observer/LocaleCatalogChangedObserver.php');
    $search = (string) file_get_contents($searchRoot . '/Observer/SetupProviderIndexWarmupObserver.php');
    self::assertStringContainsString('开始发布前台 404 静态页', $publish404);
    self::assertStringContainsString('website×locale', $generator);
    self::assertStringContainsString('FiberTaskRunner::yield', $generator);
    self::assertStringContainsString('开始发布维护模式静态页', $maintenance);
    self::assertStringContainsString('系统正在更新，搜索索引重建改到闲时再做。', $search);
}
}
