<?php

declare(strict_types=1);

namespace Weline\Mail\Extends\Module\Weline_SiteSetupAssistant\SetupTask;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Service\MailCustomerAccountService;
use Weline\Mail\Service\StalwartEngineAdapter;
use Weline\SiteSetupAssistant\Api\AbstractSetupTaskProvider;

/**
 * 自建邮局深度建站任务：引擎 → 域名 → DNS → 账号。
 * Smtp 传输/渠道绑定不在此 Provider；邮局账号就绪后由 Smtp「一键配置」自管。
 */
class MailSetupTaskProvider extends AbstractSetupTaskProvider
{
    public function provideTasks(array $context = []): array
    {
        $href = $this->backendPath('weline_mail/backend');
        $domains = $this->listDomains();
        $accounts = $this->listActiveAccounts();
        $activeDomains = array_values(array_filter(
            $domains,
            static fn(array $d): bool => (string)($d['status'] ?? '') === 'active'
        ));
        $fakeOnly = $activeDomains !== [] && $this->allDomainsFake($activeDomains);
        $env = $this->environmentReport();

        return $this->tasks([
            $this->engineTask($href, $env, $fakeOnly, $activeDomains !== []),
            $this->domainTask($href, $activeDomains, $domains),
            $this->dnsTask($href, $activeDomains, $fakeOnly),
            $this->accountTask($href, $accounts, $activeDomains !== []),
            $this->handOffSmtpTask($accounts !== []),
        ]);
    }

    /**
     * @param array{ok:bool,checks:list<array<string,mixed>>} $env
     * @return array<string, mixed>
     */
    private function engineTask(string $href, array $env, bool $fakeOnly, bool $hasActiveDomain): array
    {
        if (!empty($env['ok'])) {
            return [
                'code' => 'mail_engine',
                'parent_code' => 'mail',
                'sort' => 10,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('安装并启动邮局引擎'),
                'tip' => (string)__('Stalwart 环境检测已通过（二进制/端口/服务）。仍可用：php bin/w mail:service:status'),
                'status' => 'done',
                'href' => $href,
                'meta' => ['env_ok' => true, 'fake_only' => $fakeOnly],
            ];
        }

        if ($fakeOnly) {
            return [
                'code' => 'mail_engine',
                'parent_code' => 'mail',
                'sort' => 10,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('安装并启动邮局引擎'),
                'tip' => (string)__('当前仅 fake 测试域：可先冒烟。上线真实域名前须安装 Stalwart：php bin/w env:install stalwart-mail-server -y，并检查 php bin/w mail:env:check'),
                'status' => 'doing',
                'href' => $href,
                'meta' => ['env_ok' => false, 'fake_only' => true],
            ];
        }

        $failed = [];
        foreach ($env['checks'] as $check) {
            if (empty($check['ok'])) {
                $failed[] = (string)($check['name'] ?? '');
            }
        }
        $sample = implode('、', array_slice(array_filter($failed), 0, 4));

        return [
            'code' => 'mail_engine',
            'parent_code' => 'mail',
            'sort' => 10,
            'category' => (string)__('通信'),
            'module' => 'Weline_Mail',
            'title' => (string)__('安装并启动邮局引擎'),
            'tip' => (string)__('邮局引擎未就绪%{1}。请执行：php bin/w mail:env:check → php bin/w env:install stalwart-mail-server -y → php bin/w mail:service:status', [
                $sample !== '' ? '（缺：' . $sample . '）' : '',
            ]),
            'status' => $hasActiveDomain ? 'doing' : 'todo',
            'href' => $href,
            'meta' => ['env_ok' => false, 'failed' => $failed],
        ];
    }

