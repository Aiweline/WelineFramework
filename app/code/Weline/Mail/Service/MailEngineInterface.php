<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

interface MailEngineInterface
{
    public function getName(): string;

    public function buildInstallPlan(): array;

    public function checkEnvironment(): array;

    public function install(bool $yes = false): array;

    public function service(string $action): array;

    public function clientSettings(string $domain, string $hostname): array;
}
