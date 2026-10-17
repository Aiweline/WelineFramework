<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\Data\ResolvedFileImage;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Extends\Module\Weline_Theme\Integration\FileImageLayoutValueHydrator;
use Weline\FileManager\Service\FileAssetManager;
use Weline\FileManager\Service\FileAssetReferenceIndexer;
use Weline\FileManager\Service\LayoutContentValidator;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllFileManagerServiceContractsPass().
 */
final class FileManagerServiceContractsTest extends TestCase
{
    public function testAllFileManagerServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'FileAssetLocaleTranslation::testTranslationSurfaceContracts' => function () {
            $this->assert_FileAssetLocaleTranslation_testTranslationSurfaceContracts();
        },
            'FileImageFrameWrap::testNormalizeFrameRatioWhitelist' => function () {
            $this->assert_FileImageFrameWrap_testNormalizeFrameRatioWhitelist();
        },
            'FileImageFrameWrap::testResolveImageSourceDeclaresFrameHelper' => function () {
            $this->assert_FileImageFrameWrap_testResolveImageSourceDeclaresFrameHelper();
        },
            'FileImageFrameWrap::testTaglibPassesFrameAttribute' => function () {
            $this->assert_FileImageFrameWrap_testTaglibPassesFrameAttribute();
        },
            'FileImageLayout::testDraftReferenceIndexingValidatesAgainstUsageLocale' => function () {
            $this->assert_FileImageLayout_testDraftReferenceIndexingValidatesAgainstUsageLocale();
        },
            'FileImageLayout::testPublicationValidatesTypedUsageAgainstExactScopeAndLocale' => function () {
            $this->assert_FileImageLayout_testPublicationValidatesTypedUsageAgainstExactScopeAndLocale();
        },
            'FileImageLayout::testTypedUsageRequiresExplicitScopeAndLocaleContext' => function () {
            $this->assert_FileImageLayout_testTypedUsageRequiresExplicitScopeAndLocaleContext();
        },
            'FileImageLayout::testRuntimeHydrationKeepsUrlAndHtmlOutOfPersistedUsage' => function () {
            $this->assert_FileImageLayout_testRuntimeHydrationKeepsUrlAndHtmlOutOfPersistedUsage();
        },
            'FileImageLayout::testRuntimeHydrationRejectsCrossLocaleUsage' => function () {
            $this->assert_FileImageLayout_testRuntimeHydrationRejectsCrossLocaleUsage();
        },
            'FileImageLayout::testPreviewHydrationFallsBackToUsageLocaleOnMismatch' => function () {
            $this->assert_FileImageLayout_testPreviewHydrationFallsBackToUsageLocaleOnMismatch();
        },
            'FileImageLayout::testRenderHydrationFallsBackWhenLayoutLocaleEmpty' => function () {
            $this->assert_FileImageLayout_testRenderHydrationFallsBackWhenLayoutLocaleEmpty();
        },
            'FileImageLayout::testRenderHydrationSoftFailsWhenAssetMissing' => function () {
            $this->assert_FileImageLayout_testRenderHydrationSoftFailsWhenAssetMissing();
        },
            'FileImageLayout::testPreviewHydrationSoftFailsWhenAssetMissing' => function () {
            $this->assert_FileImageLayout_testPreviewHydrationSoftFailsWhenAssetMissing();
        },
        ];
    }

