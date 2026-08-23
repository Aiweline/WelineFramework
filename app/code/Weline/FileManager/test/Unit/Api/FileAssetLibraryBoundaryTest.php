<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Service\FileAssetLibrary;
use Weline\FileManager\Service\FileAssetMutationService;

final class FileAssetLibraryBoundaryTest extends TestCase
{
    public function testRuntimeLoadsCurrentWorkspaceClassesInsteadOfVendorCopies(): void
    {
        $workspace = realpath(BP . '/app/code/Weline/FileManager');
        self::assertNotFalse($workspace);

        foreach ([FileAssetLibraryInterface::class, FileAssetLibrary::class] as $class) {
            $path = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($path);
            $resolved = realpath($path);
            self::assertIsString($resolved);
            self::assertStringStartsWith($workspace . DIRECTORY_SEPARATOR, $resolved);
        }
    }

    public function testPublishedBoundaryIsDataOnlyAndRegisteredWithDependencyInjection(): void
    {
        foreach ((new \ReflectionClass(FileAssetLibraryInterface::class))->getMethods() as $method) {
            $return = (string)$method->getReturnType();
            self::assertNotSame('Weline\\FileManager\\Model\\FileAsset', $return);
            self::assertNotSame('Weline\\FileManager\\Model\\FileAssetLocale', $return);
        }

        $module = require BP . '/app/code/Weline/FileManager/etc/module.php';
        self::assertSame(
            FileAssetLibrary::class,
            $module['provides'][FileAssetLibraryInterface::class] ?? null,
        );
    }

