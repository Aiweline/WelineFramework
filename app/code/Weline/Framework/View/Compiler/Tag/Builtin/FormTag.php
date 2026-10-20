<?php

declare(strict_types=1);

/**
 * `<w:form>` 只登记 runtime 阶段（不提供编译期 lower）。
 *
 * 旧链对 `w:form` 命中 `w` 命名空间 wildcard → `StageResolver` 判 runtime：
 * 产物是 `FiberOutputBuffer` 捕获 + `renderRuntimeTag(...)`，不是编译期 HTML。
 * 这里只负责让 Registry/Matcher 认识它；真正的下发改由 `Lower::lowerRuntimeTag()`。
 *
 * `wOnly=true`：原生 `<form>` 不能被当成模板标签。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Tag\AttributeSpec;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class FormTag
{
    public static function descriptor(): TagDescriptor
    {
        return new TagDescriptor(
            name: 'form',
            attributes: [
                'attributes' => AttributeSpec::any(),
                'id' => AttributeSpec::any(),
                'method' => AttributeSpec::any(),
                'action' => AttributeSpec::any(),
                'class' => AttributeSpec::any(),
                'enctype' => AttributeSpec::any(),
                'autocomplete' => AttributeSpec::any(),
                'name' => AttributeSpec::any(),
                'target' => AttributeSpec::any(),
                'rel' => AttributeSpec::any(),
                'accept-charset' => AttributeSpec::any(),
                'role' => AttributeSpec::any(),
                'style' => AttributeSpec::any(),
                'novalidate' => AttributeSpec::any(),
                'hidden' => AttributeSpec::any(),
                'intent' => AttributeSpec::any(),
                'csrf' => AttributeSpec::any(),
                'captcha' => AttributeSpec::any(),
            ],
            paired: true,
            selfClosing: false,
            stage: TagDescriptor::STAGE_RUNTIME,
            elementForm: true,
            inlineForm: false,
            wOnly: true,
        );
    }
}
