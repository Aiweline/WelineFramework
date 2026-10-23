<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

final class HostNginxFpcTarget
{
    public function __construct(
        public readonly string $nginxBinary,
        public readonly string $mapDirectory,
        public readonly string $locationFile,
        public readonly string $projectRoot,
        public readonly string $publicHost,
        public readonly string $upstreamHost,
        public readonly int $upstreamPort,
    ) {
    }
}
