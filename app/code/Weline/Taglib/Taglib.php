<?php

declare(strict_types=1);

namespace Weline\Taglib;

/**
 * @deprecated Use \Weline\Framework\Taglib\AttributeCodeCompiler.
 */
class Taglib
{
    public static function attributes(array &$attributes): string
    {
        return \Weline\Framework\Taglib\AttributeCodeCompiler::attributes($attributes);
    }
}
