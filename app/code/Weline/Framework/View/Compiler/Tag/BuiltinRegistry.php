<?php

declare(strict_types=1);

/**
 * 已转正/已实现的内置标签注册表工厂。
 *
 * 目的：把「哪些内置标签已经能走 v3」收敛为**一个地方**，避免调用方各自拼 registry
 * （拼错就会静默降级成原文，却看起来“跑通了”）。新增一组标签只改这里。
 *
 * 注意：这里只放**已通过语料 S1 的组**。`local`/`js:part` 是模块标签，
 * 走 `ModuleTagBridge` 而不是本名单；`block` 是内置编译期标签，已在此注册。
 * `hook` 只覆盖「无实现 → 空串」这一条，有实现会显式抛错。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag;

use Weline\Framework\View\Compiler\Tag\Builtin\BackendUrlTag;
use Weline\Framework\View\Compiler\Tag\Builtin\BlockTag;
use Weline\Framework\View\Compiler\Tag\Builtin\ConditionTag;
use Weline\Framework\View\Compiler\Tag\Builtin\ControlFlowTag;
use Weline\Framework\View\Compiler\Tag\Builtin\CsrfTag;
use Weline\Framework\View\Compiler\Tag\Builtin\CssTag;
use Weline\Framework\View\Compiler\Tag\Builtin\FormTag;
use Weline\Framework\View\Compiler\Tag\Builtin\HookTag;
use Weline\Framework\View\Compiler\Tag\Builtin\JsTag;
use Weline\Framework\View\Compiler\Tag\Builtin\LangTag;
use Weline\Framework\View\Compiler\Tag\Builtin\PhpTag;
use Weline\Framework\View\Compiler\Tag\Builtin\StaticTag;
use Weline\Framework\View\Compiler\Tag\Builtin\StringTag;
use Weline\Framework\View\Compiler\Tag\Builtin\TemplateTag;
use Weline\Framework\View\Compiler\Tag\Builtin\UrlTag;
use Weline\Framework\View\Compiler\Tag\Builtin\VarTag;

final class BuiltinRegistry
{
    /**
     * 已实现的内置标签描述符。
     *
     * @return list<TagDescriptor>
     */
    public static function descriptors(): array
    {
        return [
            LangTag::descriptor(),
            JsTag::descriptor(),
            CssTag::descriptor(),
            CsrfTag::descriptor(),
            PhpTag::descriptor(),
            TemplateTag::descriptor(),
            FormTag::descriptor(),
            VarTag::descriptor(),
            StringTag::descriptor(),
            BlockTag::descriptor(),
            HookTag::descriptor(),
            StaticTag::descriptor(),
            BackendUrlTag::descriptor(),
            BackendUrlTag::descriptor('admin-url'),
            UrlTag::descriptor(),
            UrlTag::descriptor('frontend-url', 'getFrontendUrl'),
            UrlTag::descriptor('api', 'getApi'),
            ...ControlFlowTag::descriptors(),
            ...ConditionTag::descriptors(),
        ];
    }

    public static function build(): Registry
    {
        return new Registry(self::descriptors());
    }

    /**
     * 已实现标签名（含 `w:` 前缀等价写法由 Registry 负责）。
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::descriptors() as $descriptor) {
            $names[] = $descriptor->name;
        }

        return \array_values(\array_unique($names));
    }
}
