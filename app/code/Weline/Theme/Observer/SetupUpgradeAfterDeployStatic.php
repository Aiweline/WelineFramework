<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\Console\Console\Deploy\Upgrade as DeployUpgrade;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

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

        try {
            $this->printing->note(__('生产静态资源发布（含压缩）开始…'));
            /** @var DeployUpgrade $deploy */
            $deploy = ObjectManager::getInstance(DeployUpgrade::class);
            $deploy->execute();
        } catch (\Throwable $e) {
            $this->printing->warning(__('生产静态资源发布异常：%{msg}', ['msg' => $e->getMessage()]));
        }
    }

    /**
     * @internal test helper
     */
    public static function resetHasRunFlag(): void
    {
        self::$hasRun = false;
    }
}
