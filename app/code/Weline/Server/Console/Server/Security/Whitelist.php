<?php
declare(strict_types=1);

/**
 * Weline Server - IP 白名单
 *
 * 将指定 IP/CIDR 加入 WLS 攻击探测白名单（ip_whitelist.ips），完全跳过攻击检测，
 * 并同步解封该 IP（清除已存在的封禁记录）。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server\Security;

use Weline\Framework\Console\CommandAbstract;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Service\Control\IpcControlGateway;

/**
 * server:security:whitelist - 添加 IP 白名单并解封
 */
class Whitelist extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $instanceName = $this->parseInstanceName($args);
        $ip = $args['ip'] ?? $args['i'] ?? null;
        $remove = isset($args['remove']) || isset($args['r']);

        if (!\is_string($ip) || \trim($ip) === '') {
            $this->printer->warning(__('请指定 --ip=<IP 或 CIDR>，如 64.225.44.152 或 10.0.0.0/8'));
            $this->printer->note(__('示例：php bin/w server:security:whitelist --ip=64.225.44.152'));
            $this->printer->note(__('移除白名单：php bin/w server:security:whitelist --ip=64.225.44.152 --remove'));
            $this->printer->note(__('指定实例：php bin/w server:security:whitelist --ip=1.2.3.4 -n default'));
            return;
        }

        $ip = \trim($ip);
        if (!\filter_var($ip, FILTER_VALIDATE_IP) && !$this->isValidCidr($ip)) {
            $this->printer->error(__('IP/CIDR 格式不正确：%{1}', [$ip]));
            return;
        }

        try {
            $detector = AttackDetector::getInstance();
            $receipt = $detector->getRulesReceipt();
            $rules = $detector->getRules();

            $whitelist = \is_array($rules['ip_whitelist'] ?? null) ? $rules['ip_whitelist'] : [];
            $ips = \is_array($whitelist['ips'] ?? null) ? $whitelist['ips'] : [];
            $existing = \array_values(\array_map('strval', $ips));

            if ($remove) {
                $filtered = \array_values(\array_filter(
                    $existing,
                    static fn(string $entry): bool => $entry !== $ip
                ));
                if (\count($filtered) === \count($existing)) {
                    $this->printer->note(__('该 IP 不在白名单中，无需移除：%{1}', [$ip]));
                    return;
                }
                $rules['ip_whitelist'] = ['enabled' => true, 'ips' => $filtered];
                $commit = $detector->updateRules($rules, (int)$receipt['generation'], (string)$receipt['digest']);
                $this->printer->success(__('已从白名单移除：%{1}（generation=%{2}）', [$ip, (string)$commit['generation']]));
            } else {
                if (\in_array($ip, $existing, true)) {
                    $this->printer->note(__('该 IP 已在白名单中：%{1}', [$ip]));
                } else {
                    $rules['ip_whitelist'] = ['enabled' => true, 'ips' => \array_merge($existing, [$ip])];
                    $commit = $detector->updateRules($rules, (int)$receipt['generation'], (string)$receipt['digest']);
                    $this->printer->success(__('已加入白名单：%{1}（generation=%{2}）', [$ip, (string)$commit['generation']]));
                }

                // 顺手解封，清除已存在的封禁记录（实例未运行时忽略失败）
                try {
                    $result = (new IpcControlGateway())->securityUnblock($instanceName, $ip, false);
                    if (!empty($result['success'])) {
                        $this->printer->note(__('已发送解封命令（实例：%{1}）', [$instanceName]));
                    }
                } catch (\Throwable) {
                    $this->printer->note(__('实例未运行或解封命令未送达（白名单仍已持久化）'));
                }
            }
        } catch (\Throwable $throwable) {
            $this->printer->error(__('白名单更新失败：%{1}', [$throwable->getMessage()]));
        }
    }

    private function isValidCidr(string $cidr): bool
    {
        if (!\str_contains($cidr, '/')) {
            return false;
        }
        [$subnet, $mask] = \explode('/', $cidr, 2);
        if (!\filter_var($subnet, FILTER_VALIDATE_IP) || !\ctype_digit($mask)) {
            return false;
        }
        $max = \str_contains($subnet, ':') ? 128 : 32;
        return (int)$mask >= 0 && (int)$mask <= $max;
    }

    protected function parseInstanceName(array $args): string
    {
        foreach (['instance', 'name', 'n'] as $option) {
            $instanceName = $args[$option] ?? null;
            if (\is_string($instanceName) && \trim($instanceName) !== '') {
                return \trim($instanceName);
            }
        }

        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (\is_int($key) && !\str_starts_with((string) $arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        \array_shift($positionalArgs);
        return $positionalArgs[0] ?? 'default';
    }

    public function tip(): string
    {
        return __('IP 白名单：添加/移除 IP 或 CIDR，完全跳过攻击检测并解封');
    }
}
