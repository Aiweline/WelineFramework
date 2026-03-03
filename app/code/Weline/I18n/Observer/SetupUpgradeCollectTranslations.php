<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/01 00:00:00
 */

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;
use Weline\I18n\Model\I18n;

/**
 * 系统升级后自动收集语言包观察者
 * 监听 Weline_Framework_Setup::upgrade_after 事件
 */
class SetupUpgradeCollectTranslations implements ObserverInterface
{
    private I18n $i18n;

    public function __construct(I18n $i18n)
    {
        $this->i18n = $i18n;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 检查是否是部分更新模式
        $eventData = $event->getData();
        $isPartialUpgrade = $eventData['is_partial_upgrade'] ?? false;
        
        // 如果是部分更新模式，跳过语言包收集（语言包收集应该在完整升级时执行）
        if ($isPartialUpgrade) {
            return;
        }
        
        try {
            if (php_sapi_name() === 'cli') {
                echo "\n[I18n] 正在收集语言包...\n";
            }
            
            // 收集语言包（不使用缓存，确保最新）
            // 这会自动注册并激活对应的 locale 和国家
            $this->i18n->convertToLanguageFile(false);
            
            // 清理翻译缓存，确保新翻译生效
            try {
                w_cache('i18n')->clear();
                w_cache('phrase')->clear();
            } catch (\Exception $e) {
                // 缓存清理失败不影响主流程
                if (php_sapi_name() === 'cli') {
                    echo "[I18n] 翻译缓存清理失败：" . $e->getMessage() . "\n";
                }
            }
            
            if (php_sapi_name() === 'cli') {
                echo "[I18n] 语言包收集完成！\n";
            }
            
        } catch (\Exception $e) {
            $errorMsg = 'I18n: 系统升级后语言包收集失败 - ' . $e->getMessage();
            w_log_error($errorMsg, [], 'i18n');
            if (php_sapi_name() === 'cli') {
                echo "[I18n] 语言包收集失败：" . $e->getMessage() . "\n";
            }
        }
    }
}

