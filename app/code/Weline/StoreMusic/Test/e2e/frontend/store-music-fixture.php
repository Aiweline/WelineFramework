<?php

declare(strict_types=1);

/**
 * StoreMusic e2e fixture: apply / restore ambiance config + serve tiny mp3 fixture URL path.
 *
 * stdin JSON: {"action":"enable"|"disable"|"restore"|"status", "token"?: string, "delay_seconds"?: int}
 * stdout JSON only.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\StoreMusic\Service\StoreMusicSettings;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\SystemConfig\Model\SystemConfig;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * @return array<string, mixed>
 */
function store_music_input(): array
{
    $raw = file_get_contents('php://stdin');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('stdin must be a JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function store_music_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function store_music_fixture_audio_path(string $format = 'mp3'): string
{
    $dir = BP . '/pub/media/store-music-e2e';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('unable to create store-music e2e media dir');
    }
    $format = strtolower(trim($format));
    if ($format === 'm4a') {
        $file = $dir . '/beep.m4a';
        if (!is_file($file)) {
            $candidates = [
                BP . '/pub/media/store-music/gaoshan.m4a',
                BP . '/pub/media/store-music/gaoshan-liushui.m4a',
                BP . '/pub/media/store-music/高山流水 - 纯音乐网.m4a',
            ];
            $copied = false;
            foreach ($candidates as $src) {
                if (is_file($src) && @copy($src, $file)) {
                    $copied = true;
                    break;
                }
            }
            if (!$copied) {
                // Minimal ISO BMFF / M4A shell so WLS can 200 with audio/mp4; decode may soft-fail.
                $ftyp = 'ftypM4A ';
                $bytes = 'xxxx' . $ftyp . str_repeat("\0", 64);
                $bytes = pack('N', strlen($bytes)) . substr($bytes, 4);
                file_put_contents($file, $bytes);
            }
        }

        return '/media/store-music-e2e/beep.m4a';
    }

    $file = $dir . '/beep.mp3';
    if (!is_file($file)) {
        // Minimal valid-enough MPEG frame header + padding for <audio> to request the URL.
        $bytes = hex2bin('fff340c400000000000000000000000000000000') ?: '';
        file_put_contents($file, $bytes . str_repeat("\0", 256));
    }

    return '/media/store-music-e2e/beep.mp3';
}

function store_music_flush_runtime_caches(): void
{
    // WLS workers keep config/FPC in-process; CLI setScopedConfig is not enough alone.
    // Do NOT call server:reload here — it can hang under concurrent e2e/fixture load.
    // Prefer pool clears first; full cache:clear is best-effort with a hard timeout
    // so concurrent e2e/cron cannot wedge the fixture forever.
    foreach (['system_config', 'config', 'fpc', 'database'] as $pool) {
        try {
            \w_cache($pool)->clear();
        } catch (Throwable) {
            // Soft.
        }
    }

    $bin = BP . '/bin/w';
    if (!is_file($bin)) {
        return;
    }

    $cmd = 'php ' . escapeshellarg($bin) . ' cache:clear';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, BP);
    if (!is_resource($proc)) {
        return;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + 40.0;
    do {
        $status = proc_get_status($proc);
        if (($status['running'] ?? false) !== true) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    $status = proc_get_status($proc);
    if (($status['running'] ?? false) === true) {
        @proc_terminate($proc, 15);
        usleep(300000);
        $status = proc_get_status($proc);
        if (($status['running'] ?? false) === true) {
            @proc_terminate($proc, 9);
        }
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    @proc_close($proc);
    sleep(2);
}

/**
 * Best-effort probe that WLS HTML reflects current widget gate.
 */
function store_music_probe_widget_html(): array
{
    $hosts = [];
    $origin = getenv('PLAYWRIGHT_TARGET_ORIGIN') ?: getenv('WELINE_E2E_ORIGIN') ?: '';
    $origin = is_string($origin) ? rtrim($origin, '/') : '';
    if ($origin !== '') {
        $hosts[] = $origin . '/';
    }
    $hosts = array_values(array_unique(array_merge($hosts, [
        'https://p05113ef3.test.weline.com:19655/',
        'https://p05113ef3.test.weline.com:9555/',
        'https://127.0.0.1:19655/',
        'https://127.0.0.1:9555/',
    ])));
    $found = [];
    foreach ($hosts as $base) {
        $url = $base . '?sp_fixture_probe=' . rawurlencode((string)microtime(true));
        // Prefer HTTP/1.1: local WLS occasionally stalls on h2 handshake under e2e load.
        $cmd = 'curl -skL --http1.1 --max-time 20 ' . escapeshellarg($url);
        $html = (string)shell_exec($cmd);
        $found[$base] = str_contains($html, 'data-testid="store-music-widget"');
        $found[$base . '#bytes'] = strlen($html);
    }

    return $found;
}

function store_music_snapshot_path(string $token): string
{
    return BP . '/var/tmp/store-music-e2e-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';
}

/**
 * @return list<string>
 */
function store_music_keys(): array
{
    return [
        StoreMusicSettings::KEY_ENABLED,
        StoreMusicSettings::KEY_TRACK,
        StoreMusicSettings::KEY_PLAYLIST,
        StoreMusicSettings::KEY_DELAY_SECONDS,
        StoreMusicSettings::KEY_TRY_AUTOPLAY,
        StoreMusicSettings::KEY_LOOP,
        StoreMusicSettings::KEY_DEFAULT_VOLUME,
        StoreMusicSettings::KEY_WAVEFORM_DEFAULT,
    ];
}

function store_music_snapshot(string $token): void
{
    $path = store_music_snapshot_path($token);
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('unable to create snapshot dir');
    }
    /** @var SystemConfig $model */
    $model = clone ObjectManager::getInstance(SystemConfig::class);
    /** @var ConfigReader $reader */
    $reader = ObjectManager::getInstance(ConfigReader::class);
    $entries = [];
    foreach (store_music_keys() as $key) {
        $row = $model->getScopedConfigRow(
            $key,
            StoreMusicSettings::MODULE,
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
        $entries[$key] = [
            'exists' => $row !== null,
            'value' => $reader->get(
                $key,
                StoreMusicSettings::MODULE,
                ConfigReader::area_FRONTEND,
                null,
                ConfigReader::SCOPE_GLOBAL,
            ),
        ];
    }
    $payload = ['schema' => 1, 'entries' => $entries];
    file_put_contents(
        $path,
        (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // Keep a durable copy of the last non-e2e playlist so nested enable/restore
    // cannot permanently lose admin-configured tracks.
    $playlist = (string)($entries[StoreMusicSettings::KEY_PLAYLIST]['value'] ?? '');
    $track = (string)($entries[StoreMusicSettings::KEY_TRACK]['value'] ?? '');
    $enabled = (string)($entries[StoreMusicSettings::KEY_ENABLED]['value'] ?? '');
    $isE2e = str_contains($playlist, 'store-music-e2e')
        || str_contains($playlist, 'E2E')
        || str_contains($track, 'store-music-e2e');
    $isEmpty = ($playlist === '' || $playlist === '[]') && ($track === '');
    $isDisabled = $enabled === '0' || $enabled === 'false';
    // Never poison durable backup with gate-off / empty snapshots (CH1 disable).
    if (!$isE2e && !$isEmpty && !$isDisabled) {
        file_put_contents(
            BP . '/var/tmp/store-music-last-real.json',
            (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}

function store_music_restore(string $token): array
{
    $path = store_music_snapshot_path($token);
    $realBackup = BP . '/var/tmp/store-music-last-real.json';
    if (!is_file($path)) {
        if (is_file($realBackup)) {
            $path = $realBackup;
        } else {
            return ['ok' => true, 'restored' => false, 'reason' => 'no_snapshot'];
        }
    }
    $raw = file_get_contents($path);
    $snapshot = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($snapshot)) {
        throw new RuntimeException('invalid snapshot');
    }
    $entries = is_array($snapshot['entries'] ?? null) ? $snapshot['entries'] : [];
    $playlist = (string)(($entries[StoreMusicSettings::KEY_PLAYLIST]['value'] ?? '') ?: '');
    $track = (string)(($entries[StoreMusicSettings::KEY_TRACK]['value'] ?? '') ?: '');
    $isE2e = str_contains($playlist, 'store-music-e2e')
        || str_contains($playlist, 'E2E')
        || str_contains($track, 'store-music-e2e');
    // Nested enable snapshots often capture e2e-over-e2e; prefer durable real backup.
    if ($isE2e && is_file($realBackup) && $path !== $realBackup) {
        $rawReal = file_get_contents($realBackup);
        $realSnap = json_decode(is_string($rawReal) ? $rawReal : '', true);
        if (is_array($realSnap) && is_array($realSnap['entries'] ?? null)) {
            $snapshot = $realSnap;
            $entries = $realSnap['entries'];
        }
    }
    /** @var ConfigStore $store */
    $store = ObjectManager::getInstance(ConfigStore::class);
    foreach (store_music_keys() as $key) {
        $entry = is_array($entries[$key] ?? null) ? $entries[$key] : [];
        if (($entry['exists'] ?? false) === true) {
            $store->setScopedConfig(
                $key,
                $entry['value'] ?? null,
                StoreMusicSettings::MODULE,
                ConfigReader::area_FRONTEND,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            );
            continue;
        }
        $store->deleteScopedConfig(
            $key,
            StoreMusicSettings::MODULE,
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
    }
    $tokenPath = store_music_snapshot_path($token);
    if (is_file($tokenPath)) {
        @unlink($tokenPath);
    }
    store_music_flush_runtime_caches();

    return ['ok' => true, 'restored' => true];
}

/**
 * @param array<string, mixed> $input
 */
function store_music_enable(array $input): array
{
    $token = trim((string)($input['token'] ?? 'default'));
    store_music_snapshot($token);
    $delay = max(1, min(15, (int)($input['delay_seconds'] ?? 2)));
    $format = strtolower(trim((string)($input['format'] ?? 'mp3')));
    $track = store_music_fixture_audio_path($format === 'm4a' ? 'm4a' : 'mp3');
    $playlist = StoreMusicSettings::encodePlaylist([
        [
            'url' => $track,
            'title' => 'E2E 曲目甲',
            'intro' => '甲曲简介：用于进店氛围验收。',
        ],
        [
            'url' => $track,
            'title' => 'E2E 曲目乙',
            'intro' => '乙曲简介：切歌后可见。',
        ],
    ]);
    /** @var ConfigStore $store */
    $store = ObjectManager::getInstance(ConfigStore::class);
    $writes = [
        StoreMusicSettings::KEY_ENABLED => '1',
        StoreMusicSettings::KEY_TRACK => $track,
        StoreMusicSettings::KEY_PLAYLIST => $playlist,
        StoreMusicSettings::KEY_DELAY_SECONDS => (string)$delay,
        StoreMusicSettings::KEY_TRY_AUTOPLAY => '1',
        StoreMusicSettings::KEY_LOOP => '1',
        StoreMusicSettings::KEY_DEFAULT_VOLUME => '30',
        StoreMusicSettings::KEY_WAVEFORM_DEFAULT => '1',
    ];
    foreach ($writes as $key => $value) {
        $ok = $store->setScopedConfig(
            $key,
            $value,
            StoreMusicSettings::MODULE,
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
        if ($ok !== true) {
            throw new RuntimeException('setScopedConfig failed for ' . $key);
        }
    }
    store_music_flush_runtime_caches();

    return [
        'ok' => true,
        'mode' => 'enable',
        'token' => $token,
        'track' => $track,
        'delay_seconds' => $delay,
        'html_has_widget' => store_music_probe_widget_html(),
    ];
}

/**
 * @param array<string, mixed> $input
 */
function store_music_disable(array $input): array
{
    $token = trim((string)($input['token'] ?? 'default'));
    store_music_snapshot($token);
    /** @var ConfigStore $store */
    $store = ObjectManager::getInstance(ConfigStore::class);
    foreach ([
        StoreMusicSettings::KEY_ENABLED => '0',
        StoreMusicSettings::KEY_TRACK => '',
        StoreMusicSettings::KEY_PLAYLIST => '[]',
    ] as $key => $value) {
        $ok = $store->setScopedConfig(
            $key,
            $value,
            StoreMusicSettings::MODULE,
            ConfigReader::area_FRONTEND,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        );
        if ($ok !== true) {
            throw new RuntimeException('setScopedConfig failed for ' . $key);
        }
    }
    store_music_flush_runtime_caches();

    return ['ok' => true, 'mode' => 'disable', 'token' => $token];
}

function store_music_status(): array
{
    /** @var StoreMusicSettings $settings */
    $settings = ObjectManager::getInstance(StoreMusicSettings::class);

    return [
        'ok' => true,
        'enabled' => $settings->isEnabled(),
        'track' => $settings->trackUrl(),
        'active' => $settings->isWidgetActive(),
        'delay_seconds' => $settings->delaySeconds(),
        'payload' => $settings->frontendPayload(),
    ];
}

try {
    $input = store_music_input();
    $action = (string)($input['action'] ?? '');
    $result = match ($action) {
        'enable' => store_music_enable($input),
        'disable' => store_music_disable($input),
        'restore' => store_music_restore(trim((string)($input['token'] ?? 'default'))),
        'status' => store_music_status(),
        default => throw new InvalidArgumentException('unknown action'),
    };
    store_music_out($result);
} catch (Throwable $e) {
    store_music_out(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}
