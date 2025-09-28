<?php
declare(strict_types=1);

namespace Weline\Ai\Service;

/**
 * 内容安全服务
 */
class ContentSafetyService
{
    public function checkContent(string $content): array
    {
        return ['safe' => true, 'level' => 'low'];
    }
}
