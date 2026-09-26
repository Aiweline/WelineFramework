<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\MasterProcess;

/**
 * Per-project listen ports so multiple BP checkouts do not collide.
 *
 * 托管 Nginx 是本项目的公网网关，所以**默认就是公网端口 80/443**；只有当 80/443
 * 确实被别的进程占着（或本进程无权绑定）时才回退到 8080/8443 + projectPortOffset。
 * 回退一律通过 `source` / `http_source` / `https_source` / `notes` 写明，不静默。
 *
 * 显式配置（`wls.edge.nginx.listen_http|listen_https`，或环境变量
 * `WLS_NGINX_LISTEN_HTTP|WLS_NGINX_LISTEN_HTTPS`）永远优先，且不做占用探测：
 * 运维既然点名了端口，端口可用性就由运维负责。
 */
final class ManagedNginxPortAllocator
{
    /** 端口来自显式配置（模块 env / 项目 env / WLS_NGINX_LISTEN_*）。 */
    public const SOURCE_ENV = 'env';
    /** 端口是标准公网端口 80/443，且当前可用 —— 网关的正常状态。 */
    public const SOURCE_PUBLIC_DEFAULT = 'public_default';
    /** 公网端口不可用，已回退到 8080/8443 + projectPortOffset。 */
    public const SOURCE_PROJECT_OFFSET_FALLBACK = 'project_offset_fallback';

    public const DEFAULT_PUBLIC_HTTP_PORT = 80;
    public const DEFAULT_PUBLIC_HTTPS_PORT = 443;

    private readonly ManagedNginxPaths $paths;
    private readonly ManagedNginxPublicPortProbeInterface $probe;

    public function __construct(
        ?ManagedNginxPaths $paths = null,
        ?ManagedNginxPublicPortProbeInterface $probe = null,
    ) {
        $this->paths = $paths ?? new ManagedNginxPaths();
        $this->probe = $probe ?? new ManagedNginxPublicPortProbe($this->paths);
    }

    /**
     * `source` 是机器值，这里给出唯一一份可读文案，供 status/install 等命令复用；
     * 未知值原样回显，不吞信息。
     */
    public static function describeSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_ENV => __('显式配置'),
            self::SOURCE_PUBLIC_DEFAULT => __('公网默认 80/443'),
            self::SOURCE_PROJECT_OFFSET_FALLBACK => __('已回退项目偏移'),
            // 只有 doctorSnapshot() 在已有 owner 记录时才会用它。
            'owner' => __('当前运行实例'),
            default => $source,
        };
    }

    /**
     * @return array{
     *   http:int,
     *   https:int,
     *   offset:int,
     *   source:string,
     *   http_source:string,
     *   https_source:string,
     *   notes:list<string>
     * }
     */
    public function allocate(): array
    {
        $cfg = $this->paths->config();
        $offset = MasterProcess::getProjectPortOffset();

        $http = $this->normalizePort($cfg['listen_http'] ?? null);
        $https = $this->normalizePort($cfg['listen_https'] ?? null);
        $httpSource = $http !== null ? self::SOURCE_ENV : '';
        $httpsSource = $https !== null ? self::SOURCE_ENV : '';
        /** @var list<string> $notes */
        $notes = [];

        if ($http === null) {
            [$http, $httpSource, $note] = $this->resolvePublicPort(
                self::DEFAULT_PUBLIC_HTTP_PORT,
                8080 + $offset,
                'HTTP',
            );
            if ($note !== '') {
                $notes[] = $note;
            }
        }
        if ($https === null) {
            [$https, $httpsSource, $note] = $this->resolvePublicPort(
                self::DEFAULT_PUBLIC_HTTPS_PORT,
                8443 + $offset,
                'HTTPS',
            );
            if ($note !== '') {
                $notes[] = $note;
            }
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
            'source' => $this->overallSource($httpSource, $httpsSource),
            'http_source' => $httpSource,
            'https_source' => $httpsSource,
            'notes' => $notes,
        ];
    }

    /**
     * 公网端口可用就用公网端口；被外人占着或本进程无权绑定才回退。
     *
     * @return array{0:int,1:string,2:string}
     */
    private function resolvePublicPort(int $publicPort, int $fallbackPort, string $label): array
    {
        $verdict = $this->probe->inspect($publicPort);
        if (\in_array($verdict['state'], [
            ManagedNginxPublicPortProbeInterface::STATE_FREE,
            ManagedNginxPublicPortProbeInterface::STATE_SELF,
        ], true)) {
            // STATE_SELF：本项目的托管 Nginx 正在这个公网端口上跑（重载/证书续期
            // 路径）。这不是「被占用」，必须继续沿用，否则会把一个健康的公网入口
            // 悄悄挪到高位端口上。
            return [$publicPort, self::SOURCE_PUBLIC_DEFAULT, ''];
        }

        $reason = $verdict['state'] === ManagedNginxPublicPortProbeInterface::STATE_UNBINDABLE
            ? 'not bindable by this user'
            : 'occupied';
        $note = $label . ' ' . $publicPort . ' is ' . $reason
            . ($verdict['detail'] !== '' ? ' (' . $verdict['detail'] . ')' : '')
            . '; falling back to ' . $fallbackPort . ' (project offset)';

        return [$fallbackPort, self::SOURCE_PROJECT_OFFSET_FALLBACK, $note];
    }

    /**
     * 整体口径按「最值得注意的」取：有回退就说回退，其次是公网默认，最后才是显式配置。
     */
    private function overallSource(string $httpSource, string $httpsSource): string
    {
        $sources = [$httpSource, $httpsSource];
        if (\in_array(self::SOURCE_PROJECT_OFFSET_FALLBACK, $sources, true)) {
            return self::SOURCE_PROJECT_OFFSET_FALLBACK;
        }
        if (\in_array(self::SOURCE_PUBLIC_DEFAULT, $sources, true)) {
            return self::SOURCE_PUBLIC_DEFAULT;
        }

        return self::SOURCE_ENV;
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
