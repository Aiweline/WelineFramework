<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\Service;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Api\Scoped\ThemePatchCommand;
use Weline\Theme\Service\Scoped\ThemePatchEngine;
use Weline\Theme\Service\Scoped\ThemeScopedEditorSaveService;
final class ThemeScopedEditorSaveServiceTest extends TestCase
{
    public function testConfigSavePersistsChangedValuesAndReturnsTheActualVersionCursor(): void
    {
        self::assertTrue(class_exists(ThemeScopedEditorSaveService::class));
        $context = $this->context()->withResource('meta');
        $workspace = $this->workspace(['values' => ['title' => 'before', 'keep' => true]]);
        $saved = (new ThemeScopedEditorSaveService($workspace))->saveConfig($context, ['title' => 'after'], [], 'test');
        self::assertSame(['values' => ['title' => 'after', 'keep' => true]], $saved['draft_payload']);
        self::assertSame(12, $saved['content_revision']);
        self::assertSame(7, $saved['theme_version_id']);
    }
    public function testLocaleLayoutValuesRemainInTheirLocaleResource(): void
    {
        self::assertTrue(class_exists(ThemeScopedEditorSaveService::class));
        $context = $this->context()->withResource('i18n')->withLocale('fr_FR');
        $saved = (new ThemeScopedEditorSaveService($this->workspace(['translations' => []])))
            ->saveConfig($context, ['title' => 'Bonjour'], [], 'test');
        self::assertSame(['translations' => ['layout' => ['title' => 'Bonjour']]], $saved['draft_payload']);
    }
    private function context(): ThemeEditorContext
    {
        return new ThemeEditorContext(new ScopeContext(ScopeIdentity::channel(7, 'shop', 'cn', 'app', 'test'), 'shop.cn.app', 'test', ['shop.cn.app']), 'frontend', 'layout', 19, 'homepage', 'default');
    }
    private function workspace(array $payload): ThemeScopedWorkspaceInterface
    {
        $workspace = $this->createMock(ThemeScopedWorkspaceInterface::class);
        $workspace->method('load')->willReturn(['revision' => 4, 'expected_parent_release_id' => null]);
        $workspace->method('applyChanges')->willReturnCallback(static function ($context, $revision, $parent, $changes) use ($payload): array {
            if ($revision !== 4) { throw new \RuntimeException('revision_conflict'); }
            $commands = array_map(static fn(array $command): ThemePatchCommand => ThemePatchCommand::fromArray($command), $changes);
            return ['draft_payload' => (new ThemePatchEngine())->apply($payload, $commands), 'revision' => 5, 'theme_version_id' => 7, 'content_revision' => 12];
        });
        return $workspace;
    }
}
