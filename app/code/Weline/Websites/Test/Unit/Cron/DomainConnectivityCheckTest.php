<?php
declare(strict_types=1);

namespace {
    if (!\function_exists('__')) {
        function __($text, $args = null): string
        {
            if (!\is_array($args)) {
                return (string) $text;
            }

            $replacements = [];
            foreach (\array_values($args) as $index => $value) {
                $replacements['%{' . ($index + 1) . '}'] = (string) $value;
            }

            return \strtr((string) $text, $replacements);
        }
    }

    if (!\function_exists('w_log_info')) {
        function w_log_info(string $message, array $context = [], string $channel = ''): void
        {
        }
    }
}

namespace Weline\Websites\Test\Unit\Cron {
    use PHPUnit\Framework\TestCase;
    use Weline\Websites\Cron\DomainConnectivityCheck;
    use Weline\Websites\Model\Domain;
    use Weline\Websites\Model\DomainPool;
    use Weline\Websites\Service\DomainConnectivityService;

    /**
     * 轻/重判定 + TTL 跳过：只测决策，不连库、不连网
     */
    final class DomainConnectivityCheckTest extends TestCase
    {
        /**
         * @param array<string, mixed> $lightResult
         * @param array<string, mixed> $heavyResult
         */
        private function makeCron(array $lightResult, array $heavyResult = [
            'status' => 'ok',
            'detail' => '重活详情',
            'checked_at' => '2026-10-28 10:00:00',
        ]): DomainConnectivityCheck {
            return new class ($lightResult, $heavyResult) extends DomainConnectivityCheck {
                /** @var list<string> */
                public array $lightCalls = [];
                /** @var list<string> */
                public array $heavyCalls = [];
                /** @var list<array{0: string, 1: array, 2: array}> */
                public array $lightWrites = [];
                /** @var list<array{0: string, 1: array, 2: array}> */
                public array $heavyWrites = [];

                /**
                 * @param array<string, mixed> $lightResult
                 * @param array<string, mixed> $heavyResult
                 */
                public function __construct(private array $lightResult, private array $heavyResult)
                {
                }

                public function apply(string $modelClass, array $row, string $domainName, DomainConnectivityService $service, int $ttlHours): ?bool
                {
                    return $this->applyConnectivity($modelClass, $row, $domainName, $service, $ttlHours);
                }

                public function gate(array $light): bool
                {
                    return $this->shouldDoHeavyWork($light);
                }

                public function fresh(string $checkedAt, int $ttlHours): bool
                {
                    return $this->isFresh($checkedAt, $ttlHours);
                }

                protected function probeLight(DomainConnectivityService $service, string $domainName): array
                {
                    $this->lightCalls[] = $domainName;

                    return $this->lightResult;
                }

                protected function probeHeavy(DomainConnectivityService $service, string $domainName): array
                {
                    $this->heavyCalls[] = $domainName;

                    return $this->heavyResult;
                }

                protected function writeLightRow(string $modelClass, array $row, array $light): bool
                {
                    $this->lightWrites[] = [$modelClass, $row, $light];

                    return ($light['status'] ?? '') === 'ok';
                }

                protected function writeHeavyRow(string $modelClass, array $row, array $result): bool
                {
                    $this->heavyWrites[] = [$modelClass, $row, $result];

                    return ($result['status'] ?? '') === 'ok';
                }
            };
        }

        /**
         * @param array<string, mixed> $overrides
         * @return array<string, mixed>
         */
        private function lightResult(array $overrides = []): array
        {
            return \array_merge([
                'status' => 'ok',
                'checked_at' => '2026-10-28 10:00:00',
                'detail' => '轻探测可连通',
                'cert_expires_at' => '2027-01-01 00:00:00',
                'cert_days_left' => 60,
            ], $overrides);
        }

        public function testFreshCheckedAtSkipsProbeAndWrite(): void
        {
            $cron = $this->makeCron($this->lightResult());
            $row = [
                Domain::schema_fields_ID => 7,
                Domain::schema_fields_CONNECTIVITY_CHECKED_AT => \date('Y-m-d H:i:s', \time() - 3600),
            ];

            $result = $cron->apply(Domain::class, $row, 'example.com', new DomainConnectivityService(), 6);

            $this->assertNull($result);
            $this->assertSame([], $cron->lightCalls);
            $this->assertSame([], $cron->lightWrites);
            $this->assertSame([], $cron->heavyWrites);
        }

