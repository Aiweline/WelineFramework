<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Console\Console\Deploy\Upgrade as DeployUpgrade;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\StaticPublishFingerprint;
use Weline\Theme\Service\StaticPublishGate;

/**
 * After setup:upgrade in production, republish static assets (minify via transform hook).
 */
class SetupUpgradeAfterDeployStatic implements ObserverInterface
{
    private static bool $hasRun = false;

    public function __construct(
        private readonly Printing $printing
    ) {
    }

    public function execute(Event &$event): void
    {
        if (self::$hasRun) {
            return;
        }
        self::$hasRun = true;

        if (defined('DEV') && DEV) {
            return;
        }

        $eventData = $event->getData();
        $args = is_array($eventData) && is_array($eventData['args'] ?? null) ? $eventData['args'] : [];
        $skipReason = StaticPublishGate::skipReason($args);
        if ($skipReason !== null) {
            $this->printing->note(__('静态发布已跳过（%{reason}）', ['reason' => $skipReason]));
            return;
        }

        $mode = (string)Env::getInstance()->getConfig('setup.static_publish', 'auto');
        if ($mode === 'never') {
            $this->printing->note(__('静态发布已关闭（setup.static_publish=never）'));
            return;
        }

        $fp = null;
        $hash = '';
        if ($mode === 'auto') {
            $fp = new StaticPublishFingerprint(VAR_DIR . 'state' . DIRECTORY_SEPARATOR . 'static_publish');
            $hash = $fp->compute($this->staticPublishInputs());
            if ($fp->matches('assets', $hash)) {
                $this->printing->note(__('静态源未变化，跳过生产静态发布'));
                return;
            }
        }

        try {
            $this->printing->note(__('生产静态资源发布（含压缩）开始…'));
            /** @var DeployUpgrade $deploy */
            $deploy = ObjectManager::getInstance(DeployUpgrade::class);
            $deploy->execute();
            if ($fp !== null) {
                $fp->remember('assets', $fp->compute($this->staticPublishInputs()));
            }
        } catch (\Throwable $e) {
            $this->printing->warning(__('生产静态资源发布异常：%{msg}', ['msg' => $e->getMessage()]));
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

    /**
     * @deprecated use {@see StaticPublishGate::skipReason()}
     * @param array<string, mixed> $args
     */
    public static function skipReasonForArgs(array $args = []): ?string
    {
        return StaticPublishGate::skipReason($args);
    }

    private function staticPublishInputs(): array
    {
        $sources = [];
        $stamps = $this->collectDirectoryStamps(BP . 'app/design');
        if ($stamps !== []) {
            $sources['app/design'] = $stamps;
        }
        $moduleCount = 0;
        foreach (Env::getInstance()->getActiveModules() as $module) {
            $moduleCount++;
            $stamps = $this->collectDirectoryStamps(($module['base_path'] ?? '') . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'statics');
            if ($stamps !== []) {
                $sources[(string)($module['name'] ?? count($sources))] = $stamps;
            }
        }
        return ['version' => 1, 'sources' => $sources, 'modules' => $moduleCount];
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

    /**
     * @internal test helper
     */
    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
