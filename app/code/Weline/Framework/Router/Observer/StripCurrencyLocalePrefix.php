<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Observer;

use Weline\Framework\App\Env;
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
        $stripCount = 0;
        $hasCurrency = false;
        $hasLanguage = false;
        foreach (array_slice($segments, 0, 2) as $segment) {
            if (!$hasCurrency && self::isCurrencySegment($segment)) {
                $stripCount++;
                $hasCurrency = true;
                continue;
            }
            if (!$hasLanguage && self::isLocaleSegment($segment)) {
                $stripCount++;
                $hasLanguage = true;
                continue;
            }
            break;
        }

        if ($stripCount > 0) {
            $data->setData('path', implode('/', array_slice($segments, $stripCount)));
        }
    }

    private static function isCurrencySegment(string $segment): bool
    {
        return strlen($segment) === 3
            && $segment === strtoupper($segment)
            && ctype_alpha($segment)
            && !Env::isAreaRoutePathSegment($segment);
    }

    private static function isLocaleSegment(string $segment): bool
    {
        $segment = str_replace('-', '_', $segment);

        return preg_match('/^[a-z]{2}_[A-Za-z]{2,4}(?:_[A-Z]{2})?$/', $segment) === 1;
    }
}
