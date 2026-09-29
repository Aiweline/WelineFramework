<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

final class LocaleOnlyMaterializationTest extends TestCase
{
    public function testFrozenLayoutLocaleOnlyParamsRetainThePage(): void
    {
        $result = $this->candidates();
        self::assertNotNull($result['page']['page'], 'Frozen layout locale params are valid generation intent even without base params or a layout/meta revision.');
        self::assertSame(['title' => 'Bonjour'], $result['page']['page']['locale']['fr_FR']['layout']);
        self::assertNull($result['page']['header'], 'Page locale params must not create unrelated chrome.');
        self::assertSame(['page' => null, 'header' => null], $result['none']);
    }

    public function testFrozenPartialLocaleOnlyParamsRetainThePartial(): void
    {
        $result = $this->candidates();
        self::assertNotNull($result['partial']['header'], 'Frozen partial locale params are valid generation intent without base params or chrome nodes.');
        self::assertSame(['title' => 'Entête'], $result['partial']['header']['locale']['fr_FR']['partials.header']);
        self::assertNull($result['partial']['page'], 'Partial locale params must not create an unrelated page.');
        self::assertSame(['page' => null, 'header' => null], $result['none']);
    }

    private function candidates(): array
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/locale-only-candidates.php') . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        return json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
    }
}
