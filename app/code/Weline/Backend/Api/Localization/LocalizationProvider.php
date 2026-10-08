<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Localization;

use Weline\Backend\Service\BackendPersonalLanguage;
use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;

/**
 * Backend-area personal language via LocalizationProviderRegistry (priority ≥ 200).
 * Framework State must not soft-pull BackendPersonalLanguage FQCN.
 */
final class LocalizationProvider implements LocalizationProviderInterface
{
    public const MIN_BACKEND_PRIORITY = 200;

    public function priority(): int
    {
        return self::MIN_BACKEND_PRIORITY;
    }

    public function languageCodes(): array
    {
        $code = $this->personalLanguage();
        return $code !== '' ? [$code] : [];
    }

    public function currencyCodes(): array
    {
        return [];
    }

    public function defaultLanguage(): ?string
    {
        if (!$this->isBackendArea()) {
            return null;
        }
        $code = $this->personalLanguage();

        return $code !== '' ? $code : null;
    }

    public function defaultCurrency(): ?string
    {
        return null;
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    public function installedLanguageCodes(): ?array
    {
        return null;
    }

    private function personalLanguage(): string
    {
        try {
            $personal = BackendPersonalLanguage::runtimeOverride();
            if ($personal === '') {
                $personal = BackendPersonalLanguage::resolveForCurrentUser();
            }

            return $personal;
        } catch (\Throwable) {
            return '';
        }
    }

    private function isBackendArea(): bool
    {
        try {
            $area = (string)WelineEnv::get('area', '');
            if ($area === 'backend' || $area === 'rest_backend') {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            return State::isBackend();
        } catch (\Throwable) {
            return false;
        }
    }
}
