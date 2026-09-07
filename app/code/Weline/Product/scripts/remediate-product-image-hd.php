<?php

declare(strict_types=1);

/**
 * Remediate blurry / low-resolution product images.
 *
 * Prefer CDN originals (strip alicdn resize suffixes like .jpg_b.jpg). When no
 * larger source exists, optionally remaster with ffmpeg lanczos upscale.
 * After Media references move to the new FileAsset, delete the old object and
 * purge FileAsset + FileAssetLocale metadata rows.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-product-image-hd.php --scan [--website=0] [--object-key-contains=...] [--force-min-edge=N]
 *   php app/code/Weline/Product/scripts/remediate-product-image-hd.php --dry-run [--website=0] [--include-soft] [--upscale-fallback] [--realesrgan] [--force-min-edge=N] [--object-key-contains=...]
 *   php app/code/Weline/Product/scripts/remediate-product-image-hd.php --apply [--website=0] [--include-soft] [--upscale-fallback] [--realesrgan] [--force-min-edge=N] [--object-key-contains=...]
 *   php app/code/Weline/Product/scripts/remediate-product-image-hd.php --verify [--website=0] [--object-key-contains=...] [--force-min-edge=N]
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Sample\Hanfu1688\PublicHttpClient;
use Weline\Product\Service\ProductShardProvisioner;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$allowedModes = ['--scan', '--dry-run', '--apply', '--verify'];
$modes = array_values(array_intersect($argv, $allowedModes));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --scan, --dry-run, --apply, or --verify.\n");
    exit(2);
}
$mode = $modes[0];
$websiteId = 0;
$includeSoft = in_array('--include-soft', $argv, true);
$upscaleFallback = in_array('--upscale-fallback', $argv, true);
$useRealEsrgan = in_array('--realesrgan', $argv, true);
$restoreSource = in_array('--restore-source', $argv, true);
$objectKeyContains = '';
$forceMinEdge = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--website=')) {
        $websiteId = max(0, (int)substr($argument, strlen('--website=')));
    }
    if (str_starts_with($argument, '--object-key-contains=')) {
        $objectKeyContains = trim((string)substr($argument, strlen('--object-key-contains=')));
    }
    if (str_starts_with($argument, '--force-min-edge=')) {
        $forceMinEdge = max(0, (int)substr($argument, strlen('--force-min-edge=')));
    }
}
if ($restoreSource && $forceMinEdge < 1) {
    // Restore mode replaces every matched asset that still has a source_url.
    $forceMinEdge = 100000;
}

const LOW_RES_MIN = 600;
const SOFT_LAP_MAX = 350.0;
const TARGET_MIN_EDGE = 1200;

$root = dirname(__DIR__, 5);
$artifactDir = $root . '/var/tmp/product-image-hd-remediation';
if (!is_dir($artifactDir) && !mkdir($artifactDir, 0775, true) && !is_dir($artifactDir)) {
    throw new RuntimeException('Unable to create artifact directory: ' . $artifactDir);
}

ObjectManager::getInstance(ProductShardProvisioner::class)->provisionWebsite($websiteId);
/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var MediaRepository $mediaRepo */
$mediaRepo = ObjectManager::getInstance(MediaRepository::class);
/** @var FileAssetLibraryInterface $library */
$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
/** @var PublicHttpClient $http */
$http = ObjectManager::getInstance(PublicHttpClient::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$access = new FileAccessContext(
    ScopeIdentity::global(),
    'zh_Hans_CN',
    null,
    ['catalog_maintenance'],
    'metadata_edit',
);
$diskCode = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

/**
 * @return list<array<string,mixed>>
 */
function productImageHdCollectAssets(int $websiteId, ProductRepository $products, MediaRepository $mediaRepo): array
{
    $productRows = $products->listAll($websiteId);
    $productIds = [];
    foreach ($productRows as $row) {
        $productIds[] = (int)($row['product_id'] ?? 0);
    }
    $productIds = array_values(array_filter($productIds, static fn (int $id): bool => $id > 0));
    $mediaRows = $mediaRepo->listByProductIds($websiteId, $productIds);
    $refsByAsset = [];
    foreach ($mediaRows as $row) {
        $assetId = strtolower(trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')));
        if ($assetId === '') {
            continue;
        }
        $refsByAsset[$assetId][] = [
            'product_id' => (int)($row[Media::schema_fields_PRODUCT_ID] ?? 0),
            'media_id' => (int)($row[Media::schema_fields_ID] ?? 0),
            'role' => (string)($row[Media::schema_fields_ROLE] ?? ''),
            'combination_key' => (string)($row[Media::schema_fields_COMBINATION_KEY] ?? ''),
            'position' => (int)($row[Media::schema_fields_POSITION] ?? 0),
            'store_id' => (int)($row[Media::schema_fields_STORE_ID] ?? 0),
            'hidden' => (int)($row[Media::schema_fields_HIDDEN] ?? 0),
            'asset_visibility' => (string)($row[Media::schema_fields_ASSET_VISIBILITY] ?? 'public'),
            'mime_type' => (string)($row[Media::schema_fields_MIME_TYPE] ?? ''),
            'access_policy_json' => $row[Media::schema_fields_ACCESS_POLICY_JSON] ?? null,
        ];
    }

    $model = ObjectManager::getInstance(FileAsset::class);
    $all = $model->clear()->select()->fetchArray();
    $out = [];
    foreach ($all as $asset) {
        $assetId = strtolower(trim((string)($asset[FileAsset::schema_fields_ID] ?? '')));
        if ($assetId === '' || !isset($refsByAsset[$assetId])) {
            continue;
        }
        if (trim((string)($asset[FileAsset::schema_fields_DELETED_AT] ?? '')) !== '') {
            continue;
        }
        $meta = json_decode((string)($asset[FileAsset::schema_fields_METADATA] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $out[] = [
            'asset_id' => $assetId,
            'disk_code' => (string)($asset[FileAsset::schema_fields_DISK_CODE] ?? ''),
            'object_key' => (string)($asset[FileAsset::schema_fields_OBJECT_KEY] ?? ''),
            'original_name' => (string)($asset[FileAsset::schema_fields_ORIGINAL_NAME] ?? ''),
            'mime_type' => (string)($asset[FileAsset::schema_fields_MIME_TYPE] ?? ''),
            'width' => (int)($asset[FileAsset::schema_fields_WIDTH] ?? 0),
            'height' => (int)($asset[FileAsset::schema_fields_HEIGHT] ?? 0),
            'bytes' => (int)($asset[FileAsset::schema_fields_BYTES] ?? 0),
            'sha256' => strtolower(trim((string)($asset[FileAsset::schema_fields_SHA256] ?? ''))),
            'asset_revision' => (int)($asset[FileAsset::schema_fields_ASSET_REVISION] ?? 0),
            'source_url' => trim((string)($meta['source_url'] ?? '')),
            'metadata' => $meta,
            'refs' => $refsByAsset[$assetId],
        ];
    }

    return $out;
}

function productImageHdLapVar(string $absolutePath): float
{
    if (!is_file($absolutePath)) {
        return -1.0;
    }
    $raw = @file_get_contents($absolutePath);
    if ($raw === false || $raw === '') {
        return -1.0;
    }
    $image = @imagecreatefromstring($raw);
    if ($image === false) {
        return -1.0;
    }
    $srcW = imagesx($image);
    $srcH = imagesy($image);
    if ($srcW < 3 || $srcH < 3) {
        imagedestroy($image);
        return 0.0;
    }
    $maxEdge = 384;
    $scale = min(1.0, $maxEdge / max($srcW, $srcH));
    $w = max(3, (int)round($srcW * $scale));
    $h = max(3, (int)round($srcH * $scale));
    $small = imagecreatetruecolor($w, $h);
    if ($small === false) {
        imagedestroy($image);
        return -1.0;
    }
    imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
    imagedestroy($image);

    $gray = [];
    for ($y = 0; $y < $h; $y++) {
        $row = [];
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $row[] = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
        }
        $gray[] = $row;
    }
    imagedestroy($small);

    $values = [];
    for ($y = 1; $y < $h - 1; $y++) {
        for ($x = 1; $x < $w - 1; $x++) {
            $lap = $gray[$y - 1][$x]
                + $gray[$y + 1][$x]
                + $gray[$y][$x - 1]
                + $gray[$y][$x + 1]
                - (4.0 * $gray[$y][$x]);
            $values[] = $lap;
        }
    }
    $n = count($values);
    if ($n < 1) {
        return 0.0;
    }
    $mean = array_sum($values) / $n;
    $acc = 0.0;
    foreach ($values as $v) {
        $d = $v - $mean;
        $acc += $d * $d;
    }

    return $acc / $n;
}

/**
 * @return list<string>
 */
function productImageHdUpgradeUrls(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return [];
    }
    $candidates = [];
    if (preg_match('#^(https?://.+\.(?:jpe?g|png|webp))_[A-Za-z0-9]+\.(?:jpe?g|png|webp)$#i', $url, $m) === 1) {
        $candidates[] = $m[1];
    }
    if (preg_match('#^(https?://.+\.(?:jpe?g|png|webp))_[0-9]+x[0-9]+\.(?:jpe?g|png|webp)$#i', $url, $m) === 1) {
        $candidates[] = $m[1];
    }
    $candidates[] = $url;
    $unique = [];
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && !isset($unique[$candidate])) {
            $unique[$candidate] = true;
        }
    }

    return array_keys($unique);
}

