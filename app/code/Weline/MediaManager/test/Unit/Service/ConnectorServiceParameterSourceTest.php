<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Service\ConnectorService;
use Weline\MediaManager\Service\MediaAssetUploadService;
use Weline\MediaManager\Service\MediaUploadBase64Hydrator;
use Weline\MediaManager\Service\MediaStorageService;

final class ConnectorServiceParameterSourceTest extends TestCase
{
    public function testConnectorAcceptsExplicitParametersAndContainsNoRequestOrSuperglobalFallback(): void
    {
        $method = new \ReflectionMethod(ConnectorService::class, 'execute');
        self::assertSame('array', (string)$method->getParameters()[0]->getType());
        self::assertSame('array', (string)$method->getParameters()[2]->getType());

        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        self::assertStringNotContainsString('Weline\\Framework\\Http\\Request', $source);
        self::assertStringNotContainsString('$_FILES', $source);
        self::assertStringNotContainsString('$_GET', $source);
        self::assertStringNotContainsString('$_POST', $source);
        self::assertStringNotContainsString('parseSource', $source);
        self::assertStringNotContainsString('handleUpload(', $source);
    }

    public function testHashDecoderRejectsMalformedAndNonCanonicalInput(): void
    {
        $service = new ConnectorService();
        $decode = new \ReflectionMethod($service, 'decodeHash');
        $encode = new \ReflectionMethod($service, 'encodeHash');

        $hash = $encode->invoke($service, 'catalog/demo.txt');
        self::assertSame('catalog/demo.txt', $decode->invoke($service, $hash));
        self::assertSame('', $decode->invoke($service, $encode->invoke($service, '')));
        self::assertNull($decode->invoke($service, 'catalog/demo.txt'));
        self::assertNull($decode->invoke($service, 'mm_***'));
        self::assertNull($decode->invoke($service, 'mm_'));
        self::assertNull($decode->invoke($service, $hash . '='));
        self::assertNull($decode->invoke($service, 'mm_' . str_repeat('a', 2046)));
    }