    /**
     * @param list<array<string,mixed>> $activeDomains
     * @param list<array<string,mixed>> $allDomains
     * @return array<string, mixed>
     */
    private function domainTask(string $href, array $activeDomains, array $allDomains): array
    {
        if ($activeDomains !== []) {
            $names = array_map(static fn(array $d): string => (string)($d['domain_name'] ?? ''), $activeDomains);
            $sample = implode(', ', array_slice(array_filter($names), 0, 3));

            return [
                'code' => 'mail_domain',
                'parent_code' => 'mail',
                'sort' => 20,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('开通邮局域名'),
                'tip' => (string)__('已有 %{1} 个 active 域名（例：%{2}）。真实域须从 Websites 候选选择。', [
                    (string)count($activeDomains),
                    $sample,
                ]),
                'status' => 'done',
                'href' => $href,
                'meta' => ['active_count' => count($activeDomains)],
            ];
        }

        if ($allDomains !== []) {
            return [
                'code' => 'mail_domain',
                'parent_code' => 'mail',
                'sort' => 20,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('开通邮局域名'),
                'tip' => (string)__('已有域名记录但未 active。请在企业邮箱「域名与 DNS」启用域名。'),
                'status' => 'doing',
                'href' => $href,
                'meta' => ['active_count' => 0, 'total' => count($allDomains)],
            ];
        }

        return [
            'code' => 'mail_domain',
            'parent_code' => 'mail',
            'sort' => 20,
            'category' => (string)__('通信'),
            'module' => 'Weline_Mail',
            'title' => (string)__('开通邮局域名'),
            'tip' => (string)__('尚未开通邮局域名。后台 → 企业邮箱管理 → 从 Websites 候选创建真实域；本机冒烟可用 .test/.invalid fake 域。'),
            'status' => 'todo',
            'href' => $href,
            'meta' => ['active_count' => 0],
        ];
    }

    /**
     * @param list<array<string,mixed>> $activeDomains
     * @return array<string, mixed>
     */
    private function dnsTask(string $href, array $activeDomains, bool $fakeOnly): array
    {
        if ($activeDomains === []) {
            return [
                'code' => 'mail_dns',
                'parent_code' => 'mail',
                'sort' => 30,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('配置邮箱 DNS'),
                'tip' => (string)__('请先开通并启用邮局域名，再配置 MX/SPF/DKIM/DMARC 与 mail 主机 A 记录（禁止橙云代理）。'),
                'status' => 'todo',
                'href' => $href,
                'meta' => [],
            ];
        }

        if ($fakeOnly) {
            return [
                'code' => 'mail_dns',
                'parent_code' => 'mail',
                'sort' => 30,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('配置邮箱 DNS'),
                'tip' => (string)__('当前为 fake 测试域，无需公网 DNS。上线真实域时在域名页按清单配置，或 php bin/w mail:dns:check example.com mail.example.com'),
                'status' => 'done',
                'href' => $href,
                'meta' => ['fake_only' => true],
            ];
        }

        $pending = [];
        foreach ($activeDomains as $domain) {
            if (!empty($domain['is_fake'])) {
                continue;
            }
            $json = (string)($domain['dns_status_json'] ?? '');
            $ok = false;
            if ($json !== '') {
                $decoded = json_decode($json, true);
                if (is_array($decoded) && (!empty($decoded['ok']) || ($decoded['status'] ?? '') === 'ok' || ($decoded['status'] ?? '') === 'pass')) {
                    $ok = true;
                }
            }
            if (!$ok) {
                $pending[] = (string)($domain['domain_name'] ?? '');
            }
        }

        if ($pending === []) {
            return [
                'code' => 'mail_dns',
                'parent_code' => 'mail',
                'sort' => 30,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('配置邮箱 DNS'),
                'tip' => (string)__('真实域 DNS 状态已记录为通过。仍建议抽测外域收发与 SPF/DKIM/DMARC。'),
                'status' => 'done',
                'href' => $href,
                'meta' => ['pending' => []],
            ];
        }

        $sample = implode(', ', array_slice(array_filter($pending), 0, 3));

        return [
            'code' => 'mail_dns',
            'parent_code' => 'mail',
            'sort' => 30,
            'category' => (string)__('通信'),
            'module' => 'Weline_Mail',
            'title' => (string)__('配置邮箱 DNS'),
            'tip' => (string)__('域名 %{1} 需完成 DNS：A(mail)/MX/SPF/DKIM/DMARC；mail 主机必须 DNS-only。后台域名页可连 Cloudflare 一键应用，或 mail:dns:check。', [
                $sample,
            ]),
            'status' => 'todo',
            'href' => $href,
            'meta' => ['pending' => $pending],
        ];
    }

