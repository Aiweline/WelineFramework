<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

final class InquirySubmissionCaptchaGuard
{
    public const INTENT = 'inquiry.submit';

    private ?CaptchaManagerInterface $captchaManager = null;

    public function isEnabled(): bool
    {
        return RegistryModulePresence::isActivePresent('Weline_Captcha');
    }

    /** @param array<string, mixed> $submission */
    public function verify(array $submission, ?Request $request = null): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        try {
            $request ??= ObjectManager::getInstance(Request::class);

            return $this->manager()->verifySubmission(
                $submission,
                self::INTENT,
                $this->requestHostname($request),
                $request->clientIP(),
            );
        } catch (\Throwable $throwable) {
            \w_log_error(
                'Inquiry submission captcha verification failed: ' . $throwable->getMessage(),
                ['intent' => self::INTENT],
                'captcha',
            );

            return false;
        }
    }

    private function manager(): CaptchaManagerInterface
    {
        return $this->captchaManager ??= ObjectManager::getInstance(CaptchaManagerInterface::class);
    }

    private function requestHostname(Request $request): string
    {
        $host = trim((string)(
            $request->getServer('HTTP_HOST')
            ?: $request->getServer('SERVER_NAME')
            ?: ''
        ));
        $hostname = $host === '' ? '' : parse_url('http://' . ltrim($host, '/'), PHP_URL_HOST);

        return is_string($hostname) ? strtolower(rtrim($hostname, '.')) : '';
    }
}
