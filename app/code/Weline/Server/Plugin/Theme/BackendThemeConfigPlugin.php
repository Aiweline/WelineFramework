<?php

declare(strict_types=1);

namespace Weline\Server\Plugin\Theme;

use Weline\Backend\Block\ThemeConfig;
use Weline\Framework\Session\SessionFactory;
use Weline\Server\Service\ThemeModeSharedService;

class BackendThemeConfigPlugin
{
    public function __construct(
        private readonly ThemeModeSharedService $themeModeSharedService
    ) {
    }

    public function afterGetThemeConfig(ThemeConfig $subject, mixed $result, string $key = ''): mixed
    {
        if ($key !== 'theme-mode-switch' || !$this->themeModeSharedService->enabled()) {
            return $result;
        }
        $session = $this->resolveSession($subject);
        $userId = $session?->getUserId();
        $sessionId = $session?->getId();
        $mode = $this->themeModeSharedService->getMode('backend', \is_numeric($userId) ? (int)$userId : null, $sessionId);
        return $mode ?? $result;
    }

    public function afterSetThemeConfig(ThemeConfig $subject, mixed $result, string|array $key, mixed $value = ''): mixed
    {
        if (!$this->themeModeSharedService->enabled()) {
            return $result;
        }
        $mode = $this->extractMode($key, $value);
        if ($mode === null) {
            return $result;
        }
        $session = $this->resolveSession($subject);
        $userId = $session?->getUserId();
        $sessionId = $session?->getId();
        $this->themeModeSharedService->setMode(
            'backend',
            \is_numeric($userId) ? (int)$userId : null,
            $mode,
            $sessionId
        );
        return $result;
    }

    private function extractMode(string|array $key, mixed $value): ?string
    {
        if (\is_array($key)) {
            $mode = $key['theme-mode-switch'] ?? null;
            return \is_string($mode) ? $mode : null;
        }
        if ($key !== 'theme-mode-switch') {
            return null;
        }
        return \is_string($value) ? $value : null;
    }

    private function resolveSession(ThemeConfig $subject): mixed
    {
        return SessionFactory::getInstance()->createBackendSession();
    }
}
