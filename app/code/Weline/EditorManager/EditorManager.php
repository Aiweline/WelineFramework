<?php

namespace Weline\EditorManager;

use Weline\Framework\DataObject\DataObject;

abstract class EditorManager extends DataObject implements EditorManagerInterface
{
    use  EditorManagerTrait;
}
