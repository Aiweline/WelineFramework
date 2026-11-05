<?php

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

/**
 * 路由语言段校验：只回答「该语言是否已安装且启用」。
 *
 * 答案来自 {@see ActiveLocaleCodeProvider}（进程袋 + 命名空间代次失效），
 * 本类不再自己拼 `Locals` 查询、也不再维护一份 `all_languages_list` 缓存：
 * 同一事实的第二条读路径与第二套失效点，正是旧实现里语言装了却要重启才生效的来源。
 */
class DetectLanguage implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $code = \trim((string)$data->getData('code'));
        if ($code === '') {
            $data->setData('result', false);
            return;
        }

        /** @var ActiveLocaleCodeProvider $active */
        $active = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
        $data->setData('result', isset($active->getInstalledActiveCodeMap()[\strtolower($code)]));
    }
}
