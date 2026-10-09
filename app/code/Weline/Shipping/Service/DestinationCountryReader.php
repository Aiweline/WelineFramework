<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

/**
 * 店面「配送至」国码，与 Checkout session/cookie 同源。
 */
class DestinationCountryReader
{
    public const SESSION_COUNTRY = 'checkout_delivery_country_code';
    public const COOKIE_LOCATION = 'weline_delivery_location';

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    public function currentCountryCode(string $fallback = ''): string
    {
        $fromSession = $this->fromSession();
        if ($fromSession !== '') {
            return $fromSession;
        }
        $fromCookie = $this->fromCookie();
        if ($fromCookie !== '') {
            return $fromCookie;
        }

        return StorefrontOfferOriginCountryService::normalizeCountryCode($fallback);
    }

    private function fromSession(): string
    {
        try {
            /** @var SessionFactory $factory */
            $factory = $this->objectManager->getInstance(SessionFactory::class);
            $session = $factory->getFrontendSession();
            $code = StorefrontOfferOriginCountryService::normalizeCountryCode(
                (string)$session->get(self::SESSION_COUNTRY)
            );
            if ($code !== '') {
                return $code;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function fromCookie(): string
    {
        try {
            // Cookie::set 经 CookieScope 写限定名；须用 Cookie::get，禁止裸 $_COOKIE。
            $raw = Cookie::get(self::COOKIE_LOCATION, '');
            if (!is_string($raw) || trim($raw) === '') {
                $legacy = $_COOKIE[self::COOKIE_LOCATION] ?? '';
                $raw = is_string($legacy) ? $legacy : '';
            }
            if (!is_string($raw) || trim($raw) === '') {
                return '';
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return StorefrontOfferOriginCountryService::normalizeCountryCode(
                    (string)($decoded['country_code'] ?? $decoded['countryCode'] ?? $decoded['country'] ?? '')
                );
            }

            return StorefrontOfferOriginCountryService::normalizeCountryCode($raw);
        } catch (\Throwable) {
            return '';
        }
    }
}
