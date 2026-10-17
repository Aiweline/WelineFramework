<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Queue\Extends\Module\Weline_Framework\Query\QueueAdminQueryProvider;
use Weline\Queue\Extends\Module\Weline_Framework\Query\QueueQueryProvider;
use Weline\Queue\Model\Queue;
use Weline\Queue\Service\QueueAdminService;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllQueueServiceContractsPass().
 */
final class QueueServiceContractsTest extends TestCase
{
    public function testAllQueueServiceContractsPass(): void
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
            'IdempotentQueueAdmission::testAdmissionUsesCreateIfAbsentWithFollowupAndReopen' => function () {
            $this->assert_IdempotentQueueAdmission_testAdmissionUsesCreateIfAbsentWithFollowupAndReopen();
        },
            'QueueAdminQueryProvider::testDescriptorPublishesOnlyTheEightAuthenticatedBackendOperations' => function () {
            $this->assert_QueueAdminQueryProvider_testDescriptorPublishesOnlyTheEightAuthenticatedBackendOperations();
        },
            'QueueAdminQueryProvider::testDescriptorUsesLeastPrivilegeSourceAndParamMapAclPolicies' => function () {
            $this->assert_QueueAdminQueryProvider_testDescriptorUsesLeastPrivilegeSourceAndParamMapAclPolicies();
        },
            'QueueAdminQueryProvider::testLegacyQueueProviderRemainsServerOnly' => function () {
            $this->assert_QueueAdminQueryProvider_testLegacyQueueProviderRemainsServerOnly();
        },
            'QueueAdminQueryProvider::testBrowserOperationsDoNotPublishOperationalOwnershipParameters' => function () {
            $this->assert_QueueAdminQueryProvider_testBrowserOperationsDoNotPublishOperationalOwnershipParameters();
        },
            'QueueAdminQueryProvider::testUnknownAdminOperationFailsClosedBeforeServiceAccess' => function () {
            $this->assert_QueueAdminQueryProvider_testUnknownAdminOperationFailsClosedBeforeServiceAccess();
        },
            'QueueAdminQueryProvider::testAdminServiceRejectsOperationalActionsBeforeLoadingQueueState' => function () {
            $this->assert_QueueAdminQueryProvider_testAdminServiceRejectsOperationalActionsBeforeLoadingQueueState();
        },
            'QueueAdminQueryProvider::testTypeAttributeHtmlSanitizerRemovesLegacyScriptsButKeepsFormMarkup' => function () {
            $this->assert_QueueAdminQueryProvider_testTypeAttributeHtmlSanitizerRemovesLegacyScriptsButKeepsFormMarkup();
        },
            'QueueIdempotentCreate::testQueuePublishesNullableUniqueIdempotencyKeyWithoutChangingBizKeyIndex' => function () {
            $this->assert_QueueIdempotentCreate_testQueuePublishesNullableUniqueIdempotencyKeyWithoutChangingBizKeyIndex();
        },
            'QueueIdempotentCreate::testProviderKeepsLegacyCreateAndAddsExplicitAtomicOperation' => function () {
            $this->assert_QueueIdempotentCreate_testProviderKeepsLegacyCreateAndAddsExplicitAtomicOperation();
        },
        ];
    }

private function assert_IdempotentQueueAdmission_testAdmissionUsesCreateIfAbsentWithFollowupAndReopen(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Queue' . '/Service/IdempotentQueueAdmission.php');
    self::assertStringContainsString("createIfAbsent", $source);
    self::assertStringContainsString("':followup'", $source);
    self::assertStringContainsString('requeueQueueSafely', $source);
    self::assertStringContainsString('dispatchQueueIfEligible', $source);
    self::assertStringContainsString('queue_edit_active', $source);
    self::assertStringContainsString('queue_force_required', $source);
    self::assertMatchesRegularExpression('/queue_force_required.*?return;/s', $source, 'Terminal reopen must soft-skip queue_force_required instead of throwing for cron admission');
}

