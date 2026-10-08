<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

/**
 * theme_binding published/parent base must keep the leaf Scope for loadBase when
 * the ancestor chain has no Release — layout inherit stays slot/Release walk only.
 */
final class ThemeScopedWorkspaceThemeBindingLeafBaseContractTest extends TestCase
{
    public function testThemeBindingBasePreservesLeafAcrossParentWalk(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspace.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('?ThemeEditorContext $bindingLeaf = null', $source);
        self::assertStringContainsString('$bindingLeaf ??= $context', $source);
        self::assertStringContainsString(
            'RESOURCE_THEME_BINDING',
            $source
        );
        self::assertMatchesRegularExpression(
            '/\\$baseContext = \\$context->resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING\\s*\\? \\$bindingLeaf\\s*: \\$context;/s',
            $source
        );
        // parentPublishedState must pass the child as bindingLeaf.
        self::assertMatchesRegularExpression(
            '/function parentPublishedState\\([\\s\\S]*?publishedState\\(\\s*\\$context->withScope\\(\\$context->scope->parent\\),\\s*true,\\s*\\$context,/s',
            $source
        );
        self::assertStringContainsString('own header/footer', $source);
        self::assertStringContainsString('themeBindingLeafSkipsGlobalRelease', $source);
        self::assertStringContainsString('must NOT', $source);
    }
}
