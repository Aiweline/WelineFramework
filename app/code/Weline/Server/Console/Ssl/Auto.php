<?php
declare(strict_types=1);

/**
 * Weline Server - SSL 自动申请/续签证书命令
 * 
 * 使用 Let's Encrypt 为网站自动申请和续签 SSL 证书
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Ssl;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\SslCertificate;
use Weline\Server\Service\SslCertificateService;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * ssl:auto - 自动申请/续签 SSL 证书（含 localhost 自签）
 */
class Auto extends CommandAbstract
{
    /**
     * 命令别名
     */
    /** @var string[] 含 server:ssl:auto 以兼容旧文档 */
    public const ALIASES = ['ssl:auto', 'cert:auto', 'server:ssl:auto'];
    
    /**
     * @var SslCertificateService
     */
    protected SslCertificateService $sslService;
    
    /**
     * @var SslCertificate
     */
    protected SslCertificate $certModel;
    
    public function __construct(
        SslCertificateService $sslService,
        SslCertificate $certModel
    ) {
        $this->sslService = $sslService;
        $this->certModel = $certModel;
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('自动申请/续签 SSL 证书（Let\'s Encrypt/LiteSSL）');
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析参数（args[0] 为命令名 ssl:auto，子命令 request/renew/list 等在 args[1]）
        $possibleAction = $args[1] ?? $args[0] ?? 'status';
        $actions = ['request', 'apply', 'renew', 'list', 'ls', 'sync', 'enable', 'disable', 'status'];
        $action = \in_array((string) $possibleAction, $actions, true) ? $possibleAction : 'status';
        $domain = $args['domain'] ?? $args['d'] ?? null;
        $email = $args['email'] ?? $args['e'] ?? $this->getDefaultEmail();
        $webroot = $args['webroot'] ?? $args['w'] ?? BP . 'pub';
        $provider = $args['provider'] ?? $args['p'] ?? SslCertificateService::PROVIDER_LETS_ENCRYPT;
        $staging = isset($args['staging']) || isset($args['test']);
        $renewDays = (int) ($args['renew-days'] ?? 30);
        
        // 设置环境
        if ($staging) {
            $this->sslService->setStaging(true);
            $this->printer->warning(__('使用 Let\'s Encrypt 测试环境'));
        }
        
        switch ($action) {
            case 'request':
            case 'apply':
                $this->requestCertificate($domain, $webroot, $email, (string) $provider);
                break;
                
            case 'renew':
                $this->renewCertificates($domain, $webroot, $email, $renewDays);
                break;
                
            case 'list':
            case 'ls':
                $this->listCertificates();
                break;
                
            case 'status':
            default:
                $this->showStatus();
                break;
                
            case 'sync':
                $this->syncWebsiteDomains($email, $webroot);
                break;
                
            case 'enable':
                $this->toggleHttps($domain, true);
                break;
                
            case 'disable':
                $this->toggleHttps($domain, false);
                break;
        }
    }
    
