<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

class PathFallbackMatcher implements MatcherInterface
{
    public function match(string $rawRequest): ?array
    {
        if (\preg_match('/^\w+\s+([^\s]+)/', $rawRequest, $m) !== 1) {
            return null;
        }
        $path = (string)(\parse_url($m[1], \PHP_URL_PATH) ?? '');
        if ($path === '') {
            return null;
        }
        $pathLower = \strtolower($path);
        $segments = \array_values(\array_filter(\explode('/', \trim($pathLower, '/')), static fn ($s) => $s !== ''));
        $containsSegment = static function (array $segmentList, string $keyword): bool {
            foreach ($segmentList as $seg) {
                if ($seg === $keyword || \str_starts_with($seg, $keyword . '-') || \str_ends_with($seg, '-' . $keyword)) {
                    return true;
                }
            }

            return false;
        };

        // 常见 SSE 路径命名：…/sse、…/stream-sse、…/event-stream、…/eventsource（不依赖 Accept 头）
        if ($containsSegment($segments, 'sse')
            || $containsSegment($segments, 'event-stream')
            || $containsSegment($segments, 'eventsource')) {
            return ['is_long_lived' => true, 'layer' => 'layer-3-path-fallback', 'protocol' => 'sse'];
        }
        if ($containsSegment($segments, 'websocket') || $containsSegment($segments, 'socket')) {
            return ['is_long_lived' => true, 'layer' => 'layer-3-path-fallback', 'protocol' => 'websocket'];
        }
        if ($containsSegment($segments, 'webrtc')
            || $containsSegment($segments, 'rtc')
            || $containsSegment($segments, 'signal')) {
            return ['is_long_lived' => true, 'layer' => 'layer-3-path-fallback', 'protocol' => 'webrtc-signaling'];
        }

        return null;
    }
}

