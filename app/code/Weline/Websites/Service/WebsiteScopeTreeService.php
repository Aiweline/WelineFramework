<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Acl\Taglib\Acl;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Website → Store → Channel 管理树只读组装（对齐分类左树右编）。
 *
 * @phpstan-type TreeNode array{
 *     kind:string,
 *     id:int,
 *     node:string,
 *     code:string,
 *     name:string,
 *     logo:string,
 *     acl_ok:bool,
 *     children:list<array>
 * }
 * @phpstan-type Selection array{
 *     kind:string,
 *     id:int,
 *     node:string,
 *     acl_ok:bool,
 *     website_id:int,
 *     store_id:int,
 *     channel_id:int,
 *     entity:array<string,mixed>,
 *     error:string
 * }
 */
final class WebsiteScopeTreeService
{
    public const ACL_WEBSITE_EDIT = 'Weline_Websites::website_edit';
    public const ACL_STORE = 'Weline_Websites::store_management';
    public const ACL_CHANNEL = 'Weline_Websites::sales_channel_management';

    public function __construct(
        private readonly WebsiteCatalogInterface $websites,
        private readonly WebsiteStoreChannelDirectory $directory,
        private readonly WebsiteScopeTreeBrandLogoResolver $logos,
    ) {
    }

    /**
     * @return list<TreeNode>
     */
    public function buildTree(string $search = ''): array
    {
        $needle = mb_strtolower(trim($search));
        $canEditWebsite = Acl::hasPermissionQuiet(self::ACL_WEBSITE_EDIT)
            || Acl::hasPermissionQuiet('Weline_Websites::website');
        $canStore = Acl::hasPermissionQuiet(self::ACL_STORE);
        $canChannel = Acl::hasPermissionQuiet(self::ACL_CHANNEL);

        $tree = [];
        foreach ($this->websites->all() as $website) {
            $name = $website->name;
            $code = $website->code;
            if ($needle !== '') {
                $hay = mb_strtolower($name . ' ' . $code);
                if (!str_contains($hay, $needle)) {
                    continue;
                }
            }
            $websiteId = $website->id;
            $websiteLogo = $this->logos->forWebsite($websiteId, $code);
            $stores = [];
            foreach ($this->directory->forWebsite($websiteId) as $storeRow) {
                $storeId = (int)($storeRow['store_id'] ?? 0);
                $storeCode = (string)($storeRow['code'] ?? '');
                $storeMode = (string)($storeRow['store_mode'] ?? ScopeIdentity::MODE_NORMAL);
                $storeLogo = $this->logos->forStore($websiteId, $code, $storeCode, $storeMode);
                if ($storeLogo === '') {
                    $storeLogo = $websiteLogo;
                }
                $channels = [];
                foreach ($storeRow['channels'] ?? [] as $channelRow) {
                    if (!is_array($channelRow)) {
                        continue;
                    }
                    $channelId = (int)($channelRow['channel_id'] ?? 0);
                    $channelCode = (string)($channelRow['code'] ?? '');
                    $channelLogo = $this->logos->forChannel(
                        $websiteId,
                        $code,
                        $storeCode,
                        $channelCode,
                        $storeMode,
                    );
                    if ($channelLogo === '') {
                        $channelLogo = $storeLogo;
                    }
                    $channels[] = [
                        'kind' => 'channel',
                        'id' => $channelId,
                        'node' => self::formatNode('channel', $channelId),
                        'code' => $channelCode,
                        'name' => (string)($channelRow['name'] ?? ''),
                        'logo' => $channelLogo,
                        'acl_ok' => $canChannel,
                        'children' => [],
                    ];
                }
                $stores[] = [
                    'kind' => 'store',
                    'id' => $storeId,
                    'node' => self::formatNode('store', $storeId),
                    'code' => $storeCode,
                    'name' => (string)($storeRow['name'] ?? ''),
                    'logo' => $storeLogo,
                    'acl_ok' => $canStore,
                    'children' => $channels,
                ];
            }
            $tree[] = [
                'kind' => 'website',
                'id' => $websiteId,
                'node' => self::formatNode('website', $websiteId),
                'code' => $code,
                'name' => $name,
                'logo' => $websiteLogo,
                'acl_ok' => $canEditWebsite,
                'children' => $stores,
            ];
        }

        return $tree;
    }

