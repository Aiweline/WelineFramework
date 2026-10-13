<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployReleaseHistoryService;

/**
 * Contract: markFailed must truncate error_message and output_tail so short
 * production VARCHAR columns cannot mask the real failure with
 * "Data too long for column …".
 */
final class DeployReleaseMarkFailedTruncateContractTest extends TestCase
{
    public function testMarkFailedUsesTruncateHelperAndMaxCharsConstants(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DeployReleaseHistoryService.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('ERROR_MESSAGE_MAX_CHARS = 500', $source);
        self::assertStringContainsString('OUTPUT_TAIL_MAX_CHARS = 1000', $source);
        self::assertStringContainsString('function truncateFailedMessages(', $source);
        self::assertStringContainsString('function clipUtf8(', $source);
        self::assertStringContainsString('$this->truncateFailedMessages($errorMessage, $outputTail)', $source);
        self::assertStringContainsString('schema_fields_OUTPUT_TAIL', $source);
    }

    public function testTruncateFailedMessagesCapsErrorAndOutputTail(): void
    {
        $service = new DeployReleaseHistoryService();
        $long = str_repeat('x', DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS + 400);
        $result = $service->truncateFailedMessages($long, 'existing-tail');

        self::assertSame(
            DeployReleaseHistoryService::ERROR_MESSAGE_MAX_CHARS,
            mb_strlen($result['error_message'], 'UTF-8')
        );
        self::assertSame(
            DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS,
            mb_strlen($result['output_tail'], 'UTF-8')
        );
        // Prefer error-summary first segment (not the far end of the log).
        self::assertSame(
            mb_substr($long, 0, DeployReleaseHistoryService::ERROR_MESSAGE_MAX_CHARS, 'UTF-8'),
            $result['error_message']
        );
        self::assertSame(
            mb_substr($long, 0, DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS, 'UTF-8'),
            $result['output_tail']
        );
    }

    public function testTruncateFailedMessagesCapsStandaloneOutputTail(): void
    {
        $service = new DeployReleaseHistoryService();
        $longTail = str_repeat('y', DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS + 250);
        $result = $service->truncateFailedMessages('short failure', $longTail);

        self::assertSame('short failure', $result['error_message']);
        self::assertSame(
            DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS,
            mb_strlen($result['output_tail'], 'UTF-8')
        );
        self::assertSame(
            mb_substr($longTail, 0, DeployReleaseHistoryService::OUTPUT_TAIL_MAX_CHARS, 'UTF-8'),
            $result['output_tail']
        );
    }

    public function testTruncateFailedMessagesLeavesShortErrorUnchanged(): void
    {
        $service = new DeployReleaseHistoryService();
        $result = $service->truncateFailedMessages('short failure', '');

        self::assertSame('short failure', $result['error_message']);
        self::assertSame('', $result['output_tail']);
    }
}
