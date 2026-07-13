<?php
declare(strict_types=1);

/**
 * Weline Server - 解封/清理封禁列表
 *
 * 通过 IPC 通知当前实例的 Worker 与 Dispatcher 解封指定 IP 或清空全部封禁。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server\Security;

use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * server:security:unblock - 解封 IP 或清空全部封禁
 */
class Unblock extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        $ip = $args['ip'] ?? $args['i'] ?? null;
        $clearAll = isset($args['clear-all']) || isset($args['clear_all']) || isset($args['all']);

        if ($ip === null || $ip === '') {
            if (!$clearAll) {
                $this->printer->warning(__('请指定 --ip=<IP> 解封单个 IP，或 --clear-all 清空全部封禁'));
                $this->printer->note(__('示例：php bin/w server:security:unblock --ip=101.204.98.197'));
                $this->printer->note(__('示例：php bin/w server:security:unblock --clear-all'));
                return;
            }
        }

        $result = (new IpcControlGateway())->securityUnblock(
            $instanceName,
            \is_string($ip) ? $ip : null,
            $clearAll
        );
        if (!empty($result['success'])) {
            $this->printer->success((string)($result['message'] ?? __('解封命令已接收')));
            $this->printer->note(__('状态：%{1}', [(string)($result['status'] ?? 'accepted')]));
            return;
        }

        $this->printer->warning((string)($result['message'] ?? __('解封命令失败')));
    }

    protected function parseInstanceName(array $args): string
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string) $arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        \array_shift($positionalArgs);
        return $positionalArgs[0] ?? 'default';
    }
}
