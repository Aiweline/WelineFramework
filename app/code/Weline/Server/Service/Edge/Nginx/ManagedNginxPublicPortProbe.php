<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\System\Process\Processer;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;

/**
 * 回答「项目托管 Nginx 现在能不能占用标准公网端口 80/443」。
 *
 * 托管 Nginx 就是本项目的公网网关，正常情况下它就该听 80/443；只有这两个端口
 * 确实被**别的**进程占着（或当前进程无权绑定）时才回退到 8080/8443 +
 * projectPortOffset。本类只回答「这个端口现在归谁」，不做回退决策、不写任何状态。
 *
 * 探测必须绑**通配地址** 0.0.0.0，不能沿用 {@see Processer::isPortFreeByBindProbe()}
 * 的 127.0.0.1：BSD/macOS 允许「具体地址」与「通配监听」共存，别人已经
 * `listen *:80` 时绑 127.0.0.1:80 依旧成功，会把已占用的端口误判成空闲。
 * 本机实测：持有 `*:18099` 时绑 0.0.0.0:18099 得 EADDRINUSE(48)，绑
 * 127.0.0.1:18099 与 `[::]:18099` 却都成功 —— 所以只信通配绑定结果，
 * 也不要额外探 IPv6（macOS 上它对已占用的 v4 端口同样返回成功）。
 */
final class ManagedNginxPublicPortProbe implements ManagedNginxPublicPortProbeInterface
{
    /**
     * PHP 不导出 errno 常量，只能按平台列出。EADDRINUSE: macOS(BSD)=48、
     * Linux=98、Windows=10048；EACCES=13、EPERM=1、WSAEACCES=10013。
     */
    private const ERRNO_ADDRESS_IN_USE = [48, 98, 10048];
    private const ERRNO_ACCESS_DENIED = [1, 13, 10013];

    public function __construct(
        private readonly ManagedNginxPaths $paths = new ManagedNginxPaths(),
    ) {
    }

    /**
     * @return array{state:string,pid:int,pname:string,detail:string}
     */
    public function inspect(int $port): array
    {
        if ($port < 1 || $port > 65535) {
            return $this->verdict(self::STATE_FREE, 0, '', '');
        }

        $probe = $this->probeWildcardBind($port);
        if ($probe['ok']) {
            return $this->verdict(self::STATE_FREE, 0, '', '');
        }

        if ($this->errnoIn($probe['errno'], self::ERRNO_ACCESS_DENIED)) {
            return $this->verdict(
                self::STATE_UNBINDABLE,
                0,
                '',
                'cannot bind 0.0.0.0:' . $port . ': ' . $probe['error'],
            );
        }

        if (!$this->errnoIn($probe['errno'], self::ERRNO_ADDRESS_IN_USE)) {
            // 探测本身失败（EMFILE、EAFNOSUPPORT 等），并不代表端口被占。
            // 这里宁可判「空闲」让 nginx -t / 启动去大声失败，也不静默换端口 ——
            // 静默换端口会把「网关没拿到公网端口」这件事藏起来。
            return $this->verdict(self::STATE_FREE, 0, '', '');
        }

        return $this->classifyOccupant($port);
    }

