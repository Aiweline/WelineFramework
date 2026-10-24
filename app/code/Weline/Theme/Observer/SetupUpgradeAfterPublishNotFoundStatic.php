<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\StaticPublishFingerprint;
use Weline\Theme\Service\StaticPublishGate;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;

/**
 * Regenerate cached storefront 404 HTML after setup:upgrade.
 *
 * Hot-path 404 serving reads pub/errors/storefront-not-found/*.html directly (zero DB).
 * Manual / CI: `php bin/w theme:publish-not-found-static [--force] [--lang=el_GR]`
 */
final class SetupUpgradeAfterPublishNotFoundStatic implements ObserverInterface
{
    public function __construct(
        private readonly StorefrontNotFoundStaticGenerator $generator,
        private readonly Printing $printing,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (\is_array($eventData) && !empty($eventData['is_partial_upgrade'])) {
            return;
        }

        $args = \is_array($eventData) && \is_array($eventData['args'] ?? null) ? $eventData['args'] : [];
        $skipReason = StaticPublishGate::skipReason($args);
        if ($skipReason !== null) {
            $this->printing->note(__('404 静态发布已跳过（%{reason}）', ['reason' => $skipReason]));
            return;
        }

        $mode = (string)Env::getInstance()->getConfig('setup.static_publish', 'auto');
        if ($mode === 'never') {
            $this->printing->note(__('404 静态发布已关闭（setup.static_publish=never）'));
            return;
        }

        $fp = null;
        $hash = '';
        if ($mode === 'auto') {
            $fp = new StaticPublishFingerprint(VAR_DIR . 'state' . DIRECTORY_SEPARATOR . 'static_publish');
            $hash = $fp->compute($this->staticPublishInputs());
            if ($fp->matches('not_found', $hash)) {
                $this->printing->note(__('404 静态产物未变化，跳过后台 404 发布'));
                return;
            }
        }

        try {
            $this->printing->note(__('开始发布前台 404 静态页（website×locale，Fiber 协作）…'));
            $written = $this->generator->publishAll();
            $this->printing->success(__('前台 404 静态页发布完成：共 %{count} 个快照', [
                'count' => count($written),
            ]));
            if ($fp !== null) {
                $fp->remember('not_found', $fp->compute($this->staticPublishInputs()));
            }
        } catch (\Throwable $e) {
            $this->printing->warning(__('前台 404 静态页发布失败：%{msg}', ['msg' => $e->getMessage()]));
            w_log_warning(
                '[Theme] storefront 404 static publish failed: ' . $e->getMessage(),
                [],
                'theme_not_found_static'
            );
            $logDir = VAR_DIR . 'log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            @file_put_contents(
                $logDir . DIRECTORY_SEPARATOR . 'theme_static_publish.log',
                date('c') . ' ' . $e->getMessage() . PHP_EOL,
                FILE_APPEND
            );
        }
    }

    private function staticPublishInputs(): array
    {
        $sources = [];
        $stamps = $this->collectDirectoryStamps(BP . 'app' . DIRECTORY_SEPARATOR . 'design');
        if ($stamps !== []) {
            $sources['app/design'] = $stamps;
        }
        foreach (Env::getInstance()->getActiveModules() as $module) {
            $stamps = $this->collectDirectoryStamps(($module['base_path'] ?? '') . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'statics');
            if ($stamps !== []) {
                $sources[(string)($module['name'] ?? count($sources))] = $stamps;
            }
        }

        // 输出目录会被发布本身改写，纳入指纹会导致“永远不命中”；只用输入（源 + 目标集合）。
        $targets = [];
        try {
            $publisher = ObjectManager::getInstance(\Weline\Framework\Http\StaticErrorPagePublisher::class);
            foreach ($publisher->discoverPublishTargets() as $target) {
                $targets[] = (string)($target['website_code'] ?? '') . '/' . (string)($target['lang'] ?? '') . '/' . (int)($target['website_id'] ?? 0);
            }
        } catch (\Throwable) {
            $targets = ['unknown'];
        }
        sort($targets);
        return ['version' => 1, 'sources' => $sources, 'targets' => $targets];
    }

    private function collectDirectoryStamps(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $stamps = [];
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($count >= 20000) {
                break;
            }
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            // i18n-client 由升级/翻译流程每轮重写，属于生成物；计入会让指纹永不命中。
            if (str_contains($path, DIRECTORY_SEPARATOR . 'i18n-client' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $stamps[] = [$path, (int)$file->getMTime(), (int)$file->getSize()];
            $count++;
        }
        sort($stamps);
        return $stamps;
    }
}
