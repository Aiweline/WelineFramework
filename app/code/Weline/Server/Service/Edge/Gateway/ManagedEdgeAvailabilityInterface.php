<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * auto 模式判定「本项目能否自建托管 Nginx 边缘」的只读接口。
 *
 * 启动决策不直接读配置或文件系统，只向本接口提问；真实实现见
 * Nginx 命名空间的 ManagedNginxEdgeAvailability，测试可替换为假实现。
 */
interface ManagedEdgeAvailabilityInterface
{
    /**
     * 宿主公网边缘（标准 80/443）是否已被外人占用或本进程无权绑定。
     *
     * 为真表示公网边缘不可争抢，auto 不得再起一套托管 Nginx。
     * 仅「系统盘存在 nginx 二进制、进程未听公网端口」不得判为占用——否则 apt/宝塔
     * 装包即会把 auto 的第三出口（托管 Nginx 网关）永久堵死。
     * 当前 PHP 对特权端口 UNBINDABLE（非 root 常态）也不得判为占用——绑定能力在
     * 托管 Nginx 二进制（setcap）侧，不在探测用的 PHP 进程侧。
     */
    public function hostNginxOccupied(): bool;

    /**
     * 本项目是否被许可且已具备自建托管 Nginx 边缘的条件。
     *
     * 必须同时满足：未被 managed / auto_start 显式否决，且二进制已安装。
     * 普通启动绝不下载或编译 Nginx，未安装一律视为不具备。
     */
    public function managedNginxReady(): bool;

    /**
     * 不具备条件时的简短原因，用于写入启动决策的 reason。
     */
    public function unavailableReason(): string;
}
