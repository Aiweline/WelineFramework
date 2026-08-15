<?php

declare(strict_types=1);

namespace Weline\Inquiry\Api;

interface InquiryRendererInterface
{
    /** @param array<string,mixed> $options */
    public function render(string $code, array $options = []): string;
}