/**
 * @return array{bytes:string,width:int,height:int,mime:string,url:string}|null
 */
function productImageHdBestRemote(PublicHttpClient $http, string $sourceUrl, int $currentMin): ?array
{
    $best = null;
    foreach (productImageHdUpgradeUrls($sourceUrl) as $url) {
        try {
            $bytes = $http->getMedia($url);
        } catch (Throwable) {
            continue;
        }
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
            continue;
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        $mime = strtolower(trim((string)($info['mime'] ?? '')));
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            continue;
        }
        $min = min($width, $height);
        $score = ($min * 1_000_000) + strlen($bytes);
        if ($best === null || $score > $best['score']) {
            $best = [
                'bytes' => $bytes,
                'width' => $width,
                'height' => $height,
                'mime' => $mime,
                'url' => $url,
                'score' => $score,
                'min' => $min,
            ];
        }
    }
    if ($best === null) {
        return null;
    }
    if ($best['min'] < max((int)ceil($currentMin * 1.5), LOW_RES_MIN) && $best['min'] <= $currentMin) {
        return null;
    }
    unset($best['score'], $best['min']);

    return $best;
}

/**
 * @return array{bytes:string,width:int,height:int,mime:string}|null
 */
function productImageHdRealEsrganUpscale(string $absolutePath): ?array
{
    if (!is_file($absolutePath)) {
        return null;
    }
    $bin = productImageHdRealEsrganBinary();
    if ($bin === null) {
        return null;
    }
    $info = @getimagesize($absolutePath);
    if (!is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
        return null;
    }
    $min = min((int)$info[0], (int)$info[1]);
    $scale = $min < 500 ? 4 : 2;
    $modelDir = dirname($bin) . '/models';
    if (!is_dir($modelDir)) {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pihd_re_');
    if ($tmp === false) {
        return null;
    }
    $outFile = $tmp . '.jpg';
    @unlink($tmp);
    $cmd = sprintf(
        '%s -i %s -o %s -n realesrgan-x4plus -s %d -m %s -t 400 -f jpg 2>&1',
        escapeshellcmd($bin),
        escapeshellarg($absolutePath),
        escapeshellarg($outFile),
        $scale,
        escapeshellarg($modelDir),
    );
    exec($cmd, $output, $code);
    if ($code !== 0 || !is_file($outFile)) {
        @unlink($outFile);
        return null;
    }
    $bytes = (string)file_get_contents($outFile);
    @unlink($outFile);
    if ($bytes === '') {
        return null;
    }
    // realesrgan may emit png bytes even when -f jpg; normalize via getimagesize.
    $outInfo = @getimagesizefromstring($bytes);
    if (!is_array($outInfo) || (int)($outInfo[0] ?? 0) < 1) {
        return null;
    }
    $mime = strtolower(trim((string)($outInfo['mime'] ?? 'image/jpeg')));
    if ($mime === 'image/png') {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        ob_start();
        imagejpeg($image, null, 92);
        imagedestroy($image);
        $jpeg = (string)ob_get_clean();
        if ($jpeg === '') {
            return null;
        }
        $bytes = $jpeg;
        $mime = 'image/jpeg';
        $outInfo = @getimagesizefromstring($bytes);
        if (!is_array($outInfo)) {
            return null;
        }
    }

    return [
        'bytes' => $bytes,
        'width' => (int)$outInfo[0],
        'height' => (int)$outInfo[1],
        'mime' => $mime === 'image/jpeg' ? 'image/jpeg' : $mime,
    ];
}

function productImageHdRealEsrganBinary(): ?string
{
    static $resolved = false;
    static $path = null;
    if ($resolved) {
        return $path;
    }
    $resolved = true;
    $candidates = [];
    $env = trim((string)(getenv('WELINE_REALESRGAN_BIN') ?: ''));
    if ($env !== '') {
        $candidates[] = $env;
    }
    $root = dirname(__DIR__, 5);
    $candidates[] = $root . '/var/tmp/realesrgan/realesrgan-ncnn-vulkan';
    $which = trim((string)shell_exec('command -v realesrgan-ncnn-vulkan'));
    if ($which !== '') {
        $candidates[] = $which;
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $path = $candidate;

            return $path;
        }
    }

    return null;
}

