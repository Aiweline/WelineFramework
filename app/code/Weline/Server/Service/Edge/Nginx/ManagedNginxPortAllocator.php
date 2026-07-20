<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\MasterProcess;

/**
 * Per-project listen ports so multiple BP checkouts do not collide.
 */
final class ManagedNginxPortAllocator
{
    public function __construct(private readonly ManagedNginxPaths $paths = new ManagedNginxPaths())
    {
    }

    /**
     * @return array{http:int,https:int,offset:int,source:string}
     */
    public function allocate(): array
    {
        $cfg = $this->paths->config();
        $offset = MasterProcess::getProjectPortOffset();
        $http = $this->normalizePort($cfg['listen_http'] ?? null);
        $https = $this->normalizePort($cfg['listen_https'] ?? null);
        $source = 'project_offset';
        if ($http === null) {
            $http = 8080 + $offset;
        } else {
            $source = 'env';
        }
        if ($https === null) {
            $https = 8443 + $offset;
            if ($source !== 'env') {
                $source = 'project_offset';
            }
        } else {
            $source = 'env';
        }
        if ($http < 1 || $http > 65535 || $https < 1 || $https > 65535) {
            throw new \RuntimeException('Managed nginx listen ports must be in 1..65535.');
        }
        if ($http === $https) {
            throw new \RuntimeException('Managed nginx listen_http and listen_https must differ.');
        }

        return [
            'http' => $http,
            'https' => $https,
            'offset' => $offset,
            'source' => $source,
        ];
    }

    private function normalizePort(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_int($value) || \is_float($value) || (\is_string($value) && \ctype_digit(\trim($value)))) {
            $port = (int)$value;
            return $port > 0 ? $port : null;
        }
        return null;
    }
}