    public function testSoftDeletedPathsFailClosedAndCanBeRetriedWithFreshIdentity(): void
    {
        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );
        $upload = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetUploadService.php',
        );

        self::assertStringContainsString('findStoredByObject', $library);
        self::assertStringContainsString('if ($asset->isDeleted())', $library);
        self::assertStringContainsString('findExisting', $upload);
        self::assertStringContainsString('purgeSoftDeleted', $upload);
        self::assertStringContainsString('fresh asset ID', $upload);
        self::assertStringContainsString("if (\$remaining->getAssetId() !== '')", $upload);
    }

    public function testMovePurgesSoftDeletedDestinationIdentityInsideTheMutationTransaction(): void
    {
        $path = (new \ReflectionClass(FileAssetMutationService::class))->getFileName();
        self::assertIsString($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('findStored($canonical, $to)', $source);
        self::assertStringContainsString('purgeSoftDeleted($locked[$targetAsset->getAssetId()])', $source);
        self::assertStringContainsString('purgeSoftDeleted($locked[$staleTarget->getAssetId()])', $source);
        self::assertStringContainsString('文件移动失败且物理对象补偿失败。', $source);
        self::assertStringContainsString('$this->assertMutationAccess($targetAsset, $access)', $source);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, '$this->assertMutationAccess($targetAsset, $access)'),
        );
    }

    public function testDefaultPickerConfigurationUsesTheRegisteredMediaManagerCode(): void
    {
        $install = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Setup/Install.php',
        );

        self::assertStringContainsString("'file_manager'", $install);
        self::assertStringContainsString("'weline_media'", $install);
        self::assertStringNotContainsString("'file-manager'", $install);
        self::assertStringNotContainsString('BUILTIN_LOCAL_DISK', $install);
    }

    public function testLegacyLocalPickerConfigurationMapsDeterministicallyToMediaManager(): void
    {
        foreach (['Taglib/FileManager.php', 'Taglib/FileManagerConnector.php'] as $relativePath) {
            $source = (string)file_get_contents(BP . '/app/code/Weline/FileManager/' . $relativePath);
            self::assertStringContainsString("if (\$userConfigFileManager === 'local')", $source);
            self::assertStringContainsString("\$userConfigFileManager = 'weline_media'", $source);
            self::assertStringContainsString("isset(\$fileManagers['weline_media'])", $source);
            self::assertStringContainsString('未找到可用的文件管理器实现。', $source);
        }

        $connector = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Taglib/FileManagerConnector.php',
        );
        self::assertStringNotContainsString("\$userConfigFileManager !== 'local'", $connector);
        self::assertStringNotContainsString("count(\$fileManagers) > 1", $connector);
    }

    public function testMetadataMutationRequiresAnExplicitAccessContext(): void
    {
        $method = (new \ReflectionClass(FileAssetLibraryInterface::class))->getMethod('saveMetadata');
        $access = $method->getParameters()[4];
        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );

        self::assertSame('access', $access->getName());
        self::assertSame('Weline\\FileManager\\Api\\Data\\FileAccessContext', (string)$access->getType());
        self::assertFalse($access->allowsNull());
        self::assertFalse($access->isOptional());
        self::assertStringContainsString('FileAccessPolicyInterface $accessPolicy', $library);
        self::assertStringContainsString('$this->accessPolicy->assertCanManage($asset, $access)', $library);
        self::assertStringContainsString(
            '$current = $this->lockAssetForMetadataMutation($asset, $expectedRevision)',
            $library,
        );
        self::assertStringContainsString('$this->accessPolicy->assertCanManage($current, $access)', $library);
        self::assertStringContainsString("\$query->additional('FOR UPDATE')", $library);
        self::assertStringContainsString('schema_fields_ASSET_REVISION', $library);
    }

    public function testDescriptorsAndUploadsRequireAnExplicitAccessContext(): void
    {
        $contract = new \ReflectionClass(FileAssetLibraryInterface::class);
        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );

        foreach ([['describe', 3], ['upload', 6]] as [$methodName, $parameterIndex]) {
            $access = $contract->getMethod($methodName)->getParameters()[$parameterIndex];
            self::assertSame('access', $access->getName());
            self::assertSame('Weline\\FileManager\\Api\\Data\\FileAccessContext', (string)$access->getType());
            self::assertFalse($access->allowsNull());
            self::assertFalse($access->isOptional());
        }
        self::assertStringContainsString('$this->accessPolicy->assertCanRead($asset, $access)', $library);
        self::assertStringContainsString('$asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE', $library);
        self::assertStringContainsString('$this->accessPolicy->assertCanManage($asset, $access)', $library);
        self::assertStringContainsString('$this->describe($asset->getDiskCode(), $asset->getObjectKey(), $localeCode, $access)', $library);
        self::assertStringContainsString('return $this->describe($canonical, $objectKey, $localeCode, $access);', $library);
    }

    public function testUploadAuthorizesManagementContextBeforeStorageWrite(): void
    {
        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );
        $uploadService = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetUploadService.php',
        );
        $upload = substr($library, (int)strpos($library, 'public function upload('));
        $policyPosition = strpos($upload, '$this->assertUploadAccess($visibility, $metadata, $access)');
        $writePosition = strpos($upload, '$this->uploads->upload(');

        self::assertIsInt($policyPosition);
        self::assertIsInt($writePosition);
        self::assertTrue($policyPosition < $writePosition);
        self::assertStringContainsString('assertStalePrivateIdentityAccess', $upload);
        self::assertTrue(strpos($upload, 'assertStalePrivateIdentityAccess') < $writePosition);
        self::assertStringContainsString('$this->accessPolicy->assertCanManage($candidate, $access)', $library);
        self::assertStringContainsString(
            '$this->purgeSoftDeleted($this->lockSoftDeletedForReuse($existing))',
            $uploadService,
        );
        self::assertStringContainsString("\$query->additional('FOR UPDATE')", $uploadService);
    }

    public function testPrivateMutationsCarryAccessContextIntoTheMutationLayer(): void
    {
        $contract = new \ReflectionClass(FileAssetLibraryInterface::class);
        foreach (['moveObject', 'moveDirectory', 'deleteObject', 'deleteDirectory'] as $methodName) {
            $parameters = $contract->getMethod($methodName)->getParameters();
            $access = $parameters[array_key_last($parameters)];
            self::assertSame('access', $access->getName());
            self::assertFalse($access->allowsNull());
            self::assertFalse($access->isOptional());
        }

        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );
        $mutations = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetMutationService.php',
        );
        self::assertStringContainsString('$this->mutations->moveObject($diskCode, $from, $to, $access)', $library);
        self::assertStringContainsString('$this->mutations->deleteDirectory($diskCode, $prefix, $access)', $library);
        self::assertStringContainsString('private function assertMutationAccess(', $mutations);
        self::assertStringContainsString('$this->accessPolicy->assertCanManage($asset, $access)', $mutations);
        self::assertStringContainsString('underPrefixFromRows($this->storedOnDisk($disk->diskCode()), $prefix, true)', $mutations);
        self::assertStringContainsString('$asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE', $mutations);
    }

    public function testPickerPublishesNavigationAndPreviewAttributesAndCleansPreviewResources(): void
    {
        $tag = (string)file_get_contents(BP . '/app/code/Weline/FileManager/Taglib/FileManager.php');
        $connector = (string)file_get_contents(BP . '/app/code/Weline/FileManager/Taglib/FileManagerConnector.php');
        $source = (string)file_get_contents(BP . '/app/code/Weline/FileManager/view/statics/js/file-picker.js');
        $runtime = (string)file_get_contents(BP . '/app/code/Weline/Theme/view/statics/ui/components/weline-file-picker.js');
        $previewTemplate = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/view/blocks/image-preview/template-only-view.phtml',
        );
        $mediaPickerTemplate = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/blocks/weline-media.phtml',
        );

        foreach ([$tag, $connector] as $tagSource) {
            self::assertStringContainsString("'preview' => false", $tagSource);
        }
        self::assertStringContainsString("'lockPath' => false", $connector);
        foreach ([$tag, $connector] as $tagSource) {
            self::assertStringContainsString('FILTER_VALIDATE_BOOL', $tagSource);
            self::assertStringContainsString('FILTER_NULL_ON_FAILURE', $tagSource);
        }
        self::assertStringContainsString("\$attributes['multi'] = \$booleanAttribute", $connector);
        foreach ([$source, $runtime] as $script) {
            self::assertStringContainsString('function clearPreviewImage(previewImage)', $script);
            self::assertStringContainsString("listen(dialog, 'weline:ui:dialog:close'", $script);
            self::assertStringContainsString("previewImage.removeAttribute('src')", $script);
        }
        self::assertDoesNotMatchRegularExpression('/<img[^>]*\bsrc=""/', $previewTemplate);
        self::assertStringContainsString("data-w-file-preview<?= \$showPreview ? '' : ' hidden' ?>", $mediaPickerTemplate);
        self::assertDoesNotMatchRegularExpression('/<img[^>]*\bsrc=""/', $mediaPickerTemplate);
    }

    public function testPrivateManagementUsesAWritePurposeInsteadOfReadAuthorizationAlone(): void
    {
        $contract = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Api/FileAccessPolicyInterface.php',
        );
        $policy = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAccessPolicy.php',
        );

        self::assertStringContainsString('function assertCanManage(', $contract);
        self::assertStringContainsString("['media_manager', 'metadata_edit']", $policy);
        self::assertStringContainsString('$this->assertCanRead($asset, $context)', $policy);
        self::assertStringContainsString('if ($asset->isDeleted()', $policy);
        self::assertStringContainsString('$this->assertPrivateAccess($asset, $context)', $policy);
    }

    public function testPrivateAccessFailuresUseAPublishedExceptionType(): void
    {
        $exception = BP . '/app/code/Weline/FileManager/Api/Exception/FileAccessDeniedException.php';
        $policy = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAccessPolicy.php',
        );
        $library = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetLibrary.php',
        );

        self::assertFileExists($exception);
        self::assertStringContainsString('final class FileAccessDeniedException extends \\RuntimeException', (string)file_get_contents($exception));
        self::assertStringContainsString('use Weline\\FileManager\\Api\\Exception\\FileAccessDeniedException;', $policy);
        self::assertStringContainsString('throw new FileAccessDeniedException(', $policy);
        self::assertStringContainsString('$this->accessPolicy->assertCanManage($asset, $access)', $library);
    }

    public function testEveryPrivateUrlResolutionDisablesSharedResponseCaching(): void
    {
        $manager = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetManager.php',
        );
        $resolveUrl = substr(
            $manager,
            (int)strpos($manager, 'public function resolveUrl('),
            (int)strpos($manager, 'public function validateImageUsage(')
                - (int)strpos($manager, 'public function resolveUrl('),
        );

        self::assertStringContainsString("SharedResponseCachePolicy::forbid('private_file_asset')", $resolveUrl);
    }

    public function testMutationBoundaryRejectsStorageRootTargets(): void
    {
        $mutations = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetMutationService.php',
        );

        self::assertStringContainsString('private function requireObjectKey(string $objectKey)', $mutations);
        self::assertStringContainsString('$from = $this->requireObjectKey($from)', $mutations);
        self::assertStringContainsString('$to = $this->requireObjectKey($to)', $mutations);
        self::assertStringContainsString('$prefix = $this->requireObjectKey($prefix)', $mutations);
    }

    public function testLegacyMigrationComputesSha256FromFileContents(): void
    {
        $migration = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Setup/Db/Migration/import_legacy_local_media_20260822-v1.1.0.php',
        );

        self::assertStringContainsString("hash_init('sha256')", $migration);
        self::assertStringContainsString('hash_update($hash, $chunk)', $migration);
        self::assertStringContainsString('return hash_final($hash)', $migration);
        self::assertStringContainsString('SchedulerSystem::yield()', $migration);
        self::assertStringNotContainsString("hash_file('sha256', \$path)", $migration);
        self::assertStringNotContainsString("schema_fields_SHA256, (string)(\$stat->etag", $migration);
    }

    public function testNestedUploadRollbackReportsPhysicalCleanupFailure(): void
    {
        $upload = (string)file_get_contents(
            BP . '/app/code/Weline/FileManager/Service/FileAssetUploadService.php',
        );

        self::assertStringContainsString('file_asset_object_rollback_', $upload);
        self::assertStringContainsString('if (!$deleted && $disk->exists($stat->object->objectKey))', $upload);
        self::assertStringNotContainsString("catch (\\Throwable) {\n                        }", $upload);
    }
}
