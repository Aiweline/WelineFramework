<?php

declare(strict_types=1);

namespace Weline\EditorManager\Api\Editor;

use Weline\Framework\DataObject\DataObject;

/**
 * Stable base class for editor adapters contributed by other modules.
 *
 * It owns only the public adapter contract and framework data container; no
 * EditorManager internal concrete class leaks into the consumer hierarchy.
 */
abstract class EditorManager extends DataObject implements EditorManagerInterface
{
    use EditorManagerTrait;
}
