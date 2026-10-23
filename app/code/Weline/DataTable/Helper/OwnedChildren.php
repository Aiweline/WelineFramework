<?php

declare(strict_types=1);

namespace Weline\DataTable\Helper;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

/**
 * DataTable 自编译子内容标签（d-table / t-header / t-filter / d-form / field）处理子源码的统一入口。
 */
final class OwnedChildren
{
    /**
     * @param array<string,mixed> $config 标签回调收到的 $config（含渲染阶段）
     * @param array<string,mixed> $parentContext 子标签编译时会读取的父上下文
     */
    public static function process(string $source, array $config, mixed $template, array $parentContext): string
    {
        if (!$template instanceof Template) {
            if (self::isRuntime($config)) {
                throw new Exception(__('运行期渲染 DataTable 子内容缺少当前模板实例。'));
            }
            $template = w_obj(Template::class);
        }

        return ObjectManager::getInstance(Taglib::class)->processOwnedChildren(
            $template,
            $source,
            $config,
            \hash('xxh3', \serialize($parentContext))
        );
    }

    /** @param array<string,mixed> $config */
    public static function isRuntime(array $config): bool
    {
        return ($config[Taglib::RENDER_PHASE_KEY] ?? '') === Taglib::RENDER_PHASE_RUNTIME;
    }
}