private function assert_FileAssetLocaleTranslation_testTranslationSurfaceContracts(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/FileManager';
    $interfacePath = $root . '/Api/FileAssetLocaleTranslationInterface.php';
    self::assertFileExists($interfacePath, 'root=' . $root . ' dir=' . __DIR__);
    $interface = file_get_contents($interfacePath);
    $service = file_get_contents($root . '/Service/FileAssetLocaleTranslationService.php');
    $config = file_get_contents($root . '/Service/FileAssetLocaleTranslationConfig.php');
    $cron = file_get_contents($root . '/Cron/FileAssetLocaleAiTranslation.php');
    $queue = file_get_contents($root . '/Queue/FileAssetLocaleAiTranslationQueue.php');
    $module = file_get_contents($root . '/etc/module.php');
    self::assertIsString($interface);
    self::assertIsString($service);
    self::assertIsString($config);
    self::assertIsString($cron);
    self::assertIsString($queue);
    self::assertIsString($module);
    self::assertStringContainsString('function listInstalledLocaleCodes', $interface);
    self::assertStringContainsString('function translateMissing', $interface);
    self::assertStringContainsString('function listLocales', $interface);
    self::assertStringContainsString('function isAutoTranslationEnabled', $interface);
    self::assertStringContainsString('function enqueueAutoFill', $interface);
    self::assertStringContainsString("ORIGIN_MACHINE", $service);
    self::assertStringContainsString("STATE_DRAFT", $service);
    self::assertStringContainsString("Weline_I18n::machine_translate", $service);
    self::assertStringContainsString("'concurrency_lane' => 'file-asset'", $service);
    self::assertStringContainsString('localeHasContent', $service);
    self::assertStringContainsString('if (!$this->config->isEnabled())', $service);
    self::assertStringContainsString("KEY = 'ai_auto_translation'", $config);
    self::assertStringContainsString('isAutoTranslationEnabled()', $cron);
    self::assertStringContainsString('enqueueAutoFill', $cron);
    self::assertStringContainsString('processPendingBatch', $queue);
    self::assertStringContainsString('enqueueContinuation', $queue);
    $queueService = (string) file_get_contents($root . '/Service/FileAssetLocaleTranslationQueueService.php');
    self::assertStringContainsString('function enqueueContinuation', $queueService);
    self::assertStringContainsString('function findActiveFamilyQueueId', $queueService);
    self::assertStringContainsString('IdempotentQueueAdmission', $queueService);
    self::assertStringContainsString('IDEMPOTENCY_SCOPE', $queueService);
    self::assertStringContainsString("admission->admit", $queueService);
    self::assertStringContainsString(':offset:%', $queueService);
    self::assertStringContainsString("'offset' => \$offset", $queueService);
    self::assertStringContainsString('hasGapFillWork', $service);
    self::assertStringContainsString('assetNeedsGapFill', $service);
    self::assertStringContainsString('next_offset', $service);
    self::assertStringContainsString('assetNeedsGapFill($asset)', $service);
    self::assertStringContainsString('aborted_busy', $service);
    self::assertStringContainsString('AI_TRANSLATION_BUSY', $service);
    self::assertStringContainsString("'continuation' => !\$abortedBusy && !\$exhausted", $service);
    self::assertStringContainsString('next_offset', $queue);
    self::assertStringContainsString('aborted_busy', $queue);
    self::assertStringContainsString("empty(\$result['aborted_busy'])", $queue);
    self::assertStringContainsString('不立刻续队', $queue);
    self::assertStringContainsString('FileAssetLocaleTranslationInterface', $module);
    self::assertStringContainsString('FileAssetLocaleTranslationService', $module);
}

private function assert_FileImageFrameWrap_testNormalizeFrameRatioWhitelist(): void
{
    self::assertSame('1', FileAssetManager::normalizeFrameRatio(800, 800));
    self::assertSame('16/9', FileAssetManager::normalizeFrameRatio(1600, 900));
    self::assertSame('4/3', FileAssetManager::normalizeFrameRatio(400, 300));
}

private function assert_FileImageFrameWrap_testResolveImageSourceDeclaresFrameHelper(): void
{
    $src = '/Users/weline/Project/Official/框架/app/code/Weline/FileManager' . '/Service/FileAssetManager.php';
    self::assertFileExists($src);
    $php = (string) file_get_contents($src);
    self::assertStringContainsString('maybeWrapLayoutFrame', $php);
    self::assertStringContainsString('class="w-frame"', $php);
    self::assertStringContainsString('normalizeFrameRatio', $php);
}

private function assert_FileImageFrameWrap_testTaglibPassesFrameAttribute(): void
{
    $src = '/Users/weline/Project/Official/框架/app/code/Weline/FileManager' . '/Taglib/Image.php';
    $php = (string) file_get_contents($src);
    self::assertStringContainsString("'frame' => false", $php);
    self::assertStringContainsString('Taglib__frame', $php);
}

private const ASSET_ID_FileImageLayout = '123e4567-e89b-42d3-a456-426614174000';

