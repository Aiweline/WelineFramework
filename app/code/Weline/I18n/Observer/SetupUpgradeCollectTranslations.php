<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Registry\Service\RegistryProgress;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;

/**
 * 系统升级后自动收集语言包（Framework DictionaryCompiler）。
 *
 * 稀表架构：s:up 默认不跑全量 DB collect（避免百万行 locale 拖死升级）。
 * 需要全量时显式 `--force-i18n-collect`；平时用 cron / `i18n:collect`。
 */
class SetupUpgradeCollectTranslations implements ObserverInterface
{
    public function __construct(
        private readonly DictionaryCompiler $compiler,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!empty($eventData['is_partial_upgrade'])) {
            return;
        }

        $args = \is_array($eventData['args'] ?? null) ? $eventData['args'] : [];
        if (!$this->wantsForceI18nCollect($args)) {
            if (php_sapi_name() === 'cli') {
                echo "\n[Phrase] 已跳过语言包全量 collect（稀表默认）。需要时：php bin/w setup:upgrade --force-i18n-collect 或 php bin/w i18n:collect\n";
            }

            return;
        }

        try {
            if ($this->allI18nFingerprintsFresh() && $this->phraseArtifactsPresent()) {
                if (php_sapi_name() === 'cli') {
                    echo "\n[Phrase] i18n 目录指纹未变，跳过语言包 compile\n";
                }

                return;
            }

            if ($this->allI18nFingerprintsFresh() && !$this->phraseArtifactsPresent() && php_sapi_name() === 'cli') {
                echo "\n[Phrase] i18n 源指纹命中但 generated/language 产物缺失，强制 compile\n";
            }

            if (php_sapi_name() === 'cli') {
                echo "\n[Phrase] 正在收集语言包（--force-i18n-collect）...\n";
            }

            RegistryProgress::run(function (): void {
                RegistryProgress::section('setup:upgrade phrase dictionary collect');
                $this->compiler->compile(null, false);
            });

            DictionaryCompiler::clearTranslationCaches();
            $this->persistI18nFingerprints();

            if (php_sapi_name() === 'cli') {
                echo "[Phrase] 语言包收集完成！\n";
            }
        } catch (\Throwable $throwable) {
            w_log_error('Phrase: 系统升级后语言包收集失败 - ' . $throwable->getMessage(), [], 'phrase');
            if (php_sapi_name() === 'cli') {
                echo '[Phrase] 语言包收集失败：' . $throwable->getMessage() . "\n";
            }
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function wantsForceI18nCollect(array $args): bool
    {
        if (isset($args['force-i18n-collect']) || isset($args['force_i18n_collect'])) {
            return true;
        }
        foreach ($args as $key => $value) {
            if ($key === '--force-i18n-collect' || $value === '--force-i18n-collect') {
                return true;
            }
            if (\is_string($key) && str_contains($key, 'force-i18n-collect')) {
                return true;
            }
        }

        return false;
    }

    private function allI18nFingerprintsFresh(): bool
    {
        $fps = $this->collectI18nFingerprints();
        if ($fps === []) {
            return false;
        }
        $fpService = new SetupSourceFingerprint();
        foreach ($fps as $key => $fp) {
            if (!$fpService->matches($key, $fp)) {
                return false;
            }
        }

        return true;
    }

    private function persistI18nFingerprints(): void
    {
        $fps = $this->collectI18nFingerprints();
        if ($fps === []) {
            return;
        }
        $fpService = new SetupSourceFingerprint();
        $fpService->mergeUpdates($fps);
    }

    /**
     * @return array<string, string>
     */
    private function collectI18nFingerprints(): array
    {
        $fpService = new SetupSourceFingerprint();
        $out = [];
        $modules = Env::getInstance()->getActiveModules();
        foreach ($modules as $module) {
            if (!\is_array($module)) {
                continue;
            }
            $name = (string)($module['name'] ?? '');
            $base = \rtrim((string)($module['base_path'] ?? ''), '/\\');
            if ($name === '' || $base === '') {
                continue;
            }
            $i18nDir = $base . DIRECTORY_SEPARATOR . 'i18n';
            $out['i18n:' . $name] = \is_dir($i18nDir)
                ? $fpService->fingerprintTree($i18nDir)
                : \hash('sha256', 'i18n-empty:' . $name);
        }

        return $out;
    }

    /**
     * generated/language 词典产物是否存在。源指纹命中但产物空/缺失时不得跳过 compile。
     */
    private function phraseArtifactsPresent(): bool
    {
        $dir = \rtrim((string)Env::path_TRANSLATE_FILES_PATH, '/\\');
        if ($dir === '' || !\is_dir($dir)) {
            return false;
        }
        $found = 0;
        foreach (\glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $base = \basename((string)$file, '.php');
            if ($base === 'words' || \preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $base) !== 1) {
                continue;
            }
            if ((int)@\filesize((string)$file) > 32) {
                $found++;
            }
            if ($found >= 2) {
                return true;
            }
        }

        return false;
    }
}
