<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Acl\Api\Resource\WhitelistServiceInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 子站直进令牌消费入口需免登录可达（主站签发后 302 到子站）。
 */
final class BackendWhitelistUrl implements ObserverInterface
{
    public const CONSUME_PATH = 'websites/admin/website/consume-backend-entry';
    public const CONSUME_PATH_ALIASES = [
        'websites/admin/website/consume-backend-entry',
        'websites/admin/website/get-consume-backend-entry',
    ];

    public function execute(Event &$event): void
    {
        /** @var WhitelistServiceInterface $whitelistService */
        $whitelistService = ObjectManager::getInstance(WhitelistServiceInterface::class);
        $whitelistService->upsertPaths(self::CONSUME_PATH_ALIASES);
        // RouteBefore 缓存 backend_white_acl_sources；upsert 后必须失效，否则会继续按旧名单拦 consume。
        w_cache('acl')->delete('backend_white_acl_sources');

        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        $whitelist = $data->getData('whitelist_url');
        if (!\is_array($whitelist)) {
            $whitelist = [];
        }
        foreach (self::CONSUME_PATH_ALIASES as $path) {
            $whitelist[] = $path;
        }
        $data->setData('whitelist_url', \array_values(\array_unique($whitelist)));
    }
}