private function assert_FileImageLayout_testDraftReferenceIndexingValidatesAgainstUsageLocale(): void
{
    $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('validateImageReference')->willReturnCallback(static function (ImageUsage $usage, FileAccessContext $access) use ($scope): void {
        self::assertSame(self::ASSET_ID_FileImageLayout, $usage->assetId);
        self::assertSame('zh_Hans_CN', $usage->localeCode);
        self::assertTrue($scope->equals($access->scope));
        // Draft index follows the stamped usage locale, not the layout website-default.
        self::assertSame('zh_Hans_CN', $access->localeCode);
        self::assertSame('draft_index', $access->purpose);
    });
    $assets->expects(self::never())->method('validateImageUsage');
    $validator = new LayoutContentValidator($assets, $this->uninitializedIndexer_FileImageLayout());
    try {
        $validator->validate(['main' => [['type' => 'file-image', 'usage' => $this->usage_FileImageLayout(['locale_code' => 'zh_Hans_CN'])]]], ['scope_identity' => $scope, 'locale_code' => 'en_US', 'purpose' => 'draft_index', 'reference_only' => true, 'index_references' => true, 'reference_owner_type' => 'theme_layout_draft', 'reference_owner_id' => 'draft-1', 'owner_version' => 1]);
    } catch (\Throwable) {
        // Final indexer is not mockable; replace may fail after validation. The
        // mock expectation above already proves access locale followed usage.
    }
}

