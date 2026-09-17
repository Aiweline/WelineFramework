<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class ThemePublishedVersionRuntimeResolverTest extends TestCase
{
    public function testResolverWalksRequestScopeChainBeforeAnyPublishedFallback(): void
    {
        $source = $this->read('app/code/Weline/Theme/Service/ThemePublishedVersionRuntimeResolver.php');

        self::assertStringContainsString('RequestContext::scopeIdentity()', $source);
        self::assertStringContainsString('fallbackStorageScopes', $source);
        self::assertStringNotContainsString('->findAnyPublishedVersion(', $source);
        self::assertStringNotContainsString('$versions->findAnyPublishedVersion', $source);
        self::assertStringContainsString('identityCandidates', $source);
        self::assertStringContainsString('default.default.default', $source);
        self::assertStringContainsString('no findAnyPublishedVersion cross-identity steal', $source);
    }

    public function testEditorShellKeepsEditorAreaIndependentFromPreviewArea(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');

        self::assertStringContainsString('$editorArea = $this->resolveRequestedEditorArea();', $controller);
        self::assertStringNotContainsString('$previewAreaParam = $this->request->getParam(', $controller);
    }

    public function testWorkspaceReturnsBlockedResultToRequestBoundary(): void
    {
        $workspace = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspace.php');

        $blockedOffset = \strpos($workspace, "if ((\$result['blocked'] ?? false) === true) {");
        self::assertNotFalse($blockedOffset);
        $snippet = \substr($workspace, $blockedOffset, 160);
        self::assertStringContainsString('return $result;', $snippet);
    }
    public function testThemeEditorPublishFlushesScopedThemeCaches(): void
    {
        $controller = $this->read('app/code/Weline/Theme/Controller/Backend/ThemeEditor.php');

        self::assertStringContainsString('clearAllThemeRelatedCaches(', $controller);
        self::assertStringContainsString('flushFullPageCache(?ThemeEditorContext $context', $controller);
        self::assertStringContainsString('flushFullPageCache($context, $themeId)', $controller);
        self::assertStringContainsString('theme_scope_structural_conflict', $controller);
        self::assertStringContainsString("!empty(\$data['blocked'])", $controller);
    }

    public function testScopedPublishSkipsCacheInvalidationWhenBlocked(): void
    {
        $service = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspaceRequestService.php');

        self::assertStringContainsString("!empty(\$result['blocked'])", $service);
        self::assertStringContainsString('clearAllThemeRelatedCaches(', $service);
        self::assertLessThan(
            \strpos($service, 'clearAllThemeRelatedCaches('),
            \strpos($service, "!empty(\$result['blocked'])"),
        );
    }

    public function testEditorJsRejectsBlockedScopedPublish(): void
    {
        $ui = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');
        $legacy = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');

        foreach ([$ui, $legacy] as $source) {
            self::assertStringContainsString('result?.data?.blocked', $source);
            self::assertStringContainsString("throw new Error('theme_scope_structural_conflict')", $source);
        }
    }

    private function read(string $relative): string
    {
        $path = BP . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
