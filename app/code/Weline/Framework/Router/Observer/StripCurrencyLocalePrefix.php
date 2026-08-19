<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Observer;

use Weline\Framework\App\State;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * process_uri_before 时剥离路径开头的 货币/语言 前缀
 *
 * 当 path 仍包含 /CNY/zh_Hans_CN/ 等形式时，Url::parser 可能尚未执行或未写回 REQUEST_URI，
 * 导致路由表按纯路径（如 customerservice/frontend/chat/service-status）查找失败。
 * 本观察者作为兜底， Strip 前两段（货币+语言）使路由能正确匹配。
 */
class StripCurrencyLocalePrefix implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        /** @var DataObject $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $path = $data->getData('path');
        if (!is_string($path) || $path === '') {
            return;
        }

        $path = trim($path, '/');
        if ($path === '') {
            return;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $segment): bool => $segment !== ''
        ));
        $localized = State::resolveLocalizationFromPathSegments($segments);
        if ((int)$localized['consumed'] > 0) {
            $remaining = $localized['remaining'];
            if ((int)$localized['area_offset'] === 1) {
                array_unshift($remaining, $segments[0]);
            }
            $data->setData('path', implode('/', $remaining));
        }
    }
}
