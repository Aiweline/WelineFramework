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

        try {
            if ($this->allI18nFingerprintsFresh()) {
                if (php_sapi_name() === 'cli') {
                    echo "\n[Phrase] i18n 目录指纹未变，跳过语言包 compile\n";
                }

                return;
            }

            if (php_sapi_name() === 'cli') {
                echo "\n[Phrase] 正在收集语言包...\n";
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
        $store = $fpService->loadStore();
        foreach ($fps as $k => $v) {
            $store[$k] = $v;
        }
        $fpService->saveStore($store);
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
}