    /**
     * 端口已被监听：判断占用者是不是本项目自己的托管 Nginx。
     *
     * 「是不是自己」不能只看 kernel 监听者 PID：同一个端口可以被**多个**进程
     * 同时 listen（macOS/BSD 允许具体地址与通配监听共存），而
     * `Processer::getProcessIdByPort()` 只返回 lsof 的第一条，可能是同端口另一个
     * 地址上的无关进程。实测本机：托管 Nginx master 持 `*:443`，而
     * `getProcessIdByPort(443)` 返回了另一个只监听 127.0.0.1:443 的桌面应用 PID，
     * 于是「自己的网关」被判成外人占用、被挪到高位端口。
     *
     * 因此判据换成「我们自己的 master 活着 **且** 端口被我们自己的配置声明」：
     * owner 记录（最近一次成功发布）与当前生效 conf 的 `listen` 指令都能证明
     * 「这个端口是 nginx 按我们的配置绑的」。kernel PID 比对只作最后兜底。
     *
     * @return array{state:string,pid:int,pname:string,detail:string}
     */
    private function classifyOccupant(int $port): array
    {
        $masterPid = $this->managedNginxMasterPid();
        if ($masterPid > 0 && Processer::isRunningByPid($masterPid)) {
            if (\in_array($port, $this->publishedListenPorts(), true)) {
                return $this->verdict(
                    self::STATE_SELF,
                    $masterPid,
                    'nginx',
                    'held by this project-managed Nginx (owner record, master pid '
                        . $masterPid . ')',
                );
            }
            if (\in_array($port, $this->activeConfigListenPorts(), true)) {
                return $this->verdict(
                    self::STATE_SELF,
                    $masterPid,
                    'nginx',
                    'held by this project-managed Nginx (active config, master pid '
                        . $masterPid . ')',
                );
            }
            $occupant = Processer::inspectPortOccupant($port);
            if ((int)($occupant['kernel_listener_pid'] ?? 0) === $masterPid
                || (int)($occupant['pid'] ?? 0) === $masterPid
            ) {
                return $this->verdict(
                    self::STATE_SELF,
                    $masterPid,
                    'nginx',
                    'held by this project-managed Nginx (master pid ' . $masterPid . ')',
                );
            }
        }

        $occupant = Processer::inspectPortOccupant($port);
        $pid = (int)($occupant['kernel_listener_pid'] ?? 0);
        if ($pid <= 0) {
            $pid = (int)($occupant['pid'] ?? 0);
        }
        $pname = \trim((string)($occupant['pname'] ?? ''));
        if ($pname === '') {
            $pname = \trim((string)($occupant['process_name'] ?? ''));
        }

        return $this->verdict(
            self::STATE_FOREIGN,
            $pid,
            $pname,
            '0.0.0.0:' . $port . ' is not bindable by this project-managed Nginx'
                // 只说「该端口上有一个监听者」，不断言它就是唯一的占用者。
                . ($pid > 0 ? ' (a listener on it is pid ' . $pid
                    . ($pname !== '' ? ' ' . $pname : '') . ')' : ''),
        );
    }

    /**
     * 当前生效的托管 nginx.conf 里声明的监听端口。
     *
     * nginx 启动时绑的就是这份 conf 的 `listen` 指令，所以「我们的 master 活着
     * 且 conf 声明了这个端口」等价于「这个端口是本项目网关绑的」。
     *
     * @return list<int>
     */
    private function activeConfigListenPorts(): array
    {
        try {
            $conf = GatewayProjectStateFilesystem::readOptional(
                $this->paths->confFile(),
                4 * 1024 * 1024,
                'Managed Nginx active config',
            );
        } catch (\Throwable) {
            return [];
        }
        if ($conf === null) {
            return [];
        }
        if (\preg_match_all('/^[ \t]*listen[ \t]+(\d{1,5})\b/m', $conf, $matches) === false) {
            return [];
        }

        $ports = [];
        foreach ($matches[1] as $raw) {
            $port = (int)$raw;
            if ($port >= 1 && $port <= 65535) {
                $ports[] = $port;
            }
        }

        return \array_values(\array_unique($ports));
    }

