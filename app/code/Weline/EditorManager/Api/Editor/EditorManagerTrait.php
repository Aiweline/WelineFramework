<?php

declare(strict_types=1);

namespace Weline\EditorManager\Api\Editor;

trait EditorManagerTrait
{
    public function getTarget(): string
    {
        return $this->getData('target') ?? '';
    }
}
