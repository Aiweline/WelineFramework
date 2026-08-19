<?php

declare(strict_types=1);

use Weline\Server\Service\HostsFileManager;
use Weline\Server\Service\LocalDomainPolicy;

if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY === 'Windows') {
    \fwrite(STDERR, "The WLS privileged hosts editor is POSIX CLI-only.\n");
    exit(64);
}
if (!\function_exists('posix_geteuid') || (int)@\posix_geteuid() !== 0) {
    \fwrite(STDERR, "The WLS privileged hosts editor requires an authenticated root process.\n");
    exit(77);
}
if ($argc !== 2 || !\str_starts_with((string)$argv[1], '--domain=')) {
    \fwrite(STDERR, "Invalid WLS privileged hosts editor request.\n");
    exit(64);
}

$domain = \strtolower(\trim(\substr((string)$argv[1], \strlen('--domain='))));
if ($domain === ''
    || \str_contains($domain, "\0")
    || !\preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.weline\.test$/D', $domain)
) {
    \fwrite(STDERR, "The requested WLS hosts domain is outside the managed local policy.\n");
    exit(64);
}

$projectRoot = \dirname(__DIR__, 5);
$autoload = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!\is_file($autoload) || \is_link($autoload)) {
    \fwrite(STDERR, "The WLS autoloader is unavailable or unsafe.\n");
    exit(70);
}
require $autoload;

if (!LocalDomainPolicy::requiresHostsEntry($domain)
    || !LocalDomainPolicy::isManagedSingleLabelSubdomain($domain)
) {
    \fwrite(STDERR, "The requested WLS hosts domain is not eligible.\n");
    exit(64);
}

$result = HostsFileManager::addDomain($domain, HostsFileManager::LOOPBACK_IPV4);
if (!($result['success'] ?? false)) {
    \fwrite(STDERR, (string)($result['message'] ?? 'Privileged hosts publication failed.') . "\n");
    exit(1);
}
