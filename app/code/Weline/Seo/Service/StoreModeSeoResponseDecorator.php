<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\RequestContext;

/**
 * Adds the non-production indexing hard gate to every final HTTP response path.
 */
final class StoreModeSeoResponseDecorator
{
    public const HEADER_NAME = 'X-Robots-Tag';
    public const HEADER_VALUE = 'noindex, nofollow';

    public function __construct(
        private readonly StoreModeSeoHardGate $storeModeGate,
    ) {
    }

    public function decorate(mixed $result): mixed
    {
        if ((!$result instanceof Response && !\is_string($result))
            || !$this->storeModeGate->isHardNoIndexMode()
            || !$this->isEligibleRequest()) {
            return $result;
        }

        $response = $result instanceof Response ? $result : new Response();
        if (\is_string($result)) {
            $response->setBody($result);
        }
        $response->setHeader(self::HEADER_NAME, self::HEADER_VALUE);

        return $response;
    }

    private function isEligibleRequest(): bool
    {
        if (RequestContext::getWelineArea() !== RequestContext::AREA_FRONTEND) {
            return false;
        }

        if (!\in_array(WelineEnv::getRequestMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return false;
        }

        return !(bool)$context->get('route.is_static', false)
            && !(bool)$context->get('route.is_media', false);
    }
}
