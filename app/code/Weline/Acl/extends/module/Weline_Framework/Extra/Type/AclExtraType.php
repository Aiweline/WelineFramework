<?php

declare(strict_types=1);

namespace Weline\Acl\Extends\Module\Weline_Framework\Extra\Type;

use Weline\Framework\Controller\Extra\ExtraTypeProviderInterface;

/**
 * Controller @Extra type=acl — 声明后台动作 ACL 文案与 source_id。
 */
final class AclExtraType implements ExtraTypeProviderInterface
{
    public function type(): string
    {
        return 'acl';
    }

    public function description(): string
    {
        return '控制器动作 ACL 声明（message/source；由 Acl 收集进权限目录）';
    }

    public function normalize(array $attrs): array
    {
        $message = trim((string)($attrs['message'] ?? ''));
        $source = trim((string)($attrs['source'] ?? ''));

        return [
            'type' => 'acl',
            'message' => $message,
            'source' => $source,
        ];
    }
}
