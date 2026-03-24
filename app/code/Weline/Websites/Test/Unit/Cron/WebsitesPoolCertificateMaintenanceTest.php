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

    if (!\function_exists('w_log_error')) {
        function w_log_error(string $message, array $context = [], string $channel = ''): void
        {
        }
    }
}

namespace Weline\Websites\Test\Unit\Cron {
    use PHPUnit\Framework\TestCase;
    use Weline\Websites\Cron\WebsitesPoolCertificateMaintenance;

    final class WebsitesPoolCertificateMaintenanceTest extends TestCase
    {
        public function testExecuteRunsVerifyBeforeRequest(): void
        {
            $cron = new class extends WebsitesPoolCertificateMaintenance {
                /** @var list<string> */
                public array $calls = [];

                protected function runCertificateVerify(): string
                {
                    $this->calls[] = 'verify';

                    return 'verify ok';
                }

                protected function runCertificateRequest(): string
                {
                    $this->calls[] = 'request';

                    return 'request ok';
                }
            };

            $result = $cron->execute();

            $this->assertSame(['verify', 'request'], $cron->calls);
            $this->assertStringContainsString('verify ok', $result);
            $this->assertStringContainsString('request ok', $result);
        }

        public function testExecuteStillRunsRequestWhenVerifyFails(): void
        {
            $cron = new class extends WebsitesPoolCertificateMaintenance {
                /** @var list<string> */
                public array $calls = [];

                protected function runCertificateVerify(): string
                {
                    $this->calls[] = 'verify';

                    throw new \RuntimeException('verify failed');
                }

                protected function runCertificateRequest(): string
                {
                    $this->calls[] = 'request';

                    return 'request ok';
                }
            };

            $result = $cron->execute();

            $this->assertSame(['verify', 'request'], $cron->calls);
            $this->assertStringContainsString('verify failed', $result);
            $this->assertStringContainsString('request ok', $result);
        }
    }
}
