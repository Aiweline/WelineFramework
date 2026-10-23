<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DEV', false);
define('PROD', true);
require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Ai\Setup\Release\ProviderUsageAuditTransform;
use Weline\Framework\Manager\ObjectManager;
use Weline\MediaManager\Setup\Release\BindAiDrawModelTransform;
use Weline\Cms\Setup\Release\CmsPagePathGroupTransform;
use Weline\Cms\Setup\Release\CmsPageStoreLocaleTransform;
use Weline\FileManager\Setup\Release\FileAssetHashTransform;
use Weline\FileManager\Setup\Release\LegacyLocalMediaImportTransform;
use Weline\Seo\Setup\Release\EnableCronSitemapDefaultTransform;
use Weline\Storage\Setup\Release\CanonicalizeStorageDiskCodesTransform;
use Weline\Websites\Setup\Release\EnsureLocalRegistrarTransform;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

foreach ([
    ProviderUsageAuditTransform::class => 'ai.provider_usage_audit_identity',
    BindAiDrawModelTransform::class => 'media_manager.bind_ai_draw_model',
    EnsureLocalRegistrarTransform::class => 'websites.ensure_local_registrar',
    EnableCronSitemapDefaultTransform::class => 'seo.enable_cron_sitemap_default',
    FileAssetHashTransform::class => 'file_manager.file_asset_hash',
    LegacyLocalMediaImportTransform::class => 'file_manager.import_legacy_local_media',
] as $class => $id) {
    $check(class_exists($class), 'missing ' . $class);
    if (!class_exists($class)) {
        continue;
    }
    try {
        $transform = ObjectManager::make($class);
    } catch (Throwable $exception) {
        $failures[] = $class . ' instantiation failed: ' . $exception->getMessage();
        continue;
    }
    $check($transform->id() === $id, $class . ' id mismatch');
    $check($transform->requiresSnapshot() === true, $class . ' must require snapshot');
    $check($transform->inverse() === null, $class . ' inverse must be null');
    $check($transform->scope()->key() !== '', $class . ' scope key must not be empty');
    $check(($transform->plan()['strategy'] ?? '') === 'service-backfill', $class . ' plan strategy');
    $check(!str_contains($class, 'Migration'), 'transform must not be a legacy migration class');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T46D TRANSFORMS PASS' . PHP_EOL;