/**
 * @return array{bytes:string,width:int,height:int,mime:string}|null
 */
function productImageHdUpscaleLocal(string $absolutePath, int $targetMinEdge): ?array
{
    if (!is_file($absolutePath)) {
        return null;
    }
    $ffmpeg = trim((string)shell_exec('command -v ffmpeg'));
    if ($ffmpeg === '') {
        return null;
    }
    $info = @getimagesize($absolutePath);
    if (!is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
        return null;
    }
    $width = (int)$info[0];
    $height = (int)$info[1];
    $min = min($width, $height);
    if ($min < 1) {
        return null;
    }
    $scale = max(2.0, $targetMinEdge / $min);
    $outW = (int)round($width * $scale);
    $outH = (int)round($height * $scale);
    $tmp = tempnam(sys_get_temp_dir(), 'pihd_');
    if ($tmp === false) {
        return null;
    }
    $outFile = $tmp . '.jpg';
    @unlink($tmp);
    $cmd = sprintf(
        '%s -y -i %s -vf %s -q:v 2 %s 2>/dev/null',
        escapeshellcmd($ffmpeg),
        escapeshellarg($absolutePath),
        escapeshellarg(sprintf('scale=%d:%d:flags=lanczos,unsharp=5:5:0.8:5:5:0.0', $outW, $outH)),
        escapeshellarg($outFile),
    );
    exec($cmd, $output, $code);
    if ($code !== 0 || !is_file($outFile)) {
        @unlink($outFile);
        return null;
    }
    $bytes = (string)file_get_contents($outFile);
    @unlink($outFile);
    $outInfo = @getimagesizefromstring($bytes);
    if (!is_array($outInfo) || (int)($outInfo[0] ?? 0) < 1) {
        return null;
    }

    return [
        'bytes' => $bytes,
        'width' => (int)$outInfo[0],
        'height' => (int)$outInfo[1],
        'mime' => 'image/jpeg',
    ];
}

