<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/5/10
 * 时间：23:49
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Maintenance\Console\Maintenance;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;
use Weline\Maintenance\Helper\WlsMaintenanceSync;
use Weline\Maintenance\Service\MaintenanceStaticGenerator;
use Weline\Maintenance\Service\UpgradeWaveService;
use Weline\Maintenance\Service\WaitGiftCampaignSyncService;

class Enable implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * Enable 初始函数...
     *
     * @param Printing $printing
     */
    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $waves = new UpgradeWaveService();
        $gift = $waves->readGiftConfig();
        if (!empty($gift['enabled'])) {
            (new WaitGiftCampaignSyncService())->sync($gift);
        }
        $wave = $waves->beginWave();
        $this->printing->note(__('升级波次已固化：%{1}（SYS %{2} · Theme %{3}）', [
            (string)($wave['wave_id'] ?? ''),
            (string)($wave['system_version_to'] ?? ''),
            (string)($wave['theme_version_to'] ?? ''),
        ]));

        Env::getInstance()->setConfig('system.maintenance', true);
        $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
        $locales = (new MaintenanceStaticGenerator())->publishAll($retryAfter);
        $this->printing->note(__('维护静态页已生成：%{1}', [\implode(', ', $locales)]));
        $this->printing->success(__('维护模式已开启！'));
        WlsMaintenanceSync::syncAfterCliToggle($this->printing, true, $args);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '开启维护模式';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-n, --name' => __('指定 WLS 实例名；省略则向当前所有运行中的实例同步维护入口'),
            ],
            [],
            []
        );
    }
}
