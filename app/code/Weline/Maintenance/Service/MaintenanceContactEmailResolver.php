<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Resolves the support email shown on maintenance static pages.
 */
final class MaintenanceContactEmailResolver
{
    public const MODULE = 'Weline_Maintenance';

    public const CONFIG_KEY = 'maintenance/developer_email';

    public const DEFAULT_EMAIL = 'support@example.com';

    public function resolve(): string
    {
        foreach ($this->candidates() as $email) {
            if ($this->isValidEmail($email)) {
                return $email;
            }
        }

        return self::DEFAULT_EMAIL;
    }

    /**
     * @return list<string>
     */
    private function candidates(): array
    {
        $candidates = [];

        try {
            $reader = ObjectManager::getInstance(ConfigReader::class);
            $candidates[] = \trim((string)$reader->getConfig(
                self::CONFIG_KEY,
                self::MODULE,
                ConfigReader::area_BACKEND,
                '',
            ));
        } catch (\Throwable) {
        }

        try {
            $backendConfig = ObjectManager::getInstance(BackendConfigStore::class);
            $candidates[] = \trim((string)($backendConfig->getConfig('contact_email', 'Weline_Backend') ?? ''));
            $candidates[] = \trim((string)($backendConfig->getConfig('support_email', 'Weline_Backend') ?? ''));
        } catch (\Throwable) {
        }

        try {
            $env = Env::getInstance();
            foreach (['contact_email', 'site.contact_email', 'ssl.contact_email'] as $key) {
                $value = $env->getConfig($key);
                if (\is_scalar($value) || $value === null) {
                    $candidates[] = \trim((string)($value ?? ''));
                }
            }
        } catch (\Throwable) {
        }

        return $candidates;
    }

    private function isValidEmail(string $email): bool
    {
        return $email !== '' && \filter_var($email, \FILTER_VALIDATE_EMAIL) !== false;
    }
}