    public function testLegacyAiStorageHashDecoderAlsoFailsClosed(): void
    {
        $service = (new \ReflectionClass(MediaStorageService::class))->newInstanceWithoutConstructor();
        $hash = $service->encodeHash('catalog/demo.png');

        self::assertSame('catalog/demo.png', $service->decodeHash($hash));
        self::assertSame('', $service->decodeHash($service->encodeHash('')));
        self::assertNull($service->decodeHash('catalog/demo.png'));
        self::assertNull($service->decodeHash('mm_***'));
        self::assertNull($service->decodeHash($hash . '='));

        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/MediaStorageService.php',
        );
        self::assertStringNotContainsString("decodeHash(\$hash) ?? ''", $source);
        self::assertStringContainsString('$objectKey = $this->decodeHash($hash)', $source);
        self::assertStringContainsString('$objectKey === null', $source);
    }

    public function testMediaManagerUsesOnlyPublishedFileAssetBoundary(): void
    {
        $paths = [
            'Service/ConnectorService.php',
            'Service/MediaAssetCatalogService.php',
            'Service/MediaAssetMetadataService.php',
            'Service/MediaAssetUploadService.php',
            'Controller/Backend/Manager.php',
        ];
        $source = '';
        foreach ($paths as $path) {
            $source .= (string)file_get_contents(BP . '/app/code/Weline/MediaManager/' . $path);
        }

        self::assertStringContainsString('FileAssetLibraryInterface', $source);
        self::assertStringNotContainsString('Weline\\FileManager\\Model\\', $source);
        self::assertStringNotContainsString('Weline\\FileManager\\Service\\', $source);
        self::assertStringNotContainsString('StorageConfigRepository', $source);
    }

    public function testUploadPathPreflightsExistingAndBatchDuplicateNames(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        self::assertStringContainsString('assertUploadDestinationsAvailable', $source);
        self::assertStringContainsString('目标文件已存在：%{1}', $source);
        self::assertSame(14 * 1024 * 1024, MediaAssetUploadService::MAX_UPLOAD_BYTES);
        self::assertSame(1024 * 1024, MediaUploadBase64Hydrator::MAX_BYTES);
    }

    public function testUploadSizeUsesBytesConsistentlyAcrossControllerWorkerAndBrowser(): void
    {
        $controller = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Controller/Backend/Connector.php',
        );
        $provider = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php',
        );
        $browser = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js',
        );

        self::assertStringContainsString("'max_upload_bytes' => \$limitBytes", $controller);
        self::assertStringNotContainsString('$limitKb', $controller);
        self::assertStringContainsString("'max' => MediaUploadBase64Hydrator::MAX_BYTES", $provider);
        self::assertStringContainsString("body.append('size', String(uploadLimitBytes()))", $browser);
        self::assertStringNotContainsString('Math.floor(uploadLimitBytes() / 1024)', $browser);
    }

    public function testStorageListingDoesNotDependOnTheDefaultDisk(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $execute = substr(
            $source,
            (int)strpos($source, 'public function execute('),
            (int)strpos($source, 'private function handleStorages(')
                - (int)strpos($source, 'public function execute('),
        );
        $storagesPosition = strpos($execute, "if (\$cmd === 'storages')");
        $defaultDiskPosition = strpos($execute, 'defaultDisk()');
        self::assertIsInt($storagesPosition);
        self::assertIsInt($defaultDiskPosition);
        self::assertTrue(
            $storagesPosition < $defaultDiskPosition,
            'The storage catalog must remain available when the configured default disk is broken.',
        );
    }

    public function testLegacyPointerDownloadUsesSafeTemporaryFilesAndRejectsTruncatedCopies(): void
    {
        $controller = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Controller/Backend/Connector.php',
        );

        self::assertStringContainsString(
            '$this->resourceFactory->temporaryFile(\sys_get_temp_dir(), \'mmf_\')',
            $controller,
        );
        self::assertStringContainsString(
            'StorageRequestStreamInterface::KIND_PROXY_FILE',
            $controller,
        );
        self::assertStringNotContainsString("uniqid('', true)", $controller);
        self::assertStringContainsString('$copyFailed = true;', $controller);
        self::assertStringContainsString('下载临时文件写入不完整。', $controller);
        self::assertStringContainsString('连接器响应编码失败', $controller);

        $service = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        self::assertStringContainsString("filter_var(\$src['download'] ?? false, FILTER_VALIDATE_BOOL)", $service);
        self::assertStringContainsString("'force_download' => true", $service);
        self::assertStringContainsString("->openRead(\$path)", $service);
        self::assertStringContainsString("'resource_handle' => \$handle", $service);
        self::assertStringContainsString("'Content-Disposition', 'attachment; filename=\"'", $controller);
        self::assertStringContainsString("'X-Content-Type-Options', 'nosniff'", $controller);
        self::assertStringContainsString("preg_replace('/[\\x00-\\x1F\\x7F\"", $controller);
    }

    public function testWorkerActorIdentityIsPassedExplicitlyWithoutRequestState(): void
    {
        $provider = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php',
        );
        $connector = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString("'actor_id' => \$backendUserId", $provider);
        self::assertStringNotContainsString('ConnectorOptionsBuilder', $provider);
        self::assertStringContainsString("\$opts['actor_id']", $connector);
        self::assertStringContainsString('fileAccessContext($locale, $actorId)', $connector);
    }

    public function testBatchMoveReportsFailedCompensationInsteadOfHidingPartialState(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString('$rollbackFailed = false;', $source);
        self::assertStringContainsString('部分文件无法自动恢复，请立即刷新并人工处理', $source);
    }

    public function testUploadResolvesAccessBeforeWriteAndRollsBackFailedDescriptorEnrichment(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $uploadMethod = substr($source, (int)strpos($source, 'private function handleStorageUpload'));
        $accessPosition = strpos($uploadMethod, '$access = $this->fileAccessContext');
        $writePosition = strpos($uploadMethod, '->uploadFiles(');

        self::assertIsInt($accessPosition);
        self::assertIsInt($writePosition);
        self::assertTrue($accessPosition < $writePosition);
        self::assertStringContainsString('array_reverse($added)', $uploadMethod);
        self::assertStringContainsString('上传完成后读取资源详情失败', $uploadMethod);
    }

    public function testAiEditingCapabilityRemainsLocalMediaOnly(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString("\$normalized['ai_edit'] = \$storage === self::DEFAULT_DISK", $source);
        self::assertStringContainsString('normalizeStorageCapabilities($manager->capabilities($storage), $storage)', $source);
    }

    public function testStorageCatalogFailureNeverSynthesizesALocalFallback(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $method = substr(
            $source,
            (int)strpos($source, 'private function handleStorages'),
            (int)strpos($source, 'private function normalizeStorageCapabilities')
                - (int)strpos($source, 'private function handleStorages'),
        );

        self::assertStringContainsString('存储源清单不可用', $method);
        self::assertStringContainsString('存储源清单读取失败', $method);
        self::assertStringNotContainsString("'name' => self::DEFAULT_DISK", $method);
    }

    public function testEveryStorageOperationEnforcesItsAdvertisedCapabilityServerSide(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString('private function assertStorageCapability(', $source);
        foreach ([
            "assertStorageCapability(\$storage, 'browse'",
            "assertStorageCapability(\$storage, 'create_directory'",
            "assertStorageCapability(\$storage, 'rename_directory'",
            "assertStorageCapability(\$storage, 'rename_file'",
            "assertStorageCapability(\$storage, 'move_file'",
            "assertStorageCapability(\$storage, 'delete_directory'",
            "assertStorageCapability(\$storage, 'delete_file'",
            "assertStorageCapability(\$storage, 'upload'",
            "assertStorageCapability(\$storage, 'download'",
            "assertStorageCapability(\$storage, 'preview'",
        ] as $expected) {
            self::assertStringContainsString($expected, $source);
        }
    }

    public function testResourceResolutionRejectsMissingFilesAndDirectoriesBeforeUrlGeneration(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $resource = substr(
            $source,
            (int)strpos($source, 'private function handleStorageResource('),
            (int)strpos($source, 'private function fileAccessContext(')
                - (int)strpos($source, 'private function handleStorageResource('),
        );

        self::assertStringContainsString('$entry = $this->findStorageEntry($storage, $path, $manager)', $resource);
        self::assertStringContainsString("(\$entry['type'] ?? '') === 'directory'", $resource);
        self::assertStringContainsString('目标文件不存在', $resource);
    }

    public function testStorageMimeValuesCannotInjectDownloadHeaders(): void
    {
        $service = new ConnectorService();
        $normalize = new \ReflectionMethod($service, 'normalizeStorageMime');

        self::assertSame('image/png', $normalize->invoke($service, ' IMAGE/PNG ', 'demo.png'));
        self::assertSame(
            'application/octet-stream',
            $normalize->invoke($service, "text/plain\r\nX-Injected: yes", 'demo.bin'),
        );
    }

    public function testDirectoryListingHidesPrivateAssetsRejectedByTheAccessPolicy(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $open = substr(
            $source,
            (int)strpos($source, 'private function handleStorageOpen('),
            (int)strpos($source, 'private function handleStorageResource(')
                - (int)strpos($source, 'private function handleStorageOpen('),
        );

        self::assertStringContainsString('use Weline\\FileManager\\Api\\Exception\\FileAccessDeniedException;', $source);
        self::assertStringContainsString('catch (FileAccessDeniedException)', $open);
        self::assertStringContainsString('continue;', $open);
        self::assertStringNotContainsString('catch (\\Throwable)', $open);
        self::assertStringNotContainsString("'asset_id' => null", $open);
    }

    public function testPrivateUploadsCarryTheAuthenticatedActorPolicy(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString("'access_policy'", $source);
        self::assertStringContainsString("'owner_actor_id' => \$actorId", $source);
        self::assertSame(1, substr_count($source, "'owner_actor_id' => \$actorId"));
        self::assertStringContainsString("'policy_revision' => 1", $source);
        self::assertStringContainsString('私有文件上传必须具有明确操作人。', $source);
        self::assertStringContainsString('defaultStorageVisibility($storage)', $source);
        self::assertMatchesRegularExpression(
            '/->uploadFiles\([\s\S]*\$locale,\s*\$access,\s*\$metadata,\s*\$visibility,[\s\S]*\$assetMetadata,\s*\);/',
            $source,
        );
    }

    public function testMetadataEditsRequireTheClientObservedAssetRevision(): void
    {
        $connector = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $provider = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php',
        );
        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );

        self::assertStringContainsString("['name' => 'asset_revision', 'type' => 'int'", $provider);
        self::assertStringContainsString("\$src['asset_revision'] ?? null", $connector);
        self::assertStringContainsString('$expectedRevision !== (int)$current->getData', $library);
    }

    public function testBatchDeletePreflightsCapabilitiesAndReportsPartialCompletion(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );
        $remove = substr($source, (int)strpos($source, 'private function handleStorageRemove'));

        self::assertStringContainsString('$requiresDirectoryDelete', $remove);
        self::assertStringContainsString('$requiresFileDelete', $remove);
        self::assertStringContainsString('批量删除未全部完成，部分项目已删除，请立即刷新并人工核对。', $remove);
    }

    public function testStorageMutationsPropagateTheFrozenActorAccessContext(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString('$this->getAssetLibrary()->moveObject($storage, $from, $to, $access)', $source);
        self::assertStringContainsString('$this->getAssetLibrary()->moveDirectory($storage, $from, $to, $access)', $source);
        self::assertStringContainsString('$this->getAssetLibrary()->deleteObject($storage, $item[\'path\'], $access)', $source);
        self::assertStringContainsString('$this->getAssetLibrary()->deleteDirectory($storage, $item[\'path\'], $access)', $source);
    }

    public function testMoveAndDeleteRejectUnboundedOrAssociativeTargetCollections(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php',
        );

        self::assertStringContainsString('private function requiredTargetHashes(', $source);
        self::assertStringContainsString('count($targets) > MediaAssetUploadService::MAX_UPLOAD_FILES', $source);
        self::assertStringContainsString('!array_is_list($targets)', $source);
        self::assertStringContainsString('$targets = $this->requiredTargetHashes(', $source);

        $requiredTargets = new \ReflectionMethod(new ConnectorService(), 'requiredTargetHashes');
        $this->expectException(\InvalidArgumentException::class);
        $requiredTargets->invoke(new ConnectorService(), ['mm_' . str_repeat('a', 2046)], 'empty');
    }
}
