<?php

declare(strict_types=1);

namespace Weline\EditorManager\Api\Editor;

/** Public extension contract for editor adapters. */
interface EditorManagerInterface
{
    public static function name(): string;

    public function render(): string;
}
