<?php

declare(strict_types=1);

namespace Weline\CacheManager\Api;

use Weline\CacheManager\Model\Cache;
use Weline\Framework\Cache\Contract\CacheStatusProviderInterface;
use Weline\Framework\Manager\ObjectManager;

final class CacheStatusProvider implements CacheStatusProviderInterface
{
    public function all(): array
    {
        $status = [];
        foreach (ObjectManager::getInstance(Cache::class)->select()->fetchIterator() as $item) {
            $identity = is_array($item) ? ($item['identity'] ?? null) : $item->getData('identity');
            if ($identity !== null && $identity !== '') {
                $status[(string)$identity] = (int)(is_array($item) ? ($item['status'] ?? 0) : $item->getData('status'));
            }
        }
        return $status;
    }

    public function get(string $identity): ?int
    {
        $model = ObjectManager::getInstance(Cache::class)->where('identity', $identity)->find()->fetch();
        return $model && $model->getId() ? (int)$model->getData('status') : null;
    }
}