    /**
     * @param list<TreeNode> $tree
     * @return Selection
     */
    public function resolveSelection(string $nodeRaw, string $focus, array $tree, string $newKind = ''): array
    {
        $parsed = self::parseNode($nodeRaw);
        if ($parsed === null && $focus !== '') {
            $parsed = $this->resolveFocus($focus, $tree);
        }
        if ($parsed === null) {
            $defaultId = $this->websites->defaultWebsiteId();
            $parsed = ['kind' => 'website', 'id' => $defaultId];
            if ($tree !== [] && !$this->findInTree($tree, 'website', $defaultId)) {
                $parsed = ['kind' => 'website', 'id' => (int)$tree[0]['id']];
            }
        }

        $kind = $parsed['kind'];
        $id = $parsed['id'];
        $node = self::formatNode($kind, $id);
        $found = $this->findInTree($tree, $kind, $id);

        $selection = [
            'kind' => $kind,
            'id' => $id,
            'node' => $node,
            'acl_ok' => (bool)($found['acl_ok'] ?? false),
            'website_id' => 0,
            'store_id' => 0,
            'channel_id' => 0,
            'entity' => [],
            'error' => '',
        ];

        if ($found === null && $tree !== []) {
            $selection['error'] = (string)__('节点不存在或无权访问');
            $selection['acl_ok'] = false;
            return $selection;
        }

        if ($kind === 'website') {
            $selection['website_id'] = $id;
            $selection['entity'] = [
                'website_id' => $id,
                'code' => (string)($found['code'] ?? ''),
                'name' => (string)($found['name'] ?? ''),
            ];
        } elseif ($kind === 'store') {
            $selection['store_id'] = $id;
            $parentWebsite = $this->findParentWebsiteId($tree, 'store', $id);
            $selection['website_id'] = $parentWebsite;
            $selection['entity'] = is_array($found) ? [
                'store_id' => $id,
                'website_id' => $parentWebsite,
                'code' => (string)($found['code'] ?? ''),
                'name' => (string)($found['name'] ?? ''),
            ] : [];
        } elseif ($kind === 'channel') {
            $selection['channel_id'] = $id;
            $parent = $this->findParentStoreAndWebsite($tree, $id);
            $selection['store_id'] = $parent['store_id'];
            $selection['website_id'] = $parent['website_id'];
            $selection['entity'] = is_array($found) ? [
                'channel_id' => $id,
                'store_id' => $parent['store_id'],
                'website_id' => $parent['website_id'],
                'code' => (string)($found['code'] ?? ''),
                'name' => (string)($found['name'] ?? ''),
            ] : [];
        }

        $newKind = strtolower(trim($newKind));
        if ($newKind === 'store' && $kind === 'website') {
            $selection['kind'] = 'new_store';
            $selection['acl_ok'] = Acl::hasPermissionQuiet(self::ACL_STORE);
        } elseif ($newKind === 'channel' && $kind === 'store') {
            $selection['kind'] = 'new_channel';
            $selection['acl_ok'] = Acl::hasPermissionQuiet(self::ACL_CHANNEL);
        }

        return $selection;
    }

    /**
     * @return array{kind:string,id:int}|null
     */
    public static function parseNode(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || !preg_match('/^(website|store|channel):(-?\d+)$/', $raw, $m)) {
            return null;
        }
        return [
            'kind' => $m[1],
            'id' => (int)$m[2],
        ];
    }

    public static function formatNode(string $kind, int $id): string
    {
        return $kind . ':' . $id;
    }

    /**
     * @param list<TreeNode> $tree
     * @return array{kind:string,id:int}|null
     */
    private function resolveFocus(string $focus, array $tree): ?array
    {
        $focus = strtolower(trim($focus));
        if ($tree === []) {
            return null;
        }
        $website = $tree[0];
        $defaultId = $this->websites->defaultWebsiteId();
        foreach ($tree as $node) {
            if ((int)$node['id'] === $defaultId) {
                $website = $node;
                break;
            }
        }
        if ($focus === 'stores') {
            $children = $website['children'] ?? [];
            if ($children !== []) {
                return ['kind' => 'store', 'id' => (int)$children[0]['id']];
            }
            return ['kind' => 'website', 'id' => (int)$website['id']];
        }
        if ($focus === 'channels') {
            foreach ($website['children'] ?? [] as $store) {
                $channels = $store['children'] ?? [];
                if ($channels !== []) {
                    return ['kind' => 'channel', 'id' => (int)$channels[0]['id']];
                }
            }
            return ['kind' => 'website', 'id' => (int)$website['id']];
        }
        return ['kind' => 'website', 'id' => (int)$website['id']];
    }

    /**
     * @param list<TreeNode> $tree
     * @return TreeNode|null
     */
    private function findInTree(array $tree, string $kind, int $id): ?array
    {
        foreach ($tree as $website) {
            if ($kind === 'website' && (int)$website['id'] === $id) {
                return $website;
            }
            foreach ($website['children'] ?? [] as $store) {
                if ($kind === 'store' && (int)$store['id'] === $id) {
                    return $store;
                }
                foreach ($store['children'] ?? [] as $channel) {
                    if ($kind === 'channel' && (int)$channel['id'] === $id) {
                        return $channel;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param list<TreeNode> $tree
     */
    private function findParentWebsiteId(array $tree, string $childKind, int $childId): int
    {
        foreach ($tree as $website) {
            foreach ($website['children'] ?? [] as $store) {
                if ($childKind === 'store' && (int)$store['id'] === $childId) {
                    return (int)$website['id'];
                }
                if ($childKind === 'channel') {
                    foreach ($store['children'] ?? [] as $channel) {
                        if ((int)$channel['id'] === $childId) {
                            return (int)$website['id'];
                        }
                    }
                }
            }
        }
        return 0;
    }

    /**
     * @param list<TreeNode> $tree
     * @return array{store_id:int,website_id:int}
     */
    private function findParentStoreAndWebsite(array $tree, int $channelId): array
    {
        foreach ($tree as $website) {
            foreach ($website['children'] ?? [] as $store) {
                foreach ($store['children'] ?? [] as $channel) {
                    if ((int)$channel['id'] === $channelId) {
                        return [
                            'store_id' => (int)$store['id'],
                            'website_id' => (int)$website['id'],
                        ];
                    }
                }
            }
        }
        return ['store_id' => 0, 'website_id' => 0];
    }
}
