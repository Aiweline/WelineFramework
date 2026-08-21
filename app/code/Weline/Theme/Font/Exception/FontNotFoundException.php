<?php

declare(strict_types=1);

namespace Weline\Theme\Font\Exception;

class FontNotFoundException extends \Exception
{
    public function __construct(string $fontPath)
    {
        parent::__construct('Font not found in: ' . $fontPath);
    }
}
