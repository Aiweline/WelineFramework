<?php

declare(strict_types=1);

namespace Weline\Component\Api;

/** Public rendering boundary for Component-owned OffCanvas blocks. */
interface OffCanvasRendererInterface
{
    /** @param array<string,mixed> $data */
    public function render(array $data): string;
}
