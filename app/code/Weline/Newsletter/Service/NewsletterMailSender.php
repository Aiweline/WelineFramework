<?php

declare(strict_types=1);

namespace Weline\Newsletter\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Sends newsletter transactional mail via Smtp channel (w_query smtp/send).
 */
final class NewsletterMailSender
{
    public const CHANNEL_WELCOME = 'Weline_Newsletter::subscribe_welcome';
    public const CHANNEL_GIFT = 'Weline_Newsletter::subscribe_gift';

    /**
     * @param array<string, mixed> $vars
     */
    public function sendWelcome(string $email, array $vars = []): bool
    {
        return $this->send(self::CHANNEL_WELCOME, $email, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function sendGift(string $email, array $vars = []): bool
    {
        return $this->send(self::CHANNEL_GIFT, $email, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function send(string $channel, string $email, array $vars = []): bool
    {
        $email = \strtolower(\trim($email));
        if ($email === '' || !\filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $params = [
            'module' => 'Weline_Newsletter',
            'channel' => $channel,
            'to' => $email,
            'vars' => $vars,
        ];
        $scope = $this->resolveSendScope();
        if ($scope['website_code'] !== '') {
            $params['website_code'] = $scope['website_code'];
        }
        if ($scope['locale'] !== '') {
            $params['locale'] = $scope['locale'];
        }

        try {
            $result = w_query('smtp', 'send', $params);
            if (\is_array($result)) {
                return !empty($result['success']);
            }

            return (bool)$result;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{website_code:string,locale:string}
     */
    private function resolveSendScope(): array
    {
        $websiteCode = '';
        $locale = '';
        try {
            $identity = RequestContext::scopeIdentity();
            if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
                $websiteCode = \trim((string)($identity->websiteCode ?? ''));
            }
            $lang = \trim((string)RequestContext::getWelineUserLang());
            if ($lang !== '' && $lang !== 'default') {
                $locale = $lang;
            }
        } catch (\Throwable) {
        }

        return ['website_code' => $websiteCode, 'locale' => $locale];
    }
}
