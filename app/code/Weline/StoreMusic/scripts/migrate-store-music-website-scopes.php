<?php

declare(strict_types=1);

/**
 * Move entrance-music SystemConfig off Global onto website scopes.
 *
 * Problem: playlist/enabled lived at Global (`default.default.default`), so
 * DaoCharms (and any other website) inherited Hanfu 古风 tracks via
 * Website → Global fallback.
 *
 * Fix:
 * 1) Copy all `store_music/*` rows from Global → Website(default)
 * 2) Delete Global `store_music/*` rows (stop cross-site bleed)
 * 3) Write Website(daocharms) enabled=0 + empty playlist (explicit isolation)
 *
 * Usage (repo root):
 *   php app/code/Weline/StoreMusic/scripts/migrate-store-music-website-scopes.php
 *   php app/code/Weline/StoreMusic/scripts/migrate-store-music-website-scopes.php --dry-run
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\StoreMusic\Service\StoreMusicSettings;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Model\Website;

require dirname(__DIR__, 4) . '/bootstrap.php';

$dryRun = in_array('--dry-run', $argv ?? ($_SERVER['argv'] ?? []), true);

/** @var SystemConfig $config */
$config = ObjectManager::getInstance(SystemConfig::class);
/** @var Website $websiteModel */
$websiteModel = ObjectManager::getInstance(Website::class);

$keys = [
    StoreMusicSettings::KEY_ENABLED,
    StoreMusicSettings::KEY_TRACK,
    StoreMusicSettings::KEY_PLAYLIST,
    StoreMusicSettings::KEY_DELAY_SECONDS,
    StoreMusicSettings::KEY_TRY_AUTOPLAY,
    StoreMusicSettings::KEY_LOOP,
    StoreMusicSettings::KEY_DEFAULT_VOLUME,
    StoreMusicSettings::KEY_WAVEFORM_DEFAULT,
    StoreMusicSettings::KEY_AVATAR_SPIN,
];

$module = StoreMusicSettings::MODULE;
$area = StoreMusicSettings::AREA;
$globalScope = SystemConfig::SCOPE_GLOBAL;
$locale = SystemConfig::LOCALE_DEFAULT;

$defaultRows = $websiteModel->clear()->where('code', Website::CODE_DEFAULT)->select()->fetchArray();
$daoRows = $websiteModel->clear()->where('code', 'daocharms')->select()->fetchArray();
$defaultRow = is_array($defaultRows[0] ?? null) ? $defaultRows[0] : null;
$daoRow = is_array($daoRows[0] ?? null) ? $daoRows[0] : null;
if ($defaultRow === null || !array_key_exists('website_id', $defaultRow)) {
    fwrite(STDERR, "default website not found\n");
    exit(1);
}
if ($daoRow === null || !array_key_exists('website_id', $daoRow)) {
    fwrite(STDERR, "daocharms website not found\n");
    exit(1);
}

$defaultWebsiteId = (int)$defaultRow['website_id'];
$daoWebsiteId = (int)$daoRow['website_id'];
$defaultIdentity = ScopeIdentity::website($defaultWebsiteId, Website::CODE_DEFAULT);
$daoIdentity = ScopeIdentity::website($daoWebsiteId, 'daocharms');

echo ($dryRun ? "[dry-run] " : '') . "Migrating store_music Global → Website(default); isolate Website(daocharms)\n";
echo 'default website_id=' . $defaultWebsiteId . " daocharms website_id=" . $daoWebsiteId . "\n";

$copied = 0;
foreach ($keys as $key) {
    $row = $config->getScopedConfigRow($key, $module, $area, $globalScope, $locale);
    if (!is_array($row)) {
        echo "  skip $key (no global row)\n";
        continue;
    }
    $value = (string)($row[SystemConfig::schema_fields_VALUE] ?? '');
    $preview = strlen($value) > 64 ? substr($value, 0, 64) . '…' : $value;
    echo "  copy $key → Website(default) value=$preview\n";
    if (!$dryRun) {
        $ok = $config->setScopedConfig(
            $key,
            $value,
            $module,
            $area,
            null,
            $locale,
            ['scope_identity' => $defaultIdentity],
        );
        if (!$ok) {
            fwrite(STDERR, "FAILED set Website(default) $key\n");
            exit(1);
        }
    }
    $copied++;
}

echo "Deleting Global store_music rows…\n";
foreach ($keys as $key) {
    $row = $config->getScopedConfigRow($key, $module, $area, $globalScope, $locale);
    if (!is_array($row)) {
        continue;
    }
    echo "  delete global $key\n";
    if (!$dryRun) {
        $ok = $config->deleteScopedConfig($key, $module, $area, $globalScope, $locale);
        if (!$ok) {
            fwrite(STDERR, "FAILED delete global $key\n");
            exit(1);
        }
    }
}

echo "Writing Website(daocharms) isolation (enabled=0, empty playlist)…\n";
if (!$dryRun) {
    $okEnabled = $config->setScopedConfig(
        StoreMusicSettings::KEY_ENABLED,
        '0',
        $module,
        $area,
        null,
        $locale,
        ['scope_identity' => $daoIdentity],
    );
    $okPlaylist = $config->setScopedConfig(
        StoreMusicSettings::KEY_PLAYLIST,
        '[]',
        $module,
        $area,
        null,
        $locale,
        ['scope_identity' => $daoIdentity],
    );
    $okTrack = $config->setScopedConfig(
        StoreMusicSettings::KEY_TRACK,
        '',
        $module,
        $area,
        null,
        $locale,
        ['scope_identity' => $daoIdentity],
    );
    if (!$okEnabled || !$okPlaylist || !$okTrack) {
        fwrite(STDERR, "FAILED daocharms isolation writes\n");
        exit(1);
    }
}

echo "Verify typed resolve…\n";
$reader = ObjectManager::getInstance(\Weline\SystemConfig\Api\ConfigReader::class);
foreach (
    [
        ['default', $defaultIdentity],
        ['daocharms', $daoIdentity],
        ['global', ScopeIdentity::global()],
    ] as [$label, $identity]
) {
    $en = $reader->resolveTypedConfig(StoreMusicSettings::KEY_ENABLED, $module, $area, $identity);
    $pl = $reader->resolveTypedConfig(StoreMusicSettings::KEY_PLAYLIST, $module, $area, $identity);
    $plVal = (string)($pl->found() ? $pl->value : '');
    $hasChil = str_contains($plVal, '赤伶');
    $srcEn = $en->found() ? ($en->source->scopeKind . '@' . $en->source->storageScope) : 'none';
    $srcPl = $pl->found() ? ($pl->source->scopeKind . '@' . $pl->source->storageScope) : 'none';
    echo sprintf(
        "  %-10s enabled=%s src=%s has赤伶=%s plSrc=%s\n",
        $label,
        var_export($en->found() ? $en->value : null, true),
        $srcEn,
        $hasChil ? 'Y' : 'N',
        $srcPl,
    );
}

echo "Done. copied_keys=$copied dry_run=" . ($dryRun ? '1' : '0') . "\n";