        public function testLightOnlyWhenCertificateFarFromExpiry(): void
        {
            $cron = $this->makeCron($this->lightResult(['cert_days_left' => 30]));
            $row = [Domain::schema_fields_ID => 7];

            $result = $cron->apply(Domain::class, $row, 'example.com', new DomainConnectivityService(), 6);

            $this->assertTrue($result);
            $this->assertSame(['example.com'], $cron->lightCalls);
            $this->assertSame([], $cron->heavyCalls);
            $this->assertCount(1, $cron->lightWrites);
            $this->assertSame(Domain::class, $cron->lightWrites[0][0]);
            $this->assertSame([], $cron->heavyWrites);
        }

        public function testHeavyWhenCertificateExpiresWithinThreshold(): void
        {
            $cron = $this->makeCron($this->lightResult(['cert_days_left' => 3]));
            $row = [Domain::schema_fields_ID => 9];

            $result = $cron->apply(Domain::class, $row, 'expiring.example.com', new DomainConnectivityService(), 6);

            $this->assertTrue($result);
            $this->assertSame(['expiring.example.com'], $cron->heavyCalls);
            $this->assertSame([], $cron->lightWrites);
            $this->assertSame(Domain::class, $cron->heavyWrites[0][0]);
            $this->assertSame('重活详情', $cron->heavyWrites[0][2]['detail']);
        }

        public function testHeavyWhenLightProbeFails(): void
        {
            $cron = $this->makeCron($this->lightResult([
                'status' => 'error',
                'detail' => 'TLS 握手失败',
                'cert_expires_at' => null,
                'cert_days_left' => null,
            ]), [
                'status' => 'error',
                'detail' => 'HTTP 连接失败',
                'checked_at' => '2026-10-28 10:00:00',
            ]);
            $row = [Domain::schema_fields_ID => 11];

            $result = $cron->apply(Domain::class, $row, 'down.example.com', new DomainConnectivityService(), 6);

            $this->assertFalse($result);
            $this->assertSame(['down.example.com'], $cron->heavyCalls);
            $this->assertSame([], $cron->lightWrites);
            $this->assertSame('error', $cron->heavyWrites[0][2]['status']);
        }

        public function testPoolRowUsesPoolModel(): void
        {
            $cron = $this->makeCron($this->lightResult());
            $row = [DomainPool::schema_fields_ID => 42];

            $result = $cron->apply(DomainPool::class, $row, 'www.example.com', new DomainConnectivityService(), 6);

            $this->assertTrue($result);
            $this->assertSame(DomainPool::class, $cron->lightWrites[0][0]);
            $this->assertSame(42, $cron->lightWrites[0][1][DomainPool::schema_fields_ID]);
        }

        public function testHeavyWorkGateBoundaries(): void
        {
            $cron = $this->makeCron($this->lightResult());

            $this->assertFalse($cron->gate($this->lightResult(['cert_days_left' => 4])));
            $this->assertTrue($cron->gate($this->lightResult(['cert_days_left' => 3])));
            $this->assertTrue($cron->gate($this->lightResult(['cert_days_left' => 0])));
            $this->assertTrue($cron->gate($this->lightResult(['cert_days_left' => -5])));
            $this->assertTrue($cron->gate($this->lightResult(['status' => 'error'])));
            $this->assertTrue($cron->gate($this->lightResult(['cert_days_left' => null])));
        }

        public function testFreshWindowBoundaries(): void
        {
            $cron = $this->makeCron($this->lightResult());

            $this->assertTrue($cron->fresh(\date('Y-m-d H:i:s', \time() - 3600), 6));
            $this->assertFalse($cron->fresh(\date('Y-m-d H:i:s', \time() - 7 * 3600), 6));
            $this->assertFalse($cron->fresh('', 6));
            $this->assertFalse($cron->fresh('不是时间', 6));
        }
    }
}
