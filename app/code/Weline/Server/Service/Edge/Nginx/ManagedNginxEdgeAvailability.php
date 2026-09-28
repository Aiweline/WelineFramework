<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\Edge\Gateway\ManagedEdgeAvailabilityInterface;

/**
 * ManagedEdgeAvailabilityInterface 的真实实现。
 *
 * 把「宿主公网边缘是否已被占用」与「本项目托管 Nginx 状态」收敛成两个布尔判据，
 * 供 auto 启动决策使用；本身不做任何进程或文件写入。
 *
 * 占用判定必须看 80/443 是否被**外人**占着（或无权绑定），不能只看系统盘上有没有
 * nginx 二进制：仅安装 apt/宝塔包、进程未听公网端口时，auto 仍应走托管 Nginx 网关。
 */
final class ManagedNginxEdgeAvailability implements ManagedEdgeAvailabilityInterface
{
    private readonly ManagedNginxPaths $paths;

    /** @var (\Closure(): bool)|null 宿主占用接缝，仅测试与容器化部署使用 */
    private readonly ?\Closure $hostNginxProbe;

    private readonly ManagedNginxPublicPortProbeInterface $portProbe;

    public function __construct(
        ?ManagedNginxPaths $paths = null,
        ?\Closure $hostNginxProbe = null,
        ?ManagedNginxPublicPortProbeInterface $portProbe = null,
    ) {
        $this->paths = $paths ?? new ManagedNginxPaths();
        $this->hostNginxProbe = $hostNginxProbe;
        $this->portProbe = $portProbe ?? new ManagedNginxPublicPortProbe($this->paths);
    }

    public function hostNginxOccupied(): bool
    {
        if ($this->hostNginxProbe !== null) {
            return (bool)($this->hostNginxProbe)();
        }

        // 只认「外人已听在公网端口」。UNBINDABLE 只表示当前 PHP 进程无权试绑
        // 特权端口（普通 weline 用户常态），托管 Nginx 二进制可另有 setcap，
        // 不得因此把 auto 的第三出口（托管网关）整段堵死。
        foreach ([
            ManagedNginxPortAllocator::DEFAULT_PUBLIC_HTTP_PORT,
            ManagedNginxPortAllocator::DEFAULT_PUBLIC_HTTPS_PORT,
        ] as $port) {
            $verdict = $this->portProbe->inspect($port);
            if ((string)($verdict['state'] ?? '')
                === ManagedNginxPublicPortProbeInterface::STATE_FOREIGN
            ) {
                return true;
            }
        }

        return false;
    }

    public function managedNginxReady(): bool
    {
        return $this->paths->managedEnabled()
            && $this->paths->autoStartEnabled()
            && $this->paths->isInstalled();
    }

    public function unavailableReason(): string
    {
        if ($this->hostNginxOccupied()) {
            return 'host public edge (80/443) is occupied by a foreign listener; WLS will not contend for the public edge';
        }
        if (!$this->paths->managedEnabled()) {
            return 'wls.edge.nginx.managed=false disables the project-managed Nginx edge';
        }
        if (!$this->paths->autoStartEnabled()) {
            return 'wls.edge.nginx.auto_start=false disables managed Nginx auto-start';
        }
        if (!$this->paths->isInstalled()) {
            return 'project-managed Nginx is not installed; run server:nginx:install explicitly';
        }
        // 三个条件全为真时托管 Nginx 边缘其实是可用的。此分支只会在调用方
        // 误把「可用」当「不可用」来取原因时命中，此时必须如实回答，不能沿用
        // 上面的「未安装」文案 —— 那会让运维照着错误结论去重装。
        return 'project-managed Nginx edge is available';
    }
}