/**
 * @param array<string,mixed> $asset
 * @return array{reason:string,lap:float}|null
 */
function productImageHdClassify(array $asset, string $mediaRoot, bool $includeSoft): ?array
{
    $width = (int)$asset['width'];
    $height = (int)$asset['height'];
    $absolute = rtrim($mediaRoot, '/') . '/' . ltrim((string)$asset['object_key'], '/');
    $lap = productImageHdLapVar($absolute);
    if ($width <= 1 || $height <= 1) {
        return ['reason' => 'degenerate', 'lap' => $lap];
    }
    if (min($width, $height) < LOW_RES_MIN) {
        return ['reason' => 'low_res', 'lap' => $lap];
    }
    if ($includeSoft && $lap >= 0.0 && $lap < SOFT_LAP_MAX) {
        return ['reason' => 'soft', 'lap' => $lap];
    }

    return null;
}

function productImageHdExtension(string $mime): string
{
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => throw new RuntimeException('unsupported_mime'),
    };
}

/**
 * @param array<string,mixed> $old
 * @param array{bytes:string,width:int,height:int,mime:string,url?:string,method:string} $payload
 * @return array<string,mixed>
 */
function productImageHdUploadReplacement(
    FileAssetLibraryInterface $library,
    FileAccessContext $access,
    string $diskCode,
    array $old,
    array $payload,
): array {
    $sha = hash('sha256', $payload['bytes']);
    $ext = productImageHdExtension($payload['mime']);
    $dir = trim((string)dirname((string)$old['object_key']), '.');
    $base = pathinfo((string)$old['object_key'], PATHINFO_FILENAME);
    $base = preg_replace('/(?:-hd)+-[a-f0-9]{12}$/', '', $base) ?? $base;
    $base = preg_replace('/-[a-f0-9]{12}$/', '', $base) ?? $base;
    $objectKey = ($dir !== '' ? $dir . '/' : '')
        . $base
        . ((string)($payload['method'] ?? '') === 'restore_source' ? '-' : '-hd-')
        . substr($sha, 0, 12)
        . '.'
        . $ext;

    $localeMeta = [
        'display_name' => '',
        'default_alt' => '',
        'description' => '',
        'default_caption' => '',
        'translation_state' => FileAssetLibraryInterface::TRANSLATION_REVIEWED,
        'translation_origin' => FileAssetLibraryInterface::TRANSLATION_MANUAL,
    ];
    foreach (['zh_Hans_CN', 'en_US'] as $locale) {
        $described = $library->describe($diskCode, (string)$old['object_key'], $locale, $access);
        if (!empty($described['display_name'])) {
            $localeMeta['display_name'] = (string)$described['display_name'];
            $localeMeta['default_alt'] = (string)($described['default_alt'] ?? $described['display_name']);
            $localeMeta['description'] = (string)($described['description'] ?? '');
            $localeMeta['default_caption'] = (string)($described['default_caption'] ?? '');
            break;
        }
    }
    $fallbackName = trim((string)$old['original_name']);
    if ($fallbackName === '') {
        $fallbackName = basename((string)$old['object_key']);
    }
    if ($localeMeta['display_name'] === '') {
        $localeMeta['display_name'] = $fallbackName;
    }
    if ($localeMeta['default_alt'] === '') {
        $localeMeta['default_alt'] = $localeMeta['display_name'];
    }
    if ($localeMeta['description'] === '') {
        $localeMeta['description'] = '产品图高清替换：' . $localeMeta['display_name'];
    }

    $existing = $library->describe($diskCode, $objectKey, 'zh_Hans_CN', $access);
    if (!empty($existing['asset_id'])) {
        if (!hash_equals(strtolower((string)($existing['sha256'] ?? '')), $sha)) {
            throw new RuntimeException('hd_object_key_collision: ' . $objectKey);
        }
        return $existing;
    }

    $stream = fopen('php://temp', 'w+b');
    if (!is_resource($stream)) {
        throw new RuntimeException('hd_stream_failed');
    }
    try {
        if (fwrite($stream, $payload['bytes']) !== strlen($payload['bytes']) || !rewind($stream)) {
            throw new RuntimeException('hd_stream_failed');
        }
        $meta = is_array($old['metadata'] ?? null) ? $old['metadata'] : [];
        $meta['source_url'] = (string)($payload['url'] ?? ($meta['source_url'] ?? ''));
        $meta['replaced_from_asset_id'] = (string)$old['asset_id'];
        $meta['remediation'] = [
            'contract' => 'product.image.hd.remediation.v1',
            'method' => (string)$payload['method'],
            'replaced_at' => gmdate('c'),
            'previous_sha256' => (string)$old['sha256'],
            'previous_size' => [(int)$old['width'], (int)$old['height']],
        ];
        $meta['sha256'] = $sha;

        return $library->upload(
            $diskCode,
            $objectKey,
            $stream,
            basename($objectKey),
            $payload['mime'],
            'zh_Hans_CN',
            $access,
            $localeMeta,
            FileAssetLibraryInterface::VISIBILITY_PUBLIC,
            $meta,
            (int)$payload['width'],
            (int)$payload['height'],
        );
    } finally {
        fclose($stream);
    }
}

