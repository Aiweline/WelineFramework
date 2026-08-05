<?php

declare(strict_types=1);

namespace Weline\Storage\Service;

use Weline\Cdn\Service\MediaUrlCowResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\StorageCatalogInterface;

final class StorageCatalog implements StorageCatalogInterface
{
    public function __construct(private readonly StorageManager $storageManager)
    {
    }

    public function all(?ScopeIdentity $scope = null): array
    {
        $list = $this->storageManager->getStorageList();
        $out = [];
        foreach ($list as $item) {
            $info = \is_array($item['info'] ?? null) ? $item['info'] : [];
            $item['info'] = $this->redactSecrets($info);
            if ($scope !== null) {
                $item['media_base_url'] = $this->resolveMediaBase($scope, (string)($info['base_url'] ?? '/pub/media'));
            }
            $out[] = $item;
        }

        return $out;
    }

    private function resolveMediaBase(ScopeIdentity $scope, string $shared): string
    {
        try {
            /** @var MediaUrlCowResolver $cow */
            $cow = ObjectManager::getInstance(MediaUrlCowResolver::class);

            return \rtrim($cow->resolveCowMediaUrl('', $scope, $shared), '/');
        } catch (\Throwable) {
            return \rtrim($shared, '/');
        }
    }

    /**
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private function redactSecrets(array $info): array
    {
        $denied = [
            'secret', 'access_key_secret', 'secret_key', 'password', 'token',
            'credentials', 'secret_ref', 'private_key', 'api_key',
        ];
        foreach ($denied as $key) {
            if (\array_key_exists($key, $info)) {
                unset($info[$key]);
                $info['has_secret'] = true;
            }
        }

        return $info;
    }
}
