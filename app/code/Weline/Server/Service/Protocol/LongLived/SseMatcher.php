<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

class SseMatcher implements MatcherInterface
{
    public function match(string $rawRequest): ?array
    {
        if (\preg_match('/\r\nAccept:\s*([^\r\n]+)/i', $rawRequest, $m) !== 1) {
            return null;
        }
        $accept = \strtolower((string)$m[1]);
        if (!\str_contains($accept, 'text/event-stream')) {
            return null;
        }
        return ['is_long_lived' => true, 'layer' => 'layer-1-header', 'protocol' => 'sse'];
    }
}

