<?php
declare(strict_types=1);

namespace Weline\Server\Console\Ssl;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\SslCertificateService;

/**
 * 从证书管理重载证书文件并刷新 WLS 映射。
 */
class Reload extends CommandAbstract
{
    /** @var string[] */
    public const ALIASES = ['server:ssl:reload', 'cert:reload'];

    public function __construct(
        private readonly SslCertificateService $sslService
    ) {
    }

    public function execute(array $args = [], array $data = [])
    {
        $domain = $args['domain'] ?? $args['d'] ?? null;
        $domain = \is_string($domain) ? \trim($domain) : null;
        if ($domain === '') {
            $domain = null;
        }
        $clearNoPem = isset($args['clear-no-pem']);

        if ($domain !== null) {
            $this->printer->note(__('正在重载域名 %{1} 的证书...', [$domain]));
        } else {
            $this->printer->note(__('正在从证书管理重载所有可用证书...'));
        }
        if ($clearNoPem) {
            $this->printer->note(__('已启用 --clear-no-pem：缺少 PEM 的证书记录将被删除'));
        }

        $result = $this->sslService->reloadManagedCertificates($domain, $clearNoPem);

        if ($result['domains'] !== []) {
            $this->printer->success(__('成功重载 %{1} 个证书', [$result['reloaded']]));
            foreach ($result['domains'] as $reloadedDomain) {
                $this->printer->note(__('  ✓ %{1}', [$reloadedDomain]));
            }
        }

        if ($result['expired'] > 0) {
            $this->printer->warning(__('发现 %{1} 个已过期证书，已发送系统通知', [$result['expired']]));
        }

        if (($result['deleted'] ?? 0) > 0 && ($result['deleted_domains'] ?? []) !== []) {
            $this->printer->warning(__('已删除 %{1} 条缺少 PEM 的证书记录', [$result['deleted']]));
            foreach ($result['deleted_domains'] as $deletedDomain) {
                $this->printer->note(__('  ✗ 已删除 %{1}', [$deletedDomain]));
            }
        }

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $error) {
                $this->printer->error($error);
            }
        }

        if (($result['reloaded'] ?? 0) > 0 || ($result['deleted'] ?? 0) > 0) {
            $this->printer->note(__('已刷新本地证书文件、SNI 映射和 WLS 证书缓存'));
            return;
        }

        if ($result['errors'] === [] && ($result['expired'] ?? 0) === 0) {
            $this->printer->warning(__('没有需要重载的证书'));
        }
    }

    public function tip(): string
    {
        return __('从证书管理重载证书文件并刷新 WLS 映射');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'ssl:reload',
            __('从证书管理中读取 PEM 内容，重载到本地证书目录，并刷新 WLS 的 SNI 证书映射'),
            [
                '-d, --domain <domain>' => __('只重载指定域名的证书'),
                '--clear-no-pem' => __('若证书记录缺少 PEM 内容，则从证书管理中删除该记录'),
            ],
            [
                __('默认行为') => __('重载所有启用了 HTTPS 的有效/异常证书记录；已过期证书仅通知不重载'),
            ],
            [
                __('重载全部证书') => 'php bin/w ssl:reload',
                __('重载单个域名') => 'php bin/w ssl:reload -d www.example.com',
                __('删除缺少 PEM 的记录') => 'php bin/w ssl:reload --clear-no-pem',
            ]
        );
    }
}
