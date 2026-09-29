<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/** 主机代次与幂等记录随 nginx.conf 原子发布，回滚时两者一起回滚。 */
final class ManagedNginxHostCacheState
{
    private const MARKER = '# wls-edge-cache-state: ';

    private function __construct(private array $state)
    {
    }

    public static function read(string $config): self
    {
        if (!file_exists($config) && !is_link($config)) {
            return self::fromConfig('');
        }
        return self::fromConfig(GatewayProjectStateFilesystem::read(
            $config, 16 * 1024 * 1024, 'Managed Nginx active config host cache state',
        ));
    }

    public static function fromConfig(string $config): self
    {
        if (!str_contains($config, self::MARKER)) {
            return self::fromArray(['schema'=>1, 'generations'=>[], 'operations'=>[]]);
        }
        if (preg_match_all('/^# wls-edge-cache-state: ([^\r\n]*)$/m', $config, $matches) !== 1) {
            throw new \RuntimeException('Invalid managed Nginx host cache state marker.');
        }
        $json = base64_decode($matches[1][0], true);
        $state = $json === false ? null : json_decode($json, true);
        if (!is_array($state)) {
            throw new \RuntimeException('Invalid managed Nginx host cache state.');
        }
        return self::fromArray($state);
    }

    public static function fromArray(array $state): self
    {
        if (($state['schema'] ?? null) !== 1 || !is_array($state['generations'] ?? null)
            || !is_array($state['operations'] ?? null)) {
            throw new \RuntimeException('Invalid managed Nginx host cache state schema.');
        }
        foreach ($state['generations'] as $host=>$generation) {
            if ((!is_string($host) && !is_int($host)) || self::normalizeHosts([(string)$host]) !== [(string)$host]
                || !is_int($generation) || $generation < 1) {
                throw new \RuntimeException('Invalid managed Nginx host cache generation.');
            }
        }
        foreach ($state['operations'] as $id=>$digest) {
            if ((!is_string($id) && !is_int($id)) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', (string)$id) !== 1
                || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new \RuntimeException('Invalid managed Nginx host cache operation.');
            }
        }
        ksort($state['generations'], SORT_STRING);
        ksort($state['operations'], SORT_STRING);
        return new self($state);
    }

    /** @param list<string> $hosts @return list<string> */
    public static function normalizeHosts(array $hosts): array
    {
        $normalized = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                throw new \InvalidArgumentException('Host must be a hostname.');
            }
            $host = strtolower(rtrim(trim($host), '.'));
            if ($host === '' || strlen($host) > 253
                || (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                    && filter_var($host, FILTER_VALIDATE_IP) === false)) {
                throw new \InvalidArgumentException('Invalid edge cache hostname.');
            }
            $normalized[] = $host;
        }
        $hosts = array_values(array_unique($normalized));
        sort($hosts, SORT_STRING);
        if ($hosts === []) {
            throw new \InvalidArgumentException('At least one edge cache hostname is required.');
        }
        return $hosts;
    }

    /** @return array{state:self,already_applied:bool,hosts:list<string>} */
    public function advance(array $hosts, string $operationId): array
    {
        $hosts = self::normalizeHosts($hosts);
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $operationId) !== 1) {
            throw new \InvalidArgumentException('Invalid edge cache operation identifier.');
        }
        $digest = hash('sha256', json_encode($hosts, JSON_THROW_ON_ERROR));
        if (isset($this->state['operations'][$operationId])) {
            if (!hash_equals($this->state['operations'][$operationId], $digest)) {
                throw new \RuntimeException('operation_conflict');
            }
            return ['state'=>$this, 'already_applied'=>true, 'hosts'=>$hosts];
        }
        $next = $this->state;
        foreach ($hosts as $host) {
            $current = $next['generations'][$host] ?? 0;
            if ($current === PHP_INT_MAX) {
                throw new \RuntimeException('Edge cache host generation exhausted.');
            }
            $next['generations'][$host] = $current + 1;
        }
        $next['operations'][$operationId] = $digest;
        return ['state'=>self::fromArray($next), 'already_applied'=>false, 'hosts'=>$hosts];
    }

    public function toArray(): array { return $this->state; }
    public function generations(): array { return $this->state['generations']; }

    public function configComment(): string
    {
        return self::MARKER . base64_encode(json_encode($this->state, JSON_THROW_ON_ERROR)) . "\n";
    }

    public function nginxMap(): string
    {
        $map = "\n    map \$host \$wls_edge_host_generation {\n        default \"\";\n";
        foreach ($this->generations() as $host=>$generation) {
            $map .= '        "' . $host . '" ' . '"|host=' . $generation . '"' . ";\n";
        }
        return $map . "    }\n";
    }
}
