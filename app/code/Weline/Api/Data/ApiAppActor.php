<?php
declare(strict_types=1);

namespace Weline\Api\Data;

use Weline\Api\Model\ApiApp;
use Weline\Api\Model\ApiAppInstallation;

class ApiAppActor
{
    public function __construct(
        private readonly ApiApp $app,
        private readonly ApiAppInstallation $installation
    ) {
    }

    public function getId(mixed $default = 0): int
    {
        return $this->app->getId($default);
    }

    public function getApp(): ApiApp
    {
        return $this->app;
    }

    public function getInstallation(): ApiAppInstallation
    {
        return $this->installation;
    }

    public function getInstallationId(): int
    {
        return $this->installation->getId();
    }

    public function getAppId(): int
    {
        return $this->app->getId();
    }

    public function getIsEnabled(): bool
    {
        return $this->app->getIsEnabled() && $this->installation->isActive();
    }

    public function getRoleModel(): null
    {
        return null;
    }

    public function isSandboxAccount(): bool
    {
        return false;
    }
}