private const ACTION_ACL_MAP_QueueAdminQueryProvider = ['delete' => 'Weline_Queue::delete', 'stop' => 'Weline_Queue::stop', 'continue' => 'Weline_Queue::continue', 'retry' => 'Weline_Queue::continue', 'reset' => 'Weline_Queue::reset'];

private const BATCH_ACTION_ACL_MAP_QueueAdminQueryProvider = ['delete' => 'Weline_Queue::delete', 'stop' => 'Weline_Queue::stop', 'continue' => 'Weline_Queue::continue'];

private function assert_QueueAdminQueryProvider_testDescriptorPublishesOnlyTheEightAuthenticatedBackendOperations(): void
{
    $provider = $this->providerWithoutConstructor_QueueAdminQueryProvider(QueueAdminQueryProvider::class);
    $descriptor = $provider->getDescriptor();
    $operations = $this->operationsByName_QueueAdminQueryProvider($descriptor);
    self::assertSame('queue_admin', $provider->getProviderName());
    self::assertSame('queue_admin', $descriptor['provider']);
    self::assertSame('Weline_Queue', $descriptor['module']);
    self::assertSame(['snapshot', 'searchTypes', 'typeAttributes', 'resolveAttributeDependence', 'save', 'action', 'batchAction', 'setTypeEnabled'], \array_keys($operations));
    $expectedModes = ['snapshot' => 'read', 'searchTypes' => 'read', 'typeAttributes' => 'read', 'resolveAttributeDependence' => 'read', 'save' => 'write', 'action' => 'write', 'batchAction' => 'write', 'setTypeEnabled' => 'write'];
    foreach ($operations as $name => $operation) {
        self::assertTrue($operation['frontend'] ?? false, $name . ' must be available through weline-api');
        self::assertTrue($operation['backend'] ?? false, $name . ' must be restricted to the backend area');
        self::assertTrue($operation['external'] ?? false, $name . ' must be compiled into the bin-query index');
        self::assertSame('backend', $operation['auth'] ?? null, $name . ' must require backend auth');
        self::assertFalse($operation['graph'] ?? true, $name . ' must not expose graph traversal');
        self::assertSame($expectedModes[$name], $operation['mode'] ?? null, $name . ' mode mismatch');
        self::assertSame(['type' => 'array'], $operation['returns'] ?? null, $name . ' return contract mismatch');
    }
}

private function assert_QueueAdminQueryProvider_testDescriptorUsesLeastPrivilegeSourceAndParamMapAclPolicies(): void
{
    $operations = $this->operationsByName_QueueAdminQueryProvider($this->providerWithoutConstructor_QueueAdminQueryProvider(QueueAdminQueryProvider::class)->getDescriptor());
    self::assertSame($this->sourceAcl_QueueAdminQueryProvider('Weline_Queue::index'), $operations['snapshot']['backend_acl']);
    self::assertSame($this->sourceAcl_QueueAdminQueryProvider('Weline_Queue::search_type'), $operations['searchTypes']['backend_acl']);
    self::assertSame($this->sourceAcl_QueueAdminQueryProvider('Weline_Queue::form'), $operations['typeAttributes']['backend_acl']);
    self::assertSame($this->sourceAcl_QueueAdminQueryProvider('Weline_Queue::get_type_attributes'), $operations['resolveAttributeDependence']['backend_acl']);
    self::assertSame($this->sourceAcl_QueueAdminQueryProvider('Weline_Queue::form'), $operations['save']['backend_acl']);
    self::assertSame($this->sourceAcl_QueueAdminQueryProvider('Weline_Queue::type_manage'), $operations['setTypeEnabled']['backend_acl']);
    self::assertSame(['kind' => 'param_map', 'param' => 'action', 'map' => self::ACTION_ACL_MAP_QueueAdminQueryProvider], $operations['action']['backend_acl']);
    self::assertSame(['kind' => 'param_map', 'param' => 'action', 'map' => self::BATCH_ACTION_ACL_MAP_QueueAdminQueryProvider], $operations['batchAction']['backend_acl']);
    foreach (['action', 'batchAction'] as $name) {
        $params = [];
        foreach ($operations[$name]['params'] as $param) {
            $params[$param['name']] = $param;
        }
        self::assertArrayHasKey('action', $params, $name . ' must declare its ACL selector parameter');
        self::assertTrue($params['action']['required'] ?? false);
    }
}

