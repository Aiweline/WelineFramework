<?php
declare(strict_types=1);

namespace Weline\Mail\Console\Mail\Dns;

use Weline\Mail\Service\DnsRecordAdvisor;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

class Check extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $domain = (string)($args[1] ?? $args['domain'] ?? '');
        $hostname = (string)($args[2] ?? $args['host'] ?? ('mail.' . $domain));
        if ($domain === '') {
            $this->printer->warning(__('请提供域名，例如：php bin/w mail:dns:check example.com mail.example.com'));
            return;
        }

        /** @var DnsRecordAdvisor $advisor */
        $advisor = ObjectManager::getInstance(DnsRecordAdvisor::class);
        $result = $advisor->check($domain, $hostname);

        $this->printer->note(__('========== 企业邮箱 DNS 检测 =========='));
        foreach ($result['records'] as $record) {
            $line = $record['type'] . ' ' . $record['host'] . ' => ' . $record['value'];
            if ($record['ok']) {
                $this->printer->success(__('✔ %{1}', [$line]));
            } else {
                $this->printer->warning(__('✖ %{1}', [$line]));
            }
        }
    }

    public function tip(): string
    {
        return __('检测企业邮箱 DNS 记录');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'mail:dns:check',
            __('检测 MX/SPF/DKIM/DMARC 记录是否存在'),
            [],
            ['domain' => __('邮箱域名'), 'host' => __('邮件服务主机名')],
            ['检测域名' => 'php bin/w mail:dns:check example.com mail.example.com']
        );
    }
}
