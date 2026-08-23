<?php

declare(strict_types=1);

namespace Weline\Framework\Taglib;

/**
 * Marks a composite tag whose callback must establish its own context before
 * compiling nested tags.
 *
 * The template compiler passes the original child source to these tags. The
 * callback is then responsible for compiling that source exactly once.
 */
interface OwnsChildCompilationInterface
{
}
