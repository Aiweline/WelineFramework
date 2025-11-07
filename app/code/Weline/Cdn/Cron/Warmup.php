<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Cron;

use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN预热定时任务
 * 
 * 每5分钟执行一次，执行预热任务
 * 
 * @package Weline_Cdn
 */
class Warmup
{
    private WarmupProviderScanner $providerScanner;
    private WarmupRunner $warmupRunner;
    private EventsManager $eventsManager;

    public function __construct(
        WarmupProviderScanner $providerScanner,
        WarmupRunner $warmupRunner,
        EventsManager $eventsManager
    ) {
        $this->providerScanner = $providerScanner;
        $this->warmupRunner = $warmupRunner;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 执行预热任务
     * 
     * @return void
     */
    public function execute(): void
    {
        // 1. 从Provider收集URL
        $urls = $this->providerScanner->collectUrls();
        
        // 2. 如果收集到URL，通过事件投递
        if (!empty($urls)) {
            $event = new Event('Weline_Cdn::send_warmup', [
                'module' => 'Weline_Cdn',
                'provider' => 'scanner',
                'urls' => $urls,
                'dedupe' => true
            ]);
            $this->eventsManager->dispatch('Weline_Cdn::send_warmup', $event);
        }

        // 3. 执行预热任务
        $result = $this->warmupRunner->run(50);
        
        // 记录日志
        if (defined('CLI') && CLI) {
            echo sprintf(
                "预热任务执行完成: 处理 %d 个URL, 成功 %d 个, 失败 %d 个\n",
                $result['processed'],
                $result['success'],
                $result['fail']
            );
        }
    }
}

