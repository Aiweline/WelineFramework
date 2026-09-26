<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * 托管 Nginx 公网端口可用性探测的契约。
 *
 * 单独抽出接口是为了让「默认 80/443、被占用才回退」这段决策可被确定性地单测：
 * 80/443 在测试机上是否空闲、当前用户能否绑定，都不是测试能控制的外部状态。
 */
interface ManagedNginxPublicPortProbeInterface
{
    /** 端口空闲：没有监听者，且本进程能绑上通配地址。 */
    public const STATE_FREE = 'free';
    /** 被本项目自己的托管 Nginx 占用（重启/重载路径必须继续沿用该端口）。 */
    public const STATE_SELF = 'self';
    /** 被非本项目托管 Nginx 的进程占用。 */
    public const STATE_FOREIGN = 'foreign';
    /** 端口没人监听，但本进程无权限绑定（Linux 非 root 且未 setcap）。 */
    public const STATE_UNBINDABLE = 'unbindable';

    /**
     * @return array{state:string,pid:int,pname:string,detail:string}
     *   state ∈ self::STATE_*；pid/pname 仅作诊断；detail 为可直接展示的英文说明。
     */
    public function inspect(int $port): array;
}
