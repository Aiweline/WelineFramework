<?php

namespace Weline\EditorManager;

trait EditorManagerTrait
{
    public function getTarget(): string
    {
        return $this->getData('target') ?? '';
    }
}