function productImageHdPurgeAssetMetadata(string $assetId): void
{
    $assetId = strtolower(trim($assetId));
    if ($assetId === '') {
        return;
    }
    $locales = ObjectManager::getInstance(FileAssetLocale::class);
    (clone $locales)->clearData()->reset()
        ->where(FileAssetLocale::schema_fields_ASSET_ID, $assetId)
        ->delete()->fetch();
    $assets = ObjectManager::getInstance(FileAsset::class);
    (clone $assets)->clearData()->reset()
        ->where(FileAsset::schema_fields_ID, $assetId)
        ->delete()->fetch();
}

$mediaRoot = $root . '/pub/media';
$assets = productImageHdCollectAssets($websiteId, $products, $mediaRepo);

$candidates = [];
foreach ($assets as $asset) {
    $objectKey = (string)($asset['object_key'] ?? '');
    if ($objectKeyContains !== '' && !str_contains($objectKey, $objectKeyContains)) {
        continue;
    }
    // Spec / gallery high-clarity pass skips long-form detail body images.
    if ($forceMinEdge > 0 && str_contains($objectKey, '/detail-')) {
        continue;
    }
    $classified = productImageHdClassify($asset, $mediaRoot, $includeSoft || $mode === '--scan');
    if ($classified === null && $forceMinEdge > 0) {
        $minEdge = min((int)$asset['width'], (int)$asset['height']);
        if ($minEdge > 0 && $minEdge < $forceMinEdge) {
            $absolute = rtrim($mediaRoot, '/') . '/' . ltrim($objectKey, '/');
            $classified = [
                'reason' => 'force_below',
                'lap' => productImageHdLapVar($absolute),
            ];
        }
    }
    if ($classified === null) {
        continue;
    }
    $candidates[] = $asset + $classified;
}

