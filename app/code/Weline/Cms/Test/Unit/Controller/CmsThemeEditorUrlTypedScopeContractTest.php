<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/** CMS → Theme visual editor entry must carry typed Scope claims. */
final class CmsThemeEditorUrlTypedScopeContractTest extends TestCase
{
    public function testBuildThemeEditorUrlEmitsTypedScopeClaims(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Page.php',
        );

        self::assertStringContainsString("'scope_kind'", $source);
        self::assertStringContainsString("'context_version'", $source);
        self::assertStringContainsString('scope_identity', $source);
        self::assertStringContainsString("'lock_source' => 'cms'", $source);
    }
}