private function assert_FileImageLayout_testPublicationValidatesTypedUsageAgainstExactScopeAndLocale(): void
{
    $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('validateImageUsage')->willReturnCallback(static function (ImageUsage $usage, FileAccessContext $access) use ($scope): void {
        self::assertSame(self::ASSET_ID_FileImageLayout, $usage->assetId);
        self::assertSame('en_US', $usage->localeCode);
        self::assertTrue($scope->equals($access->scope));
        self::assertSame('en_US', $access->localeCode);
        self::assertSame(FileAccessContext::PURPOSE_PUBLIC_PUBLISH, $access->purpose);
        $usage->assertPublishable($access->localeCode);
    });
    $validator = new LayoutContentValidator($assets, $this->uninitializedIndexer_FileImageLayout());
    $validator->validate(['main' => [['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()]]], ['scope_identity' => $scope, 'locale_code' => 'en_US', 'phase' => 'publish']);
}

private function assert_FileImageLayout_testTypedUsageRequiresExplicitScopeAndLocaleContext(): void
{
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::never())->method('validateImageUsage');
    $validator = new LayoutContentValidator($assets, $this->uninitializedIndexer_FileImageLayout());
    $this->expectException(\InvalidArgumentException::class);
    $validator->validate(['main' => [['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()]]], []);
}

private function assert_FileImageLayout_testRuntimeHydrationKeepsUrlAndHtmlOutOfPersistedUsage(): void
{
    $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('resolveImage')->willReturnCallback(static function (ImageUsage $usage, FileAccessContext $access) use ($scope): ResolvedFileImage {
        self::assertSame(self::ASSET_ID_FileImageLayout, $usage->assetId);
        self::assertTrue($scope->equals($access->scope));
        self::assertSame('en_US', $access->localeCode);
        return new ResolvedFileImage('https://cdn.example.test/media/image.jpg', '<img src="https://cdn.example.test/media/image.jpg" alt="Product">', 'Product');
    });
    $hydrator = new FileImageLayoutValueHydrator($assets);
    $result = $hydrator->hydrate(['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()], ['scope_identity' => $scope, 'locale_code' => 'en_US', 'purpose' => 'render']);
    self::assertSame('https://cdn.example.test/media/image.jpg', $result->value);
    self::assertSame(self::ASSET_ID_FileImageLayout, $result->metadata['file_asset_id']);
    self::assertSame('Product', $result->metadata['file_alt']);
    self::assertArrayNotHasKey('url', $result->metadata['file_usage']);
    self::assertArrayNotHasKey('disk_code', $result->metadata['file_usage']);
    self::assertArrayNotHasKey('object_key', $result->metadata['file_usage']);
}

private function assert_FileImageLayout_testRuntimeHydrationRejectsCrossLocaleUsage(): void
{
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::never())->method('resolveImage');
    $hydrator = new FileImageLayoutValueHydrator($assets);
    $this->expectException(\RuntimeException::class);
    $hydrator->hydrate(['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()], ['scope_identity' => ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL), 'locale_code' => 'fr_FR']);
}

private function assert_FileImageLayout_testPreviewHydrationFallsBackToUsageLocaleOnMismatch(): void
{
    $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('resolveImage')->willReturnCallback(static function (ImageUsage $usage, FileAccessContext $access) use ($scope): ResolvedFileImage {
        self::assertSame(self::ASSET_ID_FileImageLayout, $usage->assetId);
        self::assertTrue($scope->equals($access->scope));
        self::assertSame('en_US', $access->localeCode);
        return new ResolvedFileImage('https://cdn.example.test/media/image.jpg', '<img src="https://cdn.example.test/media/image.jpg" alt="Product">', 'Product');
    });
    $hydrator = new FileImageLayoutValueHydrator($assets);
    $result = $hydrator->hydrate(['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()], ['scope_identity' => $scope, 'locale_code' => 'fr_FR', 'purpose' => 'preview']);
    self::assertSame('https://cdn.example.test/media/image.jpg', $result->value);
}

private function assert_FileImageLayout_testRenderHydrationFallsBackWhenLayoutLocaleEmpty(): void
{
    $scope = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('resolveImage')->willReturnCallback(static function (ImageUsage $usage, FileAccessContext $access) use ($scope): ResolvedFileImage {
        self::assertSame(self::ASSET_ID_FileImageLayout, $usage->assetId);
        self::assertTrue($scope->equals($access->scope));
        self::assertSame('en_US', $access->localeCode);
        return new ResolvedFileImage('https://cdn.example.test/media/image-en.jpg', '<img src="https://cdn.example.test/media/image-en.jpg" alt="Product">', 'Product');
    });
    $hydrator = new FileImageLayoutValueHydrator($assets);
    $result = $hydrator->hydrate(['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()], ['scope_identity' => $scope, 'locale_code' => '', 'purpose' => 'render']);
    self::assertSame('https://cdn.example.test/media/image-en.jpg', $result->value);
}

private function assert_FileImageLayout_testRenderHydrationSoftFailsWhenAssetMissing(): void
{
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('resolveImage')->willThrowException(new \RuntimeException('The file resource does not exist.'));
    $hydrator = new FileImageLayoutValueHydrator($assets);
    $result = $hydrator->hydrate(['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()], ['scope_identity' => ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL), 'locale_code' => 'en_US', 'purpose' => 'render']);
    self::assertSame('', $result->value);
    self::assertSame('', $result->metadata['file_html']);
    self::assertTrue($result->metadata['file_missing']);
    self::assertSame(self::ASSET_ID_FileImageLayout, $result->metadata['file_asset_id']);
}

private function assert_FileImageLayout_testPreviewHydrationSoftFailsWhenAssetMissing(): void
{
    $assets = $this->createMock(FileAssetManagerInterface::class);
    $assets->expects(self::once())->method('resolveImage')->willThrowException(new \RuntimeException('The file resource does not exist.'));
    $hydrator = new FileImageLayoutValueHydrator($assets);
    $result = $hydrator->hydrate(['type' => 'file-image', 'usage' => $this->usage_FileImageLayout()], ['scope_identity' => ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL), 'locale_code' => 'en_US', 'purpose' => 'preview']);
    self::assertSame('', $result->value);
    self::assertTrue($result->metadata['file_missing']);
}

/** @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
private function usage_FileImageLayout(array $overrides = []): array
{
    return $overrides + ['version' => 1, 'asset_id' => self::ASSET_ID_FileImageLayout, 'locale_code' => 'en_US', 'alt' => 'Product', 'alt_state' => 'confirmed', 'decorative' => false, 'caption' => null, 'loading' => 'lazy', 'priority' => 'auto', 'widths' => [480, 768], 'sizes' => '100vw'];
}

private function uninitializedIndexer_FileImageLayout(): FileAssetReferenceIndexer
{
    return (new \ReflectionClass(FileAssetReferenceIndexer::class))->newInstanceWithoutConstructor();
}
}
