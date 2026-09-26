<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Server\Service\Edge\Gateway\ManagedEdgeAvailabilityInterface;

/**
 * ManagedEdgeAvailabilityInterface 的真实实现。
 *
 * 把「宿主探测」与「本项目托管 Nginx 状态」收敛成两个布尔判据，
 * 供 auto 启动决策使用；本身不做任何进程或文件写入。
 */
final class ManagedNginxEdgeAvailability implements ManagedEdgeAvailabilityInterface
{
    private readonly ManagedNginxPaths $paths;

    /** @var (\Closure(): bool)|null 宿主探测接缝，仅测试与容器化部署使用 */
    private readonly ?\Closure $hostNginxProbe;

    public function __construct(
        ?ManagedNginxPaths $paths = null,
        ?\Closure $hostNginxProbe = null,
    ) {
        $this->paths = $paths ?? new ManagedNginxPaths();
        $this->hostNginxProbe = $hostNginxProbe;
    }

    public function hostNginxOccupied(): bool
    {
        if ($this->hostNginxProbe !== null) {
            return (bool)($this->hostNginxProbe)();
        }
        return $this->paths->hostNginxPresent();
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
            return 'host Nginx is present; WLS will not contend for the public edge';
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