private function assert_QueueAdminQueryProvider_testLegacyQueueProviderRemainsServerOnly(): void
{
    $descriptor = $this->providerWithoutConstructor_QueueAdminQueryProvider(QueueQueryProvider::class)->getDescriptor();
    self::assertSame('queue', $descriptor['provider']);
    foreach ($descriptor['operations'] as $operation) {
        self::assertFalse((bool) ($operation['frontend'] ?? false), (string) ($operation['name'] ?? 'unknown') . ' must not be exposed to browser callers');
    }
}

private function assert_QueueAdminQueryProvider_testBrowserOperationsDoNotPublishOperationalOwnershipParameters(): void
{
    $operations = $this->operationsByName_QueueAdminQueryProvider($this->providerWithoutConstructor_QueueAdminQueryProvider(QueueAdminQueryProvider::class)->getDescriptor());
    self::assertSame(['queue_id', 'type_id', 'name', 'biz_key', 'attributes'], $this->parameterNames_QueueAdminQueryProvider($operations['save']));
    self::assertSame(['queue_id', 'action'], $this->parameterNames_QueueAdminQueryProvider($operations['action']));
    self::assertSame(['queue_ids', 'action'], $this->parameterNames_QueueAdminQueryProvider($operations['batchAction']));
    self::assertSame(['type_id', 'attribute', 'dependence_attribute', 'dependence_value', 'attribute_value'], $this->parameterNames_QueueAdminQueryProvider($operations['resolveAttributeDependence']));
    $dependenceParams = [];
    foreach ($operations['resolveAttributeDependence']['params'] as $param) {
        $dependenceParams[$param['name']] = $param;
    }
    self::assertSame('mixed', $dependenceParams['dependence_value']['type'] ?? null);
    self::assertTrue($dependenceParams['dependence_value']['required'] ?? false);
    self::assertSame('mixed', $dependenceParams['attribute_value']['type'] ?? null);
    self::assertFalse($dependenceParams['attribute_value']['required'] ?? true);
    foreach (['resolveAttributeDependence', 'save', 'action', 'batchAction', 'setTypeEnabled'] as $name) {
        $params = $this->parameterNames_QueueAdminQueryProvider($operations[$name]);
        foreach (['eav_entity_id', 'entity', 'entity_class', 'model_class', 'class', 'takeover', 'force', 'owner', 'reason', 'pid', 'dispatch_token', 'dispatch_until'] as $forbidden) {
            self::assertNotContains($forbidden, $params, $name . ' must not publish ' . $forbidden);
        }
    }
}

private function assert_QueueAdminQueryProvider_testUnknownAdminOperationFailsClosedBeforeServiceAccess(): void
{
    $provider = $this->providerWithoutConstructor_QueueAdminQueryProvider(QueueAdminQueryProvider::class);
    $this->expectException(\InvalidArgumentException::class);
    $provider->execute('takeover', ['force' => true]);
}

private function assert_QueueAdminQueryProvider_testAdminServiceRejectsOperationalActionsBeforeLoadingQueueState(): void
{
    $service = $this->providerWithoutConstructor_QueueAdminQueryProvider(QueueAdminService::class);
    $single = $service->action(['queue_id' => 1, 'action' => 'takeover']);
    self::assertFalse($single['success']);
    self::assertSame('unsupported_action', $single['error_code']);
    $batch = $service->batchAction(['queue_ids' => [1], 'action' => 'takeover']);
    self::assertFalse($batch['success']);
    self::assertSame(0, $batch['success_count']);
    self::assertSame(0, $batch['failure_count']);
    self::assertSame([], $batch['results']);
}