$scanPayload = [
    'ok' => true,
    'mode' => ltrim($mode, '-'),
    'website_id' => $websiteId,
    'product_assets' => count($assets),
    'candidates' => count($candidates),
    'low_res' => count(array_filter($candidates, static fn (array $c): bool => $c['reason'] === 'low_res' || $c['reason'] === 'degenerate')),
    'soft' => count(array_filter($candidates, static fn (array $c): bool => $c['reason'] === 'soft')),
    'items' => array_map(static function (array $c): array {
        return [
            'asset_id' => $c['asset_id'],
            'object_key' => $c['object_key'],
            'width' => $c['width'],
            'height' => $c['height'],
            'reason' => $c['reason'],
            'lap' => round((float)$c['lap'], 2),
            'source_url' => $c['source_url'],
            'ref_count' => count($c['refs']),
            'roles' => array_values(array_unique(array_map(static fn (array $r): string => (string)$r['role'], $c['refs']))),
        ];
    }, $candidates),
];
file_put_contents(
    $artifactDir . '/scan.json',
    json_encode($scanPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
);

if ($mode === '--scan') {
    echo json_encode($scanPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

if ($mode === '--verify') {
    $remaining = [];
    foreach (productImageHdCollectAssets($websiteId, $products, $mediaRepo) as $asset) {
        $classified = productImageHdClassify($asset, $mediaRoot, false);
        if ($classified !== null && in_array($classified['reason'], ['low_res', 'degenerate'], true)) {
            // Keep soft out of hard verify gate unless include-soft.
            if (!$includeSoft || $classified['reason'] !== 'soft') {
                $remaining[] = $asset['object_key'];
            }
        }
    }
    $payload = [
        'ok' => $remaining === [],
        'mode' => 'verify',
        'website_id' => $websiteId,
        'remaining_low_res' => count($remaining),
        'sample' => array_slice($remaining, 0, 20),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit($remaining === [] ? 0 : 1);
}

$workList = [];
foreach ($candidates as $candidate) {
    if ($candidate['reason'] === 'soft' && !$includeSoft) {
        continue;
    }
    $absolute = $mediaRoot . '/' . ltrim((string)$candidate['object_key'], '/');
    $currentMin = min((int)$candidate['width'], (int)$candidate['height']);
    $payload = null;
    $method = '';
    if ($restoreSource && $candidate['source_url'] !== '') {
        foreach (productImageHdUpgradeUrls((string)$candidate['source_url']) as $remoteUrl) {
            try {
                $remoteBytes = $http->getMedia($remoteUrl);
            } catch (Throwable) {
                continue;
            }
            $remoteInfo = @getimagesizefromstring($remoteBytes);
            if (!is_array($remoteInfo) || (int)($remoteInfo[0] ?? 0) < 1) {
                continue;
            }
            $mime = strtolower(trim((string)($remoteInfo['mime'] ?? '')));
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                continue;
            }
            $payload = [
                'bytes' => $remoteBytes,
                'width' => (int)$remoteInfo[0],
                'height' => (int)$remoteInfo[1],
                'mime' => $mime,
                'method' => 'restore_source',
                'url' => $remoteUrl,
            ];
            $method = 'restore_source';
            break;
        }
    }
    if ($payload === null && $useRealEsrgan && ($upscaleFallback || $candidate['reason'] === 'force_below')) {
        $inputPath = $absolute;
        $tmpRemote = null;
        if ($candidate['source_url'] !== '') {
            foreach (productImageHdUpgradeUrls((string)$candidate['source_url']) as $remoteUrl) {
                try {
                    $remoteBytes = $http->getMedia($remoteUrl);
                } catch (Throwable) {
                    continue;
                }
                $remoteInfo = @getimagesizefromstring($remoteBytes);
                if (!is_array($remoteInfo) || (int)($remoteInfo[0] ?? 0) < 1) {
                    continue;
                }
                $tmpRemote = tempnam(sys_get_temp_dir(), 'pihd_src_');
                if ($tmpRemote === false) {
                    break;
                }
                $tmpRemoteFile = $tmpRemote . '.jpg';
                @unlink($tmpRemote);
                if (file_put_contents($tmpRemoteFile, $remoteBytes) === false) {
                    @unlink($tmpRemoteFile);
                    break;
                }
                $inputPath = $tmpRemoteFile;
                $tmpRemote = $tmpRemoteFile;
                break;
            }
        }
        $local = productImageHdRealEsrganUpscale($inputPath);
        if ($tmpRemote !== null) {
            @unlink($tmpRemote);
        }
        if ($local !== null) {
            $payload = $local + ['method' => 'realesrgan_x4plus', 'url' => (string)$candidate['source_url']];
            $method = 'realesrgan_x4plus';
        }
    }
    if ($payload === null && $candidate['source_url'] !== '' && !$useRealEsrgan) {
        $remote = productImageHdBestRemote($http, (string)$candidate['source_url'], max(1, $currentMin));
        if ($remote !== null) {
            $payload = $remote + ['method' => 'cdn_upgrade'];
            $method = 'cdn_upgrade';
        }
    }
    if ($payload === null && $upscaleFallback && in_array($candidate['reason'], ['low_res', 'degenerate', 'soft', 'force_below'], true)) {
        $local = productImageHdUpscaleLocal($absolute, TARGET_MIN_EDGE);
        if ($local !== null) {
            $payload = $local + ['method' => 'ffmpeg_lanczos_upscale', 'url' => (string)$candidate['source_url']];
            $method = 'ffmpeg_lanczos_upscale';
        }
    }
    if ($payload === null) {
        $workList[] = [
            'status' => 'skipped_no_hd_source',
            'asset_id' => $candidate['asset_id'],
            'object_key' => $candidate['object_key'],
            'reason' => $candidate['reason'],
            'width' => $candidate['width'],
            'height' => $candidate['height'],
        ];
        continue;
    }
    $workList[] = [
        'status' => 'planned',
        'asset_id' => $candidate['asset_id'],
        'object_key' => $candidate['object_key'],
        'reason' => $candidate['reason'],
        'method' => $method,
        'from' => [(int)$candidate['width'], (int)$candidate['height']],
        'to' => [(int)$payload['width'], (int)$payload['height']],
        'source_url' => (string)($payload['url'] ?? $candidate['source_url']),
        'payload' => $payload,
        'candidate' => $candidate,
    ];
}

if ($mode === '--dry-run') {
    $summary = [
        'ok' => true,
        'mode' => 'dry-run',
        'website_id' => $websiteId,
        'planned' => count(array_filter($workList, static fn (array $row): bool => $row['status'] === 'planned')),
        'skipped_no_hd_source' => count(array_filter($workList, static fn (array $row): bool => $row['status'] === 'skipped_no_hd_source')),
        'include_soft' => $includeSoft,
        'upscale_fallback' => $upscaleFallback,
        'items' => array_map(static function (array $row): array {
            unset($row['payload'], $row['candidate']);
            return $row;
        }, $workList),
    ];
    file_put_contents(
        $artifactDir . '/dry-run.json',
        json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
    );
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

$replacementMap = []; // old_asset_id => new descriptor
$applied = [];
$errors = [];

foreach ($workList as $row) {
    if ($row['status'] !== 'planned') {
        $applied[] = $row;
        continue;
    }
    try {
        $descriptor = productImageHdUploadReplacement(
            $library,
            $access,
            $diskCode,
            $row['candidate'],
            $row['payload'],
        );
        $newAssetId = strtolower(trim((string)($descriptor['asset_id'] ?? '')));
        if ($newAssetId === '') {
            throw new RuntimeException('hd_upload_missing_asset_id');
        }
        $replacementMap[(string)$row['asset_id']] = [
            'asset_id' => $newAssetId,
            'object_key' => (string)($descriptor['object_key'] ?? ''),
            'mime_type' => (string)($descriptor['mime'] ?? $row['payload']['mime']),
            'width' => (int)($descriptor['width'] ?? $row['payload']['width']),
            'height' => (int)($descriptor['height'] ?? $row['payload']['height']),
            'old_object_key' => (string)$row['object_key'],
            'method' => (string)$row['method'],
        ];
        $applied[] = [
            'status' => 'uploaded',
            'old_asset_id' => $row['asset_id'],
            'new_asset_id' => $newAssetId,
            'object_key' => $descriptor['object_key'] ?? '',
            'method' => $row['method'],
            'from' => $row['from'],
            'to' => $row['to'],
        ];
    } catch (Throwable $e) {
        $errors[] = [
            'asset_id' => $row['asset_id'],
            'object_key' => $row['object_key'],
            'error' => $e->getMessage(),
        ];
    }
}

$productIdsTouched = [];
foreach ($replacementMap as $oldAssetId => $new) {
    foreach ($candidates as $candidate) {
        if ($candidate['asset_id'] !== $oldAssetId) {
            continue;
        }
        foreach ($candidate['refs'] as $ref) {
            $productIdsTouched[(int)$ref['product_id']] = true;
        }
    }
}

foreach (array_keys($productIdsTouched) as $productId) {
    $existing = $mediaRepo->listByProductIds($websiteId, [(int)$productId], [0]);
    $rows = [];
    foreach ($existing as $index => $mediaRow) {
        $assetId = strtolower(trim((string)($mediaRow[Media::schema_fields_ASSET_ID] ?? '')));
        $replacement = $replacementMap[$assetId] ?? null;
        if ($replacement !== null) {
            $assetId = $replacement['asset_id'];
            $mime = $replacement['mime_type'];
            $visibility = (string)($mediaRow[Media::schema_fields_ASSET_VISIBILITY] ?? 'public');
        } else {
            $mime = (string)($mediaRow[Media::schema_fields_MIME_TYPE] ?? '');
            $visibility = (string)($mediaRow[Media::schema_fields_ASSET_VISIBILITY] ?? 'public');
        }
        $rows[] = [
            Media::schema_fields_ASSET_ID => $assetId,
            Media::schema_fields_ROLE => (string)($mediaRow[Media::schema_fields_ROLE] ?? 'gallery'),
            Media::schema_fields_COMBINATION_KEY => (string)($mediaRow[Media::schema_fields_COMBINATION_KEY] ?? ''),
            Media::schema_fields_ASSET_VISIBILITY => $visibility,
            Media::schema_fields_MIME_TYPE => $mime,
            Media::schema_fields_ACCESS_POLICY_JSON => $mediaRow[Media::schema_fields_ACCESS_POLICY_JSON] ?? null,
            Media::schema_fields_POSITION => (int)($mediaRow[Media::schema_fields_POSITION] ?? $index),
            Media::schema_fields_HIDDEN => !empty($mediaRow[Media::schema_fields_HIDDEN]) ? 1 : 0,
        ];
    }
    $mediaRepo->syncProductScope($websiteId, (int)$productId, 0, $rows);
}

$deleted = [];
foreach ($replacementMap as $oldAssetId => $new) {
    $oldKey = (string)$new['old_object_key'];
    try {
        $library->deleteObject($diskCode, $oldKey, $access);
        productImageHdPurgeAssetMetadata($oldAssetId);
        $absolute = $mediaRoot . '/' . ltrim($oldKey, '/');
        if (is_file($absolute)) {
            @unlink($absolute);
        }
        $deleted[] = [
            'old_asset_id' => $oldAssetId,
            'old_object_key' => $oldKey,
            'new_asset_id' => $new['asset_id'],
            'status' => 'deleted',
        ];
    } catch (Throwable $e) {
        $errors[] = [
            'asset_id' => $oldAssetId,
            'object_key' => $oldKey,
            'error' => 'delete_failed: ' . $e->getMessage(),
        ];
    }
}

$catalogCache->notifyCatalogChanged(
    $websiteId,
    'product_image_hd_remediation',
    [
        'replaced' => count($replacementMap),
        'deleted' => count($deleted),
        'errors' => count($errors),
    ],
);

$result = [
    'ok' => $errors === [],
    'mode' => 'apply',
    'website_id' => $websiteId,
    'include_soft' => $includeSoft,
    'upscale_fallback' => $upscaleFallback,
    'uploaded' => count($replacementMap),
    'deleted' => count($deleted),
    'skipped_no_hd_source' => count(array_filter($workList, static fn (array $row): bool => $row['status'] === 'skipped_no_hd_source')),
    'products_touched' => count($productIdsTouched),
    'errors' => $errors,
    'deleted_items' => $deleted,
    'uploaded_items' => array_values(array_filter($applied, static fn (array $row): bool => ($row['status'] ?? '') === 'uploaded')),
];
file_put_contents(
    $artifactDir . '/apply.json',
    json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($errors === [] ? 0 : 1);
