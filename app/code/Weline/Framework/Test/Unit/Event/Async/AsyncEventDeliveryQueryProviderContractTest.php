<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\Async;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Async\Admin\DeliveryAccessPolicy;
use Weline\Framework\Event\Async\Admin\DeliveryReplayService;
use Weline\Framework\Event\Async\AsyncErrorRedactor;
use Weline\Framework\Extends\Module\Weline_Framework\Query\AsyncEventDeliveryQueryProvider;
use Weline\Framework\Model\Event\Delivery;

/** Plan coverage: UI01, UI02, BR01 QueryProvider/ACL contract. */
final class AsyncEventDeliveryQueryProviderContractTest extends TestCase
{
    public function testUi01DescriptorPublishesThreeBackendAclOperations(): void
    {
        $descriptor = $this->provider()->getDescriptor();
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            $operations[$operation['name']] = $operation;
        }

        self::assertSame('async_event_delivery', $descriptor['provider']);
        self::assertSame(
            [
                'asyncEventDeliveryList',
                'asyncEventDeliveryDetail',
                'asyncEventDeliveryReplay',
            ],
            array_keys($operations),
        );

        foreach (['asyncEventDeliveryList', 'asyncEventDeliveryDetail'] as $name) {
            self::assertSame('backend', $operations[$name]['auth']);
            self::assertSame('read', $operations[$name]['mode']);
            self::assertSame([
                'kind' => 'source',
                'source_id' => DeliveryAccessPolicy::ACL_VIEW,
            ], $operations[$name]['backend_acl']);
        }
        self::assertSame('write', $operations['asyncEventDeliveryReplay']['mode']);
        self::assertSame([
            'kind' => 'source',
            'source_id' => DeliveryAccessPolicy::ACL_REPLAY,
        ], $operations['asyncEventDeliveryReplay']['backend_acl']);
        self::assertNotSame(
            $operations['asyncEventDeliveryList']['backend_acl']['source_id'],
            $operations['asyncEventDeliveryReplay']['backend_acl']['source_id'],
        );
    }

    public function testUi01WebsiteZeroAndUi02ReplayReasonBoundsArePublished(): void
    {
        $operations = [];
        foreach ($this->provider()->getDescriptor()['operations'] as $operation) {
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

    public function testUi02UnknownOperationFailsClosedBeforeDataAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->provider()->execute('unsafeUnknownOperation', []);
    }

    private function provider(): AsyncEventDeliveryQueryProvider
    {
        $replay = (new \ReflectionClass(DeliveryReplayService::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(DeliveryReplayService::class, $replay);

        return new AsyncEventDeliveryQueryProvider(
            $this->createStub(Delivery::class),
            new DeliveryAccessPolicy(new AsyncErrorRedactor()),
            $replay,
        );
    }
}
