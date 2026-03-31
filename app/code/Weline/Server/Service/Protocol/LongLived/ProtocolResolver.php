<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

class ProtocolResolver
{
    /** @var MatcherInterface[] */
    private array $matchers;

    public function __construct(?array $matchers = null)
    {
        $this->matchers = $matchers ?? [
            new SseMatcher(),
            new WebSocketMatcher(),
            new WebRtcSignalingMatcher(),
            new PathFallbackMatcher(),
        ];
    }

    /**
     * @return array{is_long_lived: bool, layer: string, protocol: string}
     */
    public function detect(string $rawRequest): array
    {
        foreach ($this->matchers as $matcher) {
            $matched = $matcher->match($rawRequest);
            if ($matched !== null) {
                return $matched;
            }
        }
        return ['is_long_lived' => false, 'layer' => 'none', 'protocol' => 'http'];
    }
}

