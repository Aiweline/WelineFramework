<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeContext;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Service\Scoped\ThemeScopedReleaseBatch;

final class ThemeScopedReleaseBatchTest extends TestCase
{
    public function testItFreezesTheCompleteResourceSetInCanonicalOrder(): void
    {
        $batch = ThemeScopedReleaseBatch::fromExpectations(
            $this->baseContext(),
            [
                'i18n' => ['expected_revision' => 5, 'expected_parent_release_id' => 15],
                'layout' => ['expected_revision' => 2, 'expected_parent_release_id' => 12],
                'theme_binding' => ['expected_revision' => 1, 'expected_parent_release_id' => null],
                'appearance' => ['expected_revision' => 4, 'expected_parent_release_id' => 14],
                'meta' => ['expected_revision' => 3, 'expected_parent_release_id' => 13],
            ],
        );

        self::assertSame(ThemeEditorContext::RESOURCES, \array_column($batch->items(), 'resource_type'));
        self::assertSame([1, 2, 3, 4, 5], \array_column($batch->items(), 'expected_revision'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $batch->digest());
        self::assertSame($batch->digest(), $batch->digest());
    }

    public function testItRejectsAnIncompleteResourceSetBeforePublication(): void
    {
        $this->expectExceptionMessage('theme_scope_release_batch_resource_set_incomplete');

        ThemeScopedReleaseBatch::fromExpectations(
            $this->baseContext(),
            [
                'theme_binding' => ['expected_revision' => 1, 'expected_parent_release_id' => null],
                'layout' => ['expected_revision' => 2, 'expected_parent_release_id' => null],
            ],
        );
    }

    public function testItRejectsInvalidRevisionAndParentClaims(): void
    {
        $expectations = [];
        foreach (ThemeEditorContext::RESOURCES as $resourceType) {
            $expectations[$resourceType] = [
                'expected_revision' => $resourceType === ThemeEditorContext::RESOURCE_META ? -1 : 0,
                'expected_parent_release_id' => null,
            ];
        }

        $this->expectExceptionMessage('theme_scope_release_batch_revision_invalid');
        ThemeScopedReleaseBatch::fromExpectations($this->baseContext(), $expectations);
    }

    private function baseContext(): ThemeEditorContext
    {
        $scope = new ScopeContext(
            identity: ScopeIdentity::channel(7, 'shop', 'cn', 'web', ScopeIdentity::MODE_TEST),
            storageScope: 'shop.cn.web',
            storeMode: ScopeIdentity::MODE_TEST,
            fallbackStorageScopes: [
                'shop.cn.web',
                'shop.cn.default',
                'shop.default.default',
                'default.default.default',
            ],
        );

        return new ThemeEditorContext(
            scope: $scope,
            area: 'frontend',
            resourceType: ThemeEditorContext::RESOURCE_LAYOUT,
            themeId: 19,
            layoutType: 'promotion',
            layoutOption: 'default',
            locale: 'en_US',
            targetType: 'global',
            targetId: 0,
        );
    }
}