private function assert_QueueAdminQueryProvider_testTypeAttributeHtmlSanitizerRemovesLegacyScriptsButKeepsFormMarkup(): void
{
    $service = $this->providerWithoutConstructor_QueueAdminQueryProvider(QueueAdminService::class);
    $method = new \ReflectionMethod(QueueAdminService::class, 'stripAttributeScripts');
    $html = '<label>Mode</label><select code="mode" name="mode"><option value="1">One</option></select>' . '<script>$.ajax({url:"/legacy"});</script>';
    $sanitized = $method->invoke($service, $html);
    self::assertIsString($sanitized);
    self::assertStringContainsString('<select code="mode" name="mode">', $sanitized);
    self::assertStringNotContainsString('<script', \strtolower($sanitized));
    self::assertStringNotContainsString('$.ajax', $sanitized);
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
private function providerWithoutConstructor_QueueAdminQueryProvider(string $class): object
{
    $provider = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    self::assertInstanceOf($class, $provider);
    return $provider;
}

/**
 * @param array<string,mixed> $descriptor
 * @return array<string,array<string,mixed>>
 */
private function operationsByName_QueueAdminQueryProvider(array $descriptor): array
{
    self::assertIsArray($descriptor['operations'] ?? null);
    $operations = [];
    foreach ($descriptor['operations'] as $operation) {
        self::assertIsArray($operation);
        self::assertIsString($operation['name'] ?? null);
        $operations[$operation['name']] = $operation;
    }
    return $operations;
}

/**
 * @param array<string,mixed> $operation
 * @return list<string>
 */
private function parameterNames_QueueAdminQueryProvider(array $operation): array
{
    self::assertIsArray($operation['params'] ?? null);
    return \array_map(static fn(array $param): string => (string) ($param['name'] ?? ''), $operation['params']);
}

/** @return array{kind:string,source_id:string} */
private function sourceAcl_QueueAdminQueryProvider(string $sourceId): array
{
    return ['kind' => 'source', 'source_id' => $sourceId];
}

private function assert_QueueIdempotentCreate_testQueuePublishesNullableUniqueIdempotencyKeyWithoutChangingBizKeyIndex(): void
{
    $constant = new \ReflectionClassConstant(Queue::class, 'schema_fields_IDEMPOTENCY_KEY');
    $attributes = $constant->getAttributes(Col::class);
    self::assertCount(1, $attributes);
    $column = $attributes[0]->newInstance();
    self::assertFalse($column->primaryKey);
    self::assertTrue($column->nullable);
    $indexes = [];
    foreach ((new \ReflectionClass(Queue::class))->getAttributes(Index::class) as $attribute) {
        $index = $attribute->newInstance();
        $indexes[$index->name] = $index;
    }
    self::assertSame('UNIQUE', $indexes['uk_idempotency_key']->type);
    self::assertSame([Queue::schema_fields_IDEMPOTENCY_KEY], $indexes['uk_idempotency_key']->columns);
    self::assertNotSame('UNIQUE', $indexes['idx_biz_key']->type);
}

private function assert_QueueIdempotentCreate_testProviderKeepsLegacyCreateAndAddsExplicitAtomicOperation(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Queue' . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString("'create' => \$this->createQueue(\$params)", $source);
    self::assertStringContainsString("'createIfAbsent' => \$this->createQueueIfAbsent(\$params)", $source);
    self::assertStringContainsString("Queue::schema_fields_IDEMPOTENCY_KEY", $source);
    self::assertStringContainsString("\$this->createQueue(\$params, \$storageKey)", $source);
    self::assertStringContainsString("'created' => false", $source);
}
}
