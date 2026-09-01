<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;

/**
 * Immutable upgrade-wave snapshot for maintenance wait-gift tokens (no DB at issue time).
 */
final class UpgradeWaveService
{
    public const WAVE_FILE = 'var/maintenance/current_wave.json';
    public const REDEEM_TTL_SECONDS = 600;
    public const MIN_WAIT_SECONDS_DEFAULT = 30;
    public const HIDDEN_ABANDON_SECONDS = 300;

    public function __construct(private readonly ?string $basePath = null)
    {
    }

    public function waveFilePath(): string
    {
        return $this->root() . self::WAVE_FILE;
    }

    /**
     * @return array<string, mixed>
     */
    public function beginWave(): array
    {
        $previous = $this->readWave();
        $systemTo = $this->resolveFrameworkVersion();
        $themeTo = $this->resolveThemeVersionLabel();
        $systemFrom = (string)($previous['system_version_to'] ?? $systemTo);
        $themeFrom = (string)($previous['theme_version_to'] ?? $themeTo);
        $startedAt = \time();
        $siteId = $this->resolveSiteId();
        $waveId = \hash(
            'sha256',
            \implode('|', [
                $siteId,
                $systemFrom,
                $systemTo,
                $themeFrom,
                $themeTo,
                (string)$startedAt,
            ])
        );

        $gift = $this->readGiftConfig();
        $wave = [
            'wave_id' => $waveId,
            'site_id' => $siteId,
            'system_version_from' => $systemFrom,
            'system_version_to' => $systemTo,
            'theme_version_from' => $themeFrom,
            'theme_version_to' => $themeTo,
            'maintenance_started_at' => $startedAt,
            'recovered_at' => null,
            'redeem_deadline_at' => null,
            'wait_gift_enabled' => (bool)($gift['enabled'] ?? false),
            'min_wait_sec' => (int)($gift['min_wait_sec'] ?? self::MIN_WAIT_SECONDS_DEFAULT),
            'marketing_rule_id' => (int)($gift['marketing_rule_id'] ?? 0),
            'discount_type' => (string)($gift['discount_type'] ?? 'fixed_amount'),
            'discount_value' => (float)($gift['discount_value'] ?? 0),
            'status' => 'active',
        ];

        $this->writeWave($wave);

        return $wave;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function markRecovered(?int $now = null): ?array
    {
        $wave = $this->readWave();
        if ($wave === null) {
            return null;
        }
        $now ??= \time();
        if (empty($wave['recovered_at'])) {
            $wave['recovered_at'] = $now;
            $wave['redeem_deadline_at'] = $now + self::REDEEM_TTL_SECONDS;
            $wave['status'] = 'redeem_window';
            $this->writeWave($wave);
        }

        return $wave;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readWave(): ?array
    {
        $path = $this->waveFilePath();
        if (!\is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $wave
     */
    public function writeWave(array $wave): void
    {
        $path = $this->waveFilePath();
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $payload = \json_encode($wave, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        if (!\is_string($payload)) {
            throw new \RuntimeException('Unable to encode upgrade wave snapshot.');
        }
        if (@\file_put_contents($path, $payload . "\n", \LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write upgrade wave snapshot.');
        }
    }

    public function isRedeemWindowOpen(?array $wave = null, ?int $now = null): bool
    {
        $wave ??= $this->readWave();
        if ($wave === null) {
            return false;
        }
        $now ??= \time();
        $deadline = (int)($wave['redeem_deadline_at'] ?? 0);
        if ($deadline <= 0) {
            return false;
        }

        return $now <= $deadline;
    }

    public function resolveFrameworkVersion(): string
    {
        $file = $this->root() . 'app/code/Weline/Framework/etc/module.php';
        if (!\is_file($file)) {
            return '0.0.0';
        }
        /** @var mixed $meta */
        $meta = include $file;
        if (!\is_array($meta)) {
            return '0.0.0';
        }

        return \trim((string)($meta['version'] ?? '0.0.0')) ?: '0.0.0';
    }

    /**
     * Theme wave label = user-published theme release (theme_scope_release), never Weline_Theme module version.
     */
    public function resolveThemeVersionLabel(): string
    {
        try {
            /** @var ThemePublishedVersionRuntimeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
            $resolved = $resolver->resolve();
            $label = \trim((string)($resolved['themePublishedVersion'] ?? ''));
            if ($label !== '') {
                return $label;
            }
            $id = \trim((string)($resolved['themePublishedVersionId'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        } catch (\Throwable) {
        }

        return 'unknown';
    }

    private function root(): string
    {
        $base = $this->basePath ?? (\defined('BP') ? (string)BP : '');
        if ($base === '') {
            throw new \RuntimeException('UpgradeWaveService base path is unavailable.');
        }

        return \rtrim($base, "/\\") . '/';
    }

    public function resolveSiteId(): string
    {
        try {
            $theme = Env::getInstance()->getTheme();
            $websiteId = (string)($theme['website_id'] ?? $theme['id'] ?? 'default');

            return $websiteId !== '' ? $websiteId : 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }

    /**
     * Gift knobs from env/system config (readable without DB after sync to env).
     *
     * @return array<string, mixed>
     */
    public function readGiftConfig(): array
    {
        try {
            $env = Env::getInstance();
            $enabled = (bool)$env->getConfig(
                'maintenance.wait_gift.enabled',
                $env->getConfig('maintenance/wait_gift/enabled', false)
            );
            $minWait = (int)$env->getConfig(
                'maintenance.wait_gift.min_wait_sec',
                $env->getConfig('maintenance/wait_gift/min_wait_sec', self::MIN_WAIT_SECONDS_DEFAULT)
            );
            if ($minWait < 0) {
                $minWait = self::MIN_WAIT_SECONDS_DEFAULT;
            }

            return [
                'enabled' => $enabled,
                'min_wait_sec' => $minWait,
                'marketing_rule_id' => (int)$env->getConfig(
                    'maintenance.wait_gift.marketing_rule_id',
                    $env->getConfig('maintenance/wait_gift/marketing_rule_id', 0)
                ),
                'discount_type' => (string)$env->getConfig(
                    'maintenance.wait_gift.discount_type',
                    $env->getConfig('maintenance/wait_gift/discount_type', 'fixed_amount')
                ),
                'discount_value' => (float)$env->getConfig(
                    'maintenance.wait_gift.discount_value',
                    $env->getConfig('maintenance/wait_gift/discount_value', 0)
                ),
            ];
        } catch (\Throwable) {
            return [
                'enabled' => false,
                'min_wait_sec' => self::MIN_WAIT_SECONDS_DEFAULT,
                'marketing_rule_id' => 0,
                'discount_type' => 'fixed_amount',
                'discount_value' => 0.0,
            ];
        }
    }

    /**
     * Persist gift campaign binding into env for maintenance-time reads.
     *
     * @param array<string, mixed> $config
     */
    public function writeGiftConfig(array $config): void
    {
        try {
            $env = Env::getInstance();
            $enabled = (bool)($config['enabled'] ?? false);
            $minWait = (int)($config['min_wait_sec'] ?? self::MIN_WAIT_SECONDS_DEFAULT);
            $ruleId = (int)($config['marketing_rule_id'] ?? 0);
            $type = (string)($config['discount_type'] ?? 'fixed_amount');
            $value = (float)($config['discount_value'] ?? 0);
            $env->setConfig('maintenance.wait_gift.enabled', $enabled);
            $env->setConfig('maintenance/wait_gift/enabled', $enabled);
            $env->setConfig('maintenance.wait_gift.min_wait_sec', $minWait);
            $env->setConfig('maintenance/wait_gift/min_wait_sec', $minWait);
            $env->setConfig('maintenance.wait_gift.marketing_rule_id', $ruleId);
            $env->setConfig('maintenance/wait_gift/marketing_rule_id', $ruleId);
            $env->setConfig('maintenance.wait_gift.discount_type', $type);
            $env->setConfig('maintenance/wait_gift/discount_type', $type);
            $env->setConfig('maintenance.wait_gift.discount_value', $value);
            $env->setConfig('maintenance/wait_gift/discount_value', $value);
        } catch (\Throwable) {
            // Env may be unavailable in isolated unit contexts.
        }
    }
}
