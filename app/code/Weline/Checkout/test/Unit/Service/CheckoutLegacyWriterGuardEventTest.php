<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Service\CheckoutService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\EventsManager;

final class CheckoutLegacyWriterGuardEventTest extends TestCase
{
    public function testLegacyWriterGuardEventRunsBeforeValidationAndTransaction(): void
    {
        $events = new class extends EventsManager {
            /** @var list<array{name:string,data:array<string,mixed>}> */
            public array $seen = [];

            public function __construct()
            {
            }

            public function dispatch(string $eventName, mixed &$data = []): static
            {
                $this->seen[] = [
                    'name' => $eventName,
                    'data' => is_array($data) ? $data : [],
                ];
                if ($eventName === 'Weline_Checkout::checkout::legacy_writer::assert') {
                    throw new \RuntimeException('legacy_writer_guard_probe');
                }
                return $this;
            }
        };
        $connectionFactory = (new \ReflectionClass(ConnectionFactory::class))
            ->newInstanceWithoutConstructor();
        $service = new class($connectionFactory, $events) extends CheckoutService {
            public function normalizeCheckoutIdentity(array $data): array
            {
                $data['website_id'] = 0;
                return $data;
            }
        };

        try {
            $service->createOrder([]);
            self::fail('guard probe must stop before validation/transaction');
        } catch (\RuntimeException $e) {
            self::assertSame('legacy_writer_guard_probe', $e->getMessage());
        }

        self::assertCount(1, $events->seen);
        self::assertSame(
            'Weline_Checkout::checkout::legacy_writer::assert',
            $events->seen[0]['name'],
        );
        self::assertArrayHasKey('website_id', $events->seen[0]['data']['data']);
        self::assertSame(0, $events->seen[0]['data']['data']['website_id']);
    }
}
