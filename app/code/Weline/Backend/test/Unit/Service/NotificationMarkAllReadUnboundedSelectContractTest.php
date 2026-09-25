<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 「全部已读 / 此类全部已读」禁止无界 select()->fetchArray()：
 * QueryAst 会抛 Unbounded SELECT（threshold 10000）。
 */
final class NotificationMarkAllReadUnboundedSelectContractTest extends TestCase
{
    public function testMarkAllAndTopicReadUseBulkSafeFetchPaths(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Service/NotificationService.php'
        );

        $markAll = $this->extractMethodBody($source, 'markAllAsRead');
        $markTopic = $this->extractMethodBody($source, 'markByTopicAsRead');
        $bulkHelper = $this->extractMethodBody($source, 'bulkMarkStatusIdsAsRead');

        self::assertNotSame('', $markAll);
        self::assertNotSame('', $markTopic);
        self::assertNotSame('', $bulkHelper);

        self::assertStringContainsString('->update([', $markAll);
        self::assertStringContainsString('->total()', $markAll);
        self::assertStringNotContainsString('fetchArray()', $markAll);
        self::assertStringNotContainsString('->save()', $markAll);

        self::assertStringContainsString('fetchIterator()', $markTopic);
        self::assertStringContainsString('bulkMarkStatusIdsAsRead', $markTopic);
        self::assertStringNotContainsString('fetchArray()', $markTopic);
        self::assertStringNotContainsString('->save()', $markTopic);

        self::assertStringContainsString('->update([', $bulkHelper);
        self::assertStringContainsString("'IN'", $bulkHelper);
    }

    private function extractMethodBody(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }
}