    /**
     * 在通配地址上真绑一次，用 errno 区分「被占」「无权限」「其它」。
     *
     * 绑定成功后立刻关闭：只做过 bind、没有 accept 的 socket 不会留下 TIME_WAIT，
     * 因此这个探测对后续 nginx 启动没有副作用。SO_REUSEADDR 只是为了避免把
     * 「刚断开连接的 TIME_WAIT」误判成占用；两个**活跃**监听者之间它并不放行。
     *
     * @return array{ok:bool,errno:int,error:string}
     */
    private function probeWildcardBind(int $port): array
    {
        if (\extension_loaded('sockets')
            && \function_exists('socket_create')
            && \function_exists('socket_bind')
        ) {
            $socket = @\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
            if ($socket !== false) {
                @\socket_set_option($socket, \SOL_SOCKET, \SO_REUSEADDR, 1);
                $bound = @\socket_bind($socket, '0.0.0.0', $port);
                $errno = $bound ? 0 : \socket_last_error($socket);
                @\socket_close($socket);
                if ($bound) {
                    return ['ok' => true, 'errno' => 0, 'error' => ''];
                }
                if ($errno > 0) {
                    return [
                        'ok' => false,
                        'errno' => $errno,
                        'error' => \socket_strerror($errno),
                    ];
                }
            }
        }

        $errno = 0;
        $errstr = '';
        $stream = @\stream_socket_server(
            'tcp://0.0.0.0:' . $port,
            $errno,
            $errstr,
            \STREAM_SERVER_BIND,
        );
        if (\is_resource($stream)) {
            @\fclose($stream);
            return ['ok' => true, 'errno' => 0, 'error' => ''];
        }

        $errno = (int)$errno;
        $errstr = \trim((string)$errstr);
        if ($errno === 0) {
            // 有些平台的 stream 包装层不回填 errno，只能退回文本判定。
            $lowered = \strtolower($errstr);
            if (\str_contains($lowered, 'address already in use')
                || \str_contains($lowered, 'eaddrinuse')
            ) {
                $errno = self::ERRNO_ADDRESS_IN_USE[0];
            } elseif (\str_contains($lowered, 'permission denied')
                || \str_contains($lowered, 'operation not permitted')
                || \str_contains($lowered, 'eacces')
                || \str_contains($lowered, 'eperm')
            ) {
                $errno = self::ERRNO_ACCESS_DENIED[1];
            }
        }

        return ['ok' => false, 'errno' => $errno, 'error' => $errstr];
    }

    /**
     * 托管 Nginx 的 master PID（nginx 自己按 conf 里的 `pid run/nginx.pid` 写出）。
     */
    private function managedNginxMasterPid(): int
    {
        try {
            $raw = GatewayProjectStateFilesystem::readOptional(
                $this->paths->pidFile(),
                64,
                'Managed Nginx pid file',
            );
        } catch (\Throwable) {
            return 0;
        }
        $raw = \trim((string)$raw);
        if (\preg_match('/\A\d{1,7}\z/D', $raw) !== 1) {
            return 0;
        }
        $pid = (int)$raw;

        return $pid > 1 ? $pid : 0;
    }

    /**
     * owner 记录里本项目托管 Nginx 已发布的公网监听端口。
     *
     * 只取端口，不做完整 owner 校验：判据是「owner 记录声称这个端口归我」
     * **且** master PID 存活（见 {@see classifyOccupant()}），两者缺一不可，
     * 所以一份过期/损坏的 owner 文件不会让探测认错端口。
     *
     * @return list<int>
     */
    private function publishedListenPorts(): array
    {
        try {
            $raw = GatewayProjectStateFilesystem::readOptional(
                $this->paths->ownerFile(),
                4 * 1024 * 1024,
                'Managed Nginx owner state',
            );
        } catch (\Throwable) {
            return [];
        }
        if ($raw === null) {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)
            || \trim((string)($decoded['instance_name'] ?? '')) === ''
        ) {
            return [];
        }

        $ports = [];
        foreach (['listen_http', 'listen_https'] as $field) {
            $value = $decoded[$field] ?? null;
            if (\is_int($value) && $value >= 1 && $value <= 65535) {
                $ports[] = $value;
            }
        }

        return $ports;
    }

    /**
     * @param list<int> $candidates
     */
    private function errnoIn(int $errno, array $candidates): bool
    {
        return $errno > 0 && \in_array($errno, $candidates, true);
    }

    /**
     * @return array{state:string,pid:int,pname:string,detail:string}
     */
    private function verdict(string $state, int $pid, string $pname, string $detail): array
    {
        return [
            'state' => $state,
            'pid' => $pid,
            'pname' => $pname,
            'detail' => $detail,
        ];
    }
}