    /**
     * 申请证书
     */
    protected function requestCertificate(?string $domain, string $webroot, string $email, string $provider): void
    {
        if (empty($domain)) {
            $this->printer->error(__('请指定域名：--domain example.com'));
            return;
        }
        
        $this->printer->note(__('正在为 %{1} 申请 SSL 证书...', [$domain]));
        $this->printer->note(__('联系邮箱：%{1}', [$email]));
        $this->printer->note(__('Webroot：%{1}', [$webroot]));
        echo "\n";
        
        // 只有本地域名（localhost、127.0.0.1、IP 地址等）才用自签证书
        // 线上域名即使在 dev 环境也应申请真证书
        $useEnsure = $this->sslService->isLocalDomain($domain)
            || $this->sslService->resolvesToLoopback($domain);
        if ($useEnsure) {
            $this->printer->note(__('检测到本地域名，将生成自签证书'));
            $result = $this->sslService->ensureCertificate($domain, $webroot, $email);
            if ($result['success']) {
                $this->printer->success(__('✓ 自签证书生成成功！'));
                echo "\n";
                $this->printer->note(__('证书路径：%{1}', [$result['cert_path'] ?? '']));
                $this->printer->note(__('私钥路径：%{1}', [$result['key_path'] ?? '']));
                $this->printer->note(__('到期时间：%{1}', [$result['expires_at'] ?? '']));
                echo "\n";
                $this->printer->setup(__('启用 HTTPS：'));
                $this->printer->note('  php bin/w ssl:auto enable --domain ' . $domain);
            } else {
                $this->printer->error(__('✗ 证书生成失败：%{1}', [$result['message'] ?? '']));
            }
            return;
        }
        
        // Let's Encrypt 申请前检查：Windows 需 event 扩展才能后续启动 HTTPS
        $readinessWarning = $this->sslService->getHttpsReadinessWarning();
        if ($readinessWarning !== null) {
            $this->printer->error($readinessWarning);
            $this->printer->note(__('请先安装 event 扩展后再执行申请证书。'));
            return;
        }
        
        $this->printer->note(__('证书提供商：%{1}', [$provider]));
        echo "\n";
        if (!\is_dir($webroot)) {
            $this->printer->error(__('Webroot 目录不存在：%{1}', [$webroot]));
            return;
        }
        
        $result = $this->sslService->requestCertificate($domain, $webroot, $email, 0, $provider);
        
        if ($result['success']) {
            $this->printer->success(__('✓ 证书申请成功！'));
            echo "\n";
            
            $cert = $result['cert'];
            $this->printer->note(__('证书路径：%{1}', [$cert->getCertPath()]));
            $this->printer->note(__('私钥路径：%{1}', [$cert->getKeyPath()]));
            $this->printer->note(__('到期时间：%{1}', [$cert->getExpiresAt()]));
            $this->printer->note(__('证书提供商：%{1}', [$cert->getProvider() ?: $provider]));
            echo "\n";
            
            $this->printer->setup(__('启用 HTTPS：'));
            $this->printer->note('  php bin/w ssl:auto enable --domain ' . $domain);
        } else {
            $this->printer->error(__('✗ 证书申请失败：%{1}', [$result['message']]));
        }
    }
    
