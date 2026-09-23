<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\Console\Server\Shared\Stop;
use Weline\Server\Service\SharedStateServiceManager;

final class SharedStopCommandFailureTest extends TestCase
{
    public function testUnconfirmedStopIsReportedAsFailureInsteadOfAlreadyStopped(): void
    {
        $manager = new class extends SharedStateServiceManager {
            /** @var list<array{stopped:bool,reason:string}> */
            public array $results = [
                ['stopped' => false, 'reason' => 'graceful_shutdown_sent_but_port_still_held'],
                ['stopped' => true, 'reason' => 'stopped'],
            ];

            public function stopWithReport(string $role, array $config = [], array $envConfig = []): array
            {
                $next = \array_shift($this->results) ?? [
                    'stopped' => false,
                    'reason' => 'unconfirmed',
                ];
                return [
                    'stopped' => (bool)$next['stopped'],
                    'role' => $role,
                    'reason' => (string)$next['reason'],
                    'host' => '127.0.0.1',
                    'port' => 9502,
                    'pid' => 4242,
                ];
            }
        };
        $printer = new class extends Printing {
            /** @var list<string> */
            public array $successes = [];
            /** @var list<string> */
            public array $warnings = [];

            public function success(string $data = 'CLI Success!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
            {
                $this->successes[] = $data;
            }

            public function warning(string $data = 'CLI Warning!', string $message = '', string $color = self::WARNING, int $pad_length = 25)
            {
                $this->warnings[] = $data;
            }

            public function note(string $data = 'CLI Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25)
            {
            }
        };
        $command = new class($manager, $printer) extends Stop {
            public function __construct(
                private readonly SharedStateServiceManager $manager,
                Printing $printer,
            ) {
                $this->printer = $printer;
            }

            protected function createManager(): SharedStateServiceManager
            {
                return $this->manager;
            }
        };

        $exitCode = $command->execute();

        self::assertSame(1, $exitCode);
        self::assertCount(1, $printer->warnings);
        self::assertStringContainsString('Session Server', $printer->warnings[0]);
        self::assertStringContainsString('not confirmed', $printer->warnings[0]);
        self::assertStringContainsString('graceful_shutdown_sent_but_port_still_held', $printer->warnings[0]);
        self::assertStringContainsString('pid=4242', $printer->warnings[0]);
        self::assertSame(['Memory Service stopped'], $printer->successes);
        self::assertStringNotContainsString(
            'already stopped',
            \implode("\n", [...$printer->successes, ...$printer->warnings]),
        );
    }
}
