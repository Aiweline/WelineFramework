<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Console\Server\Gateway\Repair;

final class GatewayRepairPayloadTest extends TestCase
{
    public function testClockAcceptanceUsesTheExactSecurityExemptionPayload(): void
    {
        self::assertSame(
            ['accept_clock' => true],
            $this->payload(['accept-clock' => true, 'json' => true]),
        );
    }

    public function testUnselectedRepairFlagsAreNotSerializedAsFalse(): void
    {
        self::assertSame([], $this->payload(['json' => true]));
        self::assertSame(
            ['retry_h3' => true],
            $this->payload(['retry-h3' => true, 'json' => true]),
        );
        self::assertSame(
            [
                'accept_storage_recovery' => true,
            ],
            $this->payload([
                'accept-storage' => true,
                'json' => true,
            ]),
        );
    }

    public function testClockStorageAndJournalRecoveryUseTheControllerExactFieldSet(): void
    {
        $payload = $this->payload([
            'accept-clock' => true,
            'accept-storage' => true,
            'accept-journal-reset' => true,
            'json' => true,
        ]);
        self::assertSame([
            'accept_clock' => true,
            'accept_storage_recovery' => true,
            'accept_journal_reset' => true,
        ], $payload);
        self::assertSame('', $this->validationError($payload));
    }

    public function testStorageRecoveryProtocolAliasesMapToTheSameExactField(): void
    {
        foreach ([
            'accept-storage',
            'accept_storage',
            'accept-storage-recovery',
            'accept_storage_recovery',
        ] as $flag) {
            self::assertSame(
                ['accept_storage_recovery' => true],
                $this->payload([$flag => true]),
            );
        }
    }

    public function testInvalidRepairCombinationsAreRejectedBeforeTransport(): void
    {
        self::assertNotSame('', $this->validationError(
            $this->payload(['accept-journal-reset' => true]),
        ));
        self::assertNotSame('', $this->validationError(
            $this->payload([
                'accept-clock' => true,
                'retry-h3' => true,
            ]),
        ));
        self::assertNotSame('', $this->validationError(
            $this->payload([
                'accept-storage' => true,
                'retry-h3' => true,
            ]),
        ));
    }

    public function testPublicCommandRejectsMixedH3RecoveryBeforeOpeningTransport(): void
    {
        $command = new Repair();
        $command->__init();
        \ob_start();
        $exit = $command->execute([
            'accept-clock' => true,
            'retry-h3' => true,
            'json' => true,
        ]);
        $output = (string)\ob_get_clean();
        $decoded = \json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $exit);
        self::assertFalse($decoded['ok']);
        self::assertSame(
            'invalid_repair_combination',
            $decoded['error']['code'],
        );
    }

    /** @return array<string, true> */
    private function payload(array $args): array
    {
        $method = new ReflectionMethod(Repair::class, 'repairPayload');
        return (array)$method->invoke(null, $args);
    }

    /** @param array<string,true> $payload */
    private function validationError(array $payload): string
    {
        $method = new ReflectionMethod(Repair::class, 'repairPayloadValidationError');
        return (string)$method->invoke(null, $payload);
    }
}