    /**
     * 续签证书
     */
    protected function renewCertificates(?string $domain, string $webroot, string $email, int $renewDays): void
    {
        // 续签后可能启用 HTTPS，Windows 下同样需 event 扩展
        $readinessWarning = $this->sslService->getHttpsReadinessWarning();
        if ($readinessWarning !== null) {
            $this->printer->warning($readinessWarning);
            $this->printer->note(__('续签将继续执行，但启用 HTTPS 前请先安装 event 扩展。'));
        }
        
        if ($domain) {
            // 续签指定域名
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                $this->printer->error(__('未找到域名证书：%{1}', [$domain]));
                return;
            }
            
            $this->printer->note(__('正在续签 %{1} 的证书...', [$domain]));
            $result = $this->sslService->renewCertificate($cert, $webroot, $email);
            
            if ($result['success']) {
                $this->printer->success(__('✓ 证书续签成功'));
            } else {
                $this->printer->error(__('✗ 证书续签失败：%{1}', [$result['message']]));
            }
        } else {
            // 续签所有即将过期的证书
            $this->printer->note(__('检查需要续签的证书（%{1} 天内过期）...', [$renewDays]));
            
            $results = $this->sslService->renewExpiringCertificates($webroot, $email, $renewDays);
            
            if (empty($results)) {
                $this->printer->success(__('没有需要续签的证书'));
                return;
            }
            
            $successCount = 0;
            $failCount = 0;
            
            foreach ($results as $dom => $result) {
                if ($result['success']) {
                    $this->printer->success(__('  ✓ %{1} - 续签成功', [$dom]));
                    $successCount++;
                } else {
                    $this->printer->error(__('  ✗ %{1} - 续签失败：%{2}', [$dom, $result['message']]));
                    $failCount++;
                }
            }
            
            echo "\n";
            $this->printer->setup(__('续签完成：成功 %{1} 个，失败 %{2} 个', [$successCount, $failCount]));
        }
    }
    
    /**
     * 列出所有证书
     */
    protected function listCertificates(): void
    {
        $certificates = $this->certModel->clearQuery()
            ->order(SslCertificate::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
        
        if (empty($certificates)) {
            $this->printer->note(__('暂无证书记录'));
            $this->printer->setup(__('申请证书：php bin/w ssl:auto request -d example.com -e admin@example.com'));
            return;
        }
        
        $this->printer->setup(__('SSL 证书列表'));
        echo "\n";
        
        $this->printer->note(\sprintf(
            '%-30s %-12s %-10s %-20s %-6s',
            __('域名'),
            __('状态'),
            __('HTTPS'),
            __('到期时间'),
            __('自动续签')
        ));
        $this->printer->note(\str_repeat('-', 90));
        
        foreach ($certificates as $cert) {
            $domain = $cert[SslCertificate::schema_fields_DOMAIN];
            $status = $cert[SslCertificate::schema_fields_STATUS];
            $httpsEnabled = $cert[SslCertificate::schema_fields_HTTPS_ENABLED] ? '✓' : '✗';
            $expiresAt = $cert[SslCertificate::schema_fields_EXPIRES_AT] ?: '-';
            $autoRenew = $cert[SslCertificate::schema_fields_AUTO_RENEW] ? '✓' : '✗';
            
            // 状态颜色
            $statusText = match ($status) {
                SslCertificate::STATUS_ACTIVE => __('有效'),
                SslCertificate::STATUS_EXPIRED => __('已过期'),
                SslCertificate::STATUS_PENDING => __('待申请'),
                SslCertificate::STATUS_ERROR => __('错误'),
                default => $status,
            };
            
            echo \sprintf(
                "%-30s %-12s %-10s %-20s %-6s\n",
                $domain,
                $statusText,
                $httpsEnabled,
                $expiresAt,
                $autoRenew
            );
        }
    }
    
    /**
     * 显示状态概览
     */
    protected function showStatus(): void
    {
        $this->printer->setup(__('Weline Server SSL 证书管理'));
        echo "\n";
        
        // 统计信息
        $total = $this->certModel->clearQuery()->total();
        $active = $this->certModel->clearQuery()
            ->where(SslCertificate::schema_fields_STATUS, SslCertificate::STATUS_ACTIVE)
            ->total();
        $httpsEnabled = $this->certModel->clearQuery()
            ->where(SslCertificate::schema_fields_HTTPS_ENABLED, 1)
            ->total();
        $expiringSoon = \count($this->certModel->getCertificatesNeedRenew(30));
        
        $this->printer->note(__('证书总数：%{1}', [$total]));
        $this->printer->note(__('有效证书：%{1}', [$active]));
        $this->printer->note(__('HTTPS 启用：%{1}', [$httpsEnabled]));
        
        if ($expiringSoon > 0) {
            $this->printer->warning(__('即将过期（30天内）：%{1}', [$expiringSoon]));
        }
        
        echo "\n";
        $this->printer->setup(__('可用命令：'));
$this->printer->note('  ssl:auto list                    ' . __('- 查看证书列表'));
            $this->printer->note('  ssl:auto request -d example.com  ' . __('- 申请新证书（localhost 自动生成自签证书）'));
            $this->printer->note('  ssl:auto renew                   ' . __('- 续签到期证书'));
            $this->printer->note('  ssl:auto sync                    ' . __('- 同步网站域名'));
            $this->printer->note('  ssl:auto enable -d example.com   ' . __('- 启用 HTTPS'));
            $this->printer->note('  ssl:auto disable -d example.com  ' . __('- 禁用 HTTPS'));
    }
    
    /**
     * 同步网站域名并申请证书
     */
    protected function syncWebsiteDomains(string $email, string $webroot): void
    {
        $this->printer->note(__('从 Weline_Websites 同步域名...'));
        
        // 获取所有网站域名
        $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
        $domains = $domainModel->clearQuery()
            ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        
        if (empty($domains)) {
            // 尝试从 Website 模型获取
            $websiteModel = ObjectManager::getInstance(Website::class);
            $websites = $websiteModel->clearQuery()->select()->fetchArray();
            
            foreach ($websites as $website) {
                $url = $website[Website::schema_fields_URL] ?? '';
                $domain = $this->extractDomainFromUrl($url);
                
                if ($domain) {
                    $domains[] = [
                        WebsiteDomain::schema_fields_DOMAIN => $domain,
                        WebsiteDomain::schema_fields_WEBSITE_ID => $website[Website::schema_fields_ID],
                    ];
                }
            }
        }
        
        if (empty($domains)) {
            $this->printer->warning(__('未找到任何网站域名'));
            return;
        }
        
        $this->printer->note(__('找到 %{1} 个域名', [\count($domains)]));
        echo "\n";
        
        foreach ($domains as $domainData) {
            $domain = $domainData[WebsiteDomain::schema_fields_DOMAIN] ?? '';
            $websiteId = (int) ($domainData[WebsiteDomain::schema_fields_WEBSITE_ID] ?? 0);
            
            if (empty($domain) || $domain === 'localhost' || \filter_var($domain, FILTER_VALIDATE_IP)) {
                continue;
            }
            
            // 检查是否已有证书
            $existingCert = $this->certModel->clearQuery()->loadByDomain($domain);
            
            if ($existingCert->getCertId()) {
                if ($existingCert->getStatus() === SslCertificate::STATUS_ACTIVE) {
                    $this->printer->note(__('  ✓ %{1} - 已有有效证书', [$domain]));
                    continue;
                }
            }
            
            // 申请证书
            $this->printer->note(__('  → %{1} - 申请证书...', [$domain]));
            $result = $this->sslService->requestCertificate($domain, $webroot, $email, $websiteId);
            
            if ($result['success']) {
                $this->printer->success(__('    ✓ 成功'));
            } else {
                $this->printer->error(__('    ✗ 失败：%{1}', [$result['message']]));
            }
        }
    }
    
    /**
     * 切换 HTTPS 状态
     */
    protected function toggleHttps(?string $domain, bool $enabled): void
    {
        if (empty($domain)) {
            $this->printer->error(__('请指定域名：--domain example.com'));
            return;
        }
        
        $action = $enabled ? __('启用') : __('禁用');
        $this->printer->note(__('正在 %{1} %{2} 的 HTTPS...', [$action, $domain]));
        
        $result = $this->sslService->toggleHttps($domain, $enabled);
        
        if ($result['success']) {
            $this->printer->success(__('✓ %{1}', [$result['message']]));
            
            if ($enabled) {
                echo "\n";
                $this->printer->warning(__('重要：请重启服务器使配置生效'));
                $this->printer->note('  php bin/w server:restart');
            }
        } else {
            $this->printer->error(__('✗ %{1}', [$result['message']]));
        }
    }
    
    /**
     * 从 URL 提取域名
     */
    protected function extractDomainFromUrl(string $url): string
    {
        $parsed = \parse_url($url);
        return $parsed['host'] ?? '';
    }
    
    /**
     * 获取默认邮箱
     */
    protected function getDefaultEmail(): string
    {
        // 尝试从配置获取
        $envConfig = \Weline\Framework\App\Env::getInstance()->getConfig();
        return $envConfig['admin_email'] ?? $envConfig['email'] ?? 'admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'ssl:auto [action]',
            __('使用 Let\'s Encrypt / LiteSSL 自动管理 SSL 证书'),
            [
                '[action]' => __('操作：status/list/request/renew/sync/enable/disable'),
                '-d, --domain <domain>' => __('指定域名'),
                '-e, --email <email>' => __('联系邮箱（Let\'s Encrypt 要求）'),
                '-w, --webroot <path>' => __('Webroot 路径（默认：pub/）'),
                '-p, --provider <provider>' => __('证书提供商（letsencrypt/litessl）'),
                '--renew-days <days>' => __('提前续签天数（默认：30）'),
                '--staging' => __('使用 Let\'s Encrypt 测试环境'),
            ],
            [
                'status' => __('显示证书状态概览（默认）'),
                'list' => __('列出所有证书'),
                'request' => __('申请新证书'),
                'renew' => __('续签到期证书'),
                'sync' => __('同步网站域名并申请证书'),
                'enable' => __('启用指定域名的 HTTPS'),
                'disable' => __('禁用指定域名的 HTTPS'),
            ],
            [
                __('查看状态') => 'php bin/w ssl:auto',
                __('列出证书') => 'php bin/w ssl:auto list',
                __('申请证书') => 'php bin/w ssl:auto request -d localhost -e admin@localhost',
                __('续签证书') => 'php bin/w ssl:auto renew',
                __('同步并申请') => 'php bin/w ssl:auto sync -e admin@example.com',
                __('启用 HTTPS') => 'php bin/w ssl:auto enable -d example.com',
                __('禁用 HTTPS') => 'php bin/w ssl:auto disable -d example.com',
            ]
        );
    }
}
