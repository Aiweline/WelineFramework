<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySaveException;

final class ThemeWidgetConfigSaveReceiptTest extends TestCase
{
    public function testCommittedConfigFailureReturnsTheActualDatabaseRevision(): void
    {
        $failure = new ThemeLayoutEntitySaveException([
            'revision' => 7, 'content_revision' => 12, 'theme_version_id' => 84, 'revision_id' => 901,
        ], new \RuntimeException('file_replace_failed'));
        $response = $this->executeFailureBoundary($failure);
        self::assertFalse($response['success']);
        self::assertSame($failure->receipt(), $response['saved_revision'] ?? null);
    }

    public function testFailureBeforeCommitDoesNotClaimDatabaseWasSaved(): void
    {
        $response = $this->executeFailureBoundary(new \RuntimeException('theme_layout_owner_lock_timeout'));
        self::assertFalse($response['success']);
        self::assertNull($response['saved_revision'] ?? null);
    }

    private function executeFailureBoundary(\Exception $failure): array
    {
        // Execute this endpoint's exact catch body without its unrelated auth,
        // request parsing and database setup. This checks the response boundary,
        // not the already-tested file publisher or transaction implementation.
        $source = file_get_contents(BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');
        $start = strpos($source, 'public function postSaveWidgetConfig()');
        self::assertNotFalse($start);
        $end = strpos($source, 'public function getCompileLayout()', $start);
        self::assertNotFalse($end);
        $method = substr($source, $start, $end - $start);
        self::assertSame(1, preg_match('/catch \(\\\\Exception \$e\) \{(.*?)\n        \}/s', $method, $match));
        $handler = eval('namespace Weline\\Theme\\Test\\WidgetConfigReceiptProbe; return function (\\Exception $e) {' . $match[1] . '};');
        $endpoint = new class {
            public function fetchJson(array $response): array { return $response; }
        };
        return $handler->call($endpoint, $failure);
    }
}

namespace Weline\Theme\Test\WidgetConfigReceiptProbe;

// Translation is outside this isolated response-boundary test.
function __(string $text, array $params = []): string { return $text; }
