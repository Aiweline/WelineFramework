<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\Console\Server\Start;

final class StartSharedStateRuntimeSummaryOutputTest extends TestCase
{
    /**
     * @return array{session: array<string, mixed>, memory: array<string, mixed>}
     */
    private function sampleRuntime(): array
    {
        return [
            'session' => [
                'host' => '127.0.0.1',
                'port' => 26277,
                'token_file_name' => 'session_server.26277.token',
                'reuse_existing' => true,
                'created_now' => false,
                'shared_service' => true,
                'pid' => 86966,
                'process_name' => 'weline-wls-session-weline-shared-state-26277',
                'instance_name' => 'weline-shared-state',
                'independent' => true,
                'registered' => true,
                'identity_digest' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'lease_expires_at' => null,
            ],
            'memory' => [
                'host' => '127.0.0.1',
                'port' => 26278,
                'token_file_name' => 'memory_server.26278.token',
                'reuse_existing' => true,
                'created_now' => false,
                'shared_service' => true,
                'pid' => 86967,
                'process_name' => 'weline-wls-memory-weline-shared-state-26278',
                'instance_name' => 'weline-shared-state',
                'independent' => true,
                'registered' => true,
                'identity_digest' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'lease_expires_at' => null,
            ],
        ];
    }

    public function testDefaultStartupNotesKeepSummaryAndOmitFullRuntimeJsonDump(): void
    {
        $printer = new class extends Printing {
            /** @var list<string> */
            public array $notes = [];

            public function note(string $data = 'CLI Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25)
            {
                $this->notes[] = $data;
            }
        };

        $start = new class ($printer) extends Start {
            public function __construct(Printing $printer)
            {
                $this->printer = $printer;
            }

            public function emit(string $instanceName, array $runtime, bool $verboseDetail): void
            {
                $this->printSharedStateRuntimeStartupNotes($instanceName, $runtime, $verboseDetail);
            }
        };

        $start->emit('default', $this->sampleRuntime(), false);
        $joined = \implode("\n", $printer->notes);

        self::assertNotSame('', $joined);
        self::assertStringContainsString('Session Server', $joined);
        self::assertStringContainsString('Memory Service', $joined);
        self::assertStringContainsString('26277', $joined);
        self::assertStringContainsString('86966', $joined);
        self::assertStringNotContainsString('identity_digest', $joined);
        self::assertStringNotContainsString('lease_expires_at', $joined);
        self::assertStringNotContainsString('token_file_name', $joined);
        self::assertStringNotContainsString('共享状态运行时:', $joined);
        self::assertDoesNotMatchRegularExpression('/\{"session":/', $joined);
    }

    public function testVerboseStartupNotesUseCompactDetailWithoutFullRuntimeJsonDump(): void
    {
        $printer = new class extends Printing {
            /** @var list<string> */
            public array $notes = [];

            public function note(string $data = 'CLI Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25)
            {
                $this->notes[] = $data;
            }
        };

        $start = new class ($printer) extends Start {
            public function __construct(Printing $printer)
            {
                $this->printer = $printer;
            }

            public function emit(string $instanceName, array $runtime, bool $verboseDetail): void
            {
                $this->printSharedStateRuntimeStartupNotes($instanceName, $runtime, $verboseDetail);
            }
        };

        $start->emit('default', $this->sampleRuntime(), true);
        $joined = \implode("\n", $printer->notes);

        self::assertStringContainsString('共享状态运行时详情:', $joined);
        self::assertStringContainsString('session port=26277', $joined);
        self::assertStringContainsString('memory port=26278', $joined);
        self::assertStringContainsString('Session Server', $joined);
        self::assertStringNotContainsString('identity_digest', $joined);
        self::assertDoesNotMatchRegularExpression('/\{"session":\{"host"/', $joined);
    }
}