    /**
     * @param list<array<string,mixed>> $accounts
     * @return array<string, mixed>
     */
    private function accountTask(string $href, array $accounts, bool $hasActiveDomain): array
    {
        if ($accounts !== []) {
            $sample = (string)($accounts[0]['email'] ?? '');

            return [
                'code' => 'mail_account',
                'parent_code' => 'mail',
                'sort' => 40,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('开通邮局账号'),
                'tip' => (string)__('已有 %{1} 个 active 邮箱账号（例：%{2}）。建议至少保留 noreply@ 供系统发信。', [
                    (string)count($accounts),
                    $sample,
                ]),
                'status' => 'done',
                'href' => $href,
                'meta' => ['active_count' => count($accounts)],
            ];
        }

        if (!$hasActiveDomain) {
            return [
                'code' => 'mail_account',
                'parent_code' => 'mail',
                'sort' => 40,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('开通邮局账号'),
                'tip' => (string)__('请先启用邮局域名，再在「用户与账号」创建并启用邮箱。'),
                'status' => 'todo',
                'href' => $href,
                'meta' => ['active_count' => 0],
            ];
        }

        return [
            'code' => 'mail_account',
            'parent_code' => 'mail',
            'sort' => 40,
            'category' => (string)__('通信'),
            'module' => 'Weline_Mail',
            'title' => (string)__('开通邮局账号'),
            'tip' => (string)__('域名已就绪，但尚无 active 邮箱账号。请创建 noreply@ 或业务邮箱并启用。'),
            'status' => 'todo',
            'href' => $href,
            'meta' => ['active_count' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handOffSmtpTask(bool $hasAccounts): array
    {
        $scope = 'default.default.default';
        $smtpPath = 'none';
        $smtpConfirmed = false;
        try {
            if (class_exists(\Weline\Smtp\Service\MailAccountTransportProvisioner::class)) {
                /** @var \Weline\Smtp\Service\MailAccountTransportProvisioner $provisioner */
                $provisioner = ObjectManager::getInstance(\Weline\Smtp\Service\MailAccountTransportProvisioner::class);
                $smtpPath = (string)($provisioner->detectSendPath($scope)['path'] ?? 'none');
            }
            if (class_exists(\Weline\Smtp\Helper\Data::class)) {
                /** @var \Weline\Smtp\Helper\Data $smtpData */
                $smtpData = ObjectManager::getInstance(\Weline\Smtp\Helper\Data::class);
                $smtpConfirmed = $smtpData->isSetupConfirmed('Weline_Smtp', $scope);
            }
        } catch (\Throwable) {
        }

        if ($smtpPath === 'mail_account' && $smtpConfirmed) {
            return [
                'code' => 'mail_smtp_handoff',
                'parent_code' => 'mail',
                'sort' => 50,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('交给 Smtp 自管发信'),
                'tip' => (string)__('Smtp 已挂接自建邮局并完成测试确认。'),
                'status' => 'done',
                'href' => $this->backendPath('smtp/backend/config'),
                'meta' => ['has_accounts' => $hasAccounts, 'smtp_path' => $smtpPath, 'confirmed' => true],
            ];
        }

        if ($smtpPath === 'mail_account') {
            return [
                'code' => 'mail_smtp_handoff',
                'parent_code' => 'mail',
                'sort' => 50,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('交给 Smtp 自管发信'),
                'tip' => (string)__('Smtp 已挂接自建邮局传输，请打开 Smtp配置 发送测试邮件完成确认。'),
                'status' => 'doing',
                'href' => $this->backendPath('smtp/backend/config'),
                'meta' => ['has_accounts' => $hasAccounts, 'smtp_path' => $smtpPath, 'confirmed' => false],
            ];
        }

        if ($hasAccounts) {
            return [
                'code' => 'mail_smtp_handoff',
                'parent_code' => 'mail',
                'sort' => 50,
                'category' => (string)__('通信'),
                'module' => 'Weline_Mail',
                'title' => (string)__('交给 Smtp 自管发信'),
                'tip' => (string)__('邮局账号已就绪。请打开 Smtp配置，使用「用自建邮局一键配置」由 Smtp 自动写传输并绑渠道，再测试发信确认。'),
                'status' => 'doing',
                'href' => $this->backendPath('smtp/backend/config?ensure_mail=1'),
                'meta' => ['has_accounts' => true, 'smtp_path' => $smtpPath],
            ];
        }

        return [
            'code' => 'mail_smtp_handoff',
            'parent_code' => 'mail',
            'sort' => 50,
            'category' => (string)__('通信'),
            'module' => 'Weline_Mail',
            'title' => (string)__('交给 Smtp 自管发信'),
            'tip' => (string)__('完成引擎/域名/DNS/账号后，由 Smtp 一键挂接 mail_account 传输；本任务不代替 Smtp 测试确认。'),
            'status' => 'todo',
            'href' => $this->backendPath('smtp/backend/config'),
            'meta' => ['has_accounts' => false, 'smtp_path' => $smtpPath],
        ];
    }

    /**
     * @return array{ok:bool,checks:list<array<string,mixed>>}
     */
    private function environmentReport(): array
    {
        try {
            /** @var StalwartEngineAdapter $engine */
            $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
            $result = $engine->checkEnvironment();

            return [
                'ok' => !empty($result['ok']),
                'checks' => is_array($result['checks'] ?? null) ? $result['checks'] : [],
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'checks' => []];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listDomains(): array
    {
        try {
            /** @var MailDomain $model */
            $model = ObjectManager::getInstance(MailDomain::class);
            $items = $model->clear()->select()->fetch()->getItems();
            /** @var MailCustomerAccountService $customer */
            $customer = ObjectManager::getInstance(MailCustomerAccountService::class);
            $out = [];
            foreach ($items as $item) {
                if (!is_object($item) || !method_exists($item, 'getData')) {
                    continue;
                }
                $row = [
                    'id' => (int)$item->getData(MailDomain::schema_fields_ID),
                    'domain_name' => (string)$item->getData(MailDomain::schema_fields_DOMAIN_NAME),
                    'status' => (string)$item->getData(MailDomain::schema_fields_STATUS),
                    'dns_status_json' => (string)$item->getData(MailDomain::schema_fields_DNS_STATUS_JSON),
                    'is_fake' => $customer->isFakeDomain($item),
                ];
                $out[] = $row;
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listActiveAccounts(): array
    {
        try {
            $result = w_query('mail', 'getSmtpAccounts', ['limit' => 200]);
            if (is_array($result) && !empty($result['success']) && is_array($result['items'] ?? null)) {
                return $result['items'];
            }
        } catch (\Throwable) {
        }

        try {
            /** @var MailAccount $model */
            $model = ObjectManager::getInstance(MailAccount::class);
            $items = $model->clear()
                ->where(MailAccount::schema_fields_STATUS, 'active')
                ->select()
                ->fetch()
                ->getItems();
            $out = [];
            foreach ($items as $item) {
                if (!is_object($item) || !method_exists($item, 'getData')) {
                    continue;
                }
                $out[] = [
                    'account_id' => (int)$item->getId(),
                    'email' => (string)$item->getData(MailAccount::schema_fields_EMAIL),
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string,mixed>> $domains
     */
    private function allDomainsFake(array $domains): bool
    {
        if ($domains === []) {
            return false;
        }
        foreach ($domains as $domain) {
            if (empty($domain['is_fake'])) {
                return false;
            }
        }

        return true;
    }
}
