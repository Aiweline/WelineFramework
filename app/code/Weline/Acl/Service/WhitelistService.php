<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Resource\WhitelistServiceInterface;
use Weline\Acl\Model\WhiteAclSource;
use Weline\Framework\Manager\ObjectManager;

final class WhitelistService implements WhitelistServiceInterface
{
    public function listPaths(string $type = 'pc'): array
    {
        /** @var WhiteAclSource $source */
        $source = ObjectManager::getInstance(WhiteAclSource::class, [], false);
        $rows = $source->fields(WhiteAclSource::schema_fields_PATH)
            ->where(WhiteAclSource::schema_fields_TYPE, $type)
            ->select()
            ->fetchArray();
        $paths = [];
        foreach ($rows as $row) {
            $path = trim((string)($row[WhiteAclSource::schema_fields_PATH] ?? ''));
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return array_values(array_unique($paths));
    }

    public function upsertPaths(array $paths, string $type = 'pc'): void
    {
        $rows = [];
        foreach ($paths as $path) {
            $path = trim((string)$path, '/');
            if ($path !== '') {
                $rows[] = [
                    WhiteAclSource::schema_fields_PATH => $path,
                    WhiteAclSource::schema_fields_TYPE => $type,
                ];
            }
        }
        if ($rows === []) {
            return;
        }
        /** @var WhiteAclSource $source */
        $source = ObjectManager::getInstance(WhiteAclSource::class, [], false);
        $source->insert($rows, WhiteAclSource::schema_fields_PATH)->fetch();
    }
}
