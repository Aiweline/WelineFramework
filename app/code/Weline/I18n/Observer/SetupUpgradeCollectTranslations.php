<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Registry\Service\RegistryProgress;

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
            if (php_sapi_name() === 'cli') {
                echo "\n[Phrase] 正在收集语言包...\n";
            }

            RegistryProgress::run(function (): void {
                RegistryProgress::section('setup:upgrade phrase dictionary collect');
                $this->compiler->compile(null, false);
            });

            DictionaryCompiler::clearTranslationCaches();

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
}
