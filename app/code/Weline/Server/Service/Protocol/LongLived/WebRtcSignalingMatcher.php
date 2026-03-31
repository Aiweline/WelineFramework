<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

class WebRtcSignalingMatcher implements MatcherInterface
{
    public function match(string $rawRequest): ?array
    {
        if (\preg_match('/\r\nSec-WebSocket-Protocol:\s*([^\r\n]+)/i', $rawRequest, $m) === 1) {
            $protocol = \strtolower((string)$m[1]);
            if (\str_contains($protocol, 'webrtc')
                || \str_contains($protocol, 'rtc')
                || \str_contains($protocol, 'signal')) {
                return ['is_long_lived' => true, 'layer' => 'layer-1-header', 'protocol' => 'webrtc-signaling'];
            }
        }

        if (\preg_match('/\r\nContent-Type:\s*([^\r\n]+)/i', $rawRequest, $m) === 1) {
            $ct = \strtolower((string)$m[1]);
            if (\str_contains($ct, 'application/sdp')
                || \str_contains($ct, 'webrtc')
                || \str_contains($ct, 'trickle-ice')) {
                return ['is_long_lived' => true, 'layer' => 'layer-2-content-type', 'protocol' => 'webrtc-signaling'];
            }
        }

        return null;
    }
}

