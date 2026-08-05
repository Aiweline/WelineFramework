<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Queue\Extends\Module\Weline_Framework\Query\QueueAdminQueryProvider;
use Weline\Queue\Extends\Module\Weline_Framework\Query\QueueQueryProvider;
use Weline\Queue\Service\QueueAdminService;

final class QueueAdminQueryProviderContractTest extends TestCase
{
    private const ACTION_ACL_MAP = [
        'delete' => 'Weline_Queue::delete',
        'stop' => 'Weline_Queue::stop',
        'continue' => 'Weline_Queue::continue',
        'retry' => 'Weline_Queue::continue',
        'reset' => 'Weline_Queue::reset',
    ];

    private const BATCH_ACTION_ACL_MAP = [
        'delete' => 'Weline_Queue::delete',
        'stop' => 'Weline_Queue::stop',
        'continue' => 'Weline_Queue::continue',
    ];

    public function testDescriptorPublishesOnlyTheEightAuthenticatedBackendOperations(): void
    {
        $provider = $this->providerWithoutConstructor(QueueAdminQueryProvider::class);
        $descriptor = $provider->getDescriptor();
        $operations = $this->operationsByName($descriptor);

        self::assertSame('queue_admin', $provider->getProviderName());
        self::assertSame('queue_admin', $descriptor['provider']);
        self::assertSame('Weline_Queue', $descriptor['module']);
        self::assertSame(
            [
                'snapshot',
                'searchTypes',
                'typeAttributes',
                'resolveAttributeDependence',
                'save',
                'action',
                'batchAction',
                'setTypeEnabled',
            ],
            \array_keys($operations),
        );

        $expectedModes = [
            'snapshot' => 'read',
            'searchTypes' => 'read',
            'typeAttributes' => 'read',
            'resolveAttributeDependence' => 'read',
            'save' => 'write',
            'action' => 'write',
            'batchAction' => 'write',
            'setTypeEnabled' => 'write',
        ];
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

    public function testDescriptorUsesLeastPrivilegeSourceAndParamMapAclPolicies(): void
    {
        $operations = $this->operationsByName(
            $this->providerWithoutConstructor(QueueAdminQueryProvider::class)->getDescriptor()
        );

        self::assertSame($this->sourceAcl('Weline_Queue::index'), $operations['snapshot']['backend_acl']);
        self::assertSame($this->sourceAcl('Weline_Queue::search_type'), $operations['searchTypes']['backend_acl']);
        self::assertSame(
            $this->sourceAcl('Weline_Queue::form'),
            $operations['typeAttributes']['backend_acl']
        );
        self::assertSame(
            $this->sourceAcl('Weline_Queue::get_type_attributes'),
            $operations['resolveAttributeDependence']['backend_acl']
        );
        self::assertSame($this->sourceAcl('Weline_Queue::form'), $operations['save']['backend_acl']);
        self::assertSame($this->sourceAcl('Weline_Queue::type_manage'), $operations['setTypeEnabled']['backend_acl']);

        self::assertSame(
            [
                'kind' => 'param_map',
                'param' => 'action',
                'map' => self::ACTION_ACL_MAP,
            ],
            $operations['action']['backend_acl']
        );
        self::assertSame(
            [
                'kind' => 'param_map',
                'param' => 'action',
                'map' => self::BATCH_ACTION_ACL_MAP,
            ],
            $operations['batchAction']['backend_acl']
        );

        foreach (['action', 'batchAction'] as $name) {
            $params = [];
            foreach ($operations[$name]['params'] as $param) {
                $params[$param['name']] = $param;
            }
            self::assertArrayHasKey('action', $params, $name . ' must declare its ACL selector parameter');
            self::assertTrue($params['action']['required'] ?? false);
        }
    }

    public function testLegacyQueueProviderRemainsServerOnly(): void
    {
        $descriptor = $this->providerWithoutConstructor(QueueQueryProvider::class)->getDescriptor();

        self::assertSame('queue', $descriptor['provider']);
        foreach ($descriptor['operations'] as $operation) {
            self::assertFalse(
                (bool)($operation['frontend'] ?? false),
                (string)($operation['name'] ?? 'unknown') . ' must not be exposed to browser callers'
            );
        }
    }

    public function testBrowserOperationsDoNotPublishOperationalOwnershipParameters(): void
    {
        $operations = $this->operationsByName(
            $this->providerWithoutConstructor(QueueAdminQueryProvider::class)->getDescriptor()
        );

        self::assertSame(
            ['queue_id', 'type_id', 'name', 'biz_key', 'attributes'],
            $this->parameterNames($operations['save'])
        );
        self::assertSame(['queue_id', 'action'], $this->parameterNames($operations['action']));
        self::assertSame(['queue_ids', 'action'], $this->parameterNames($operations['batchAction']));
        self::assertSame(
            [
                'type_id',
                'attribute',
                'dependence_attribute',
                'dependence_value',
                'attribute_value',
            ],
            $this->parameterNames($operations['resolveAttributeDependence'])
        );
        $dependenceParams = [];
        foreach ($operations['resolveAttributeDependence']['params'] as $param) {
            $dependenceParams[$param['name']] = $param;
        }
        self::assertSame('mixed', $dependenceParams['dependence_value']['type'] ?? null);
        self::assertTrue($dependenceParams['dependence_value']['required'] ?? false);
        self::assertSame('mixed', $dependenceParams['attribute_value']['type'] ?? null);
        self::assertFalse($dependenceParams['attribute_value']['required'] ?? true);

        foreach (['resolveAttributeDependence', 'save', 'action', 'batchAction', 'setTypeEnabled'] as $name) {
            $params = $this->parameterNames($operations[$name]);
            foreach ([
                'eav_entity_id',
                'entity',
                'entity_class',
                'model_class',
                'class',
                'takeover',
                'force',
                'owner',
                'reason',
                'pid',
                'dispatch_token',
                'dispatch_until',
            ] as $forbidden) {
                self::assertNotContains($forbidden, $params, $name . ' must not publish ' . $forbidden);
            }
        }
    }

    public function testUnknownAdminOperationFailsClosedBeforeServiceAccess(): void
    {
        $provider = $this->providerWithoutConstructor(QueueAdminQueryProvider::class);

        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('takeover', ['force' => true]);
    }

    public function testAdminServiceRejectsOperationalActionsBeforeLoadingQueueState(): void
    {
        $service = $this->providerWithoutConstructor(QueueAdminService::class);

        $single = $service->action(['queue_id' => 1, 'action' => 'takeover']);
        self::assertFalse($single['success']);
        self::assertSame('unsupported_action', $single['error_code']);

        $batch = $service->batchAction(['queue_ids' => [1], 'action' => 'takeover']);
        self::assertFalse($batch['success']);
        self::assertSame(0, $batch['success_count']);
        self::assertSame(0, $batch['failure_count']);
        self::assertSame([], $batch['results']);
    }

    public function testTypeAttributeHtmlSanitizerRemovesLegacyScriptsButKeepsFormMarkup(): void
    {
        $service = $this->providerWithoutConstructor(QueueAdminService::class);
        $method = new \ReflectionMethod(QueueAdminService::class, 'stripAttributeScripts');
        $html = '<label>Mode</label><select code="mode" name="mode"><option value="1">One</option></select>'
            . '<script>$.ajax({url:"/legacy"});</script>';

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
    private function providerWithoutConstructor(string $class): object
    {
        $provider = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf($class, $provider);

        return $provider;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,array<string,mixed>>
     */
    private function operationsByName(array $descriptor): array
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
    private function parameterNames(array $operation): array
    {
        self::assertIsArray($operation['params'] ?? null);

        return \array_map(
            static fn (array $param): string => (string)($param['name'] ?? ''),
            $operation['params'],
        );
    }

    /** @return array{kind:string,source_id:string} */
    private function sourceAcl(string $sourceId): array
    {
        return [
            'kind' => 'source',
            'source_id' => $sourceId,
        ];
    }
}
