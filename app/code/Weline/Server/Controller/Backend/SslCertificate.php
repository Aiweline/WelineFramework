<?php
declare(strict_types=1);

/**
 * Weline Server - SSL 证书后台管理控制器
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Server\Model\SslCertificate as CertModel;
use Weline\Server\Service\SslCertificateService;

class SslCertificate extends BaseController
{
    /**
     * @var CertModel
     */
    protected CertModel $certModel;
    
    /**
     * @var SslCertificateService
     */
    protected SslCertificateService $sslService;
    
    public function __construct(
        CertModel $certModel,
        SslCertificateService $sslService
    ) {
        $this->certModel = $certModel;
        $this->sslService = $sslService;
    }
    
    /**
     * 证书列表页
     */
    public function getIndex(): string
    {
        // 框架级收敛：页面加载时自动把本地证书目录同步到数据库，保证“正在使用的证书”可见。
        $this->sslService->syncCertificatesFromStorage();

        // 获取所有证书
        $certificates = $this->certModel->clearQuery()
            ->order(CertModel::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
        $certificates = \array_map([$this, 'stripSensitiveCertificateFields'], $certificates);
        
        // 统计信息
        $stats = [
            'total' => \count($certificates),
            'active' => 0,
            'https_enabled' => 0,
            'expiring_soon' => 0,
            'expired' => 0,
        ];
        
        $now = \time();
        $warningTime = $now + (30 * 86400);
        
        foreach ($certificates as &$cert) {
            // 计算状态
            if ($cert[CertModel::schema_fields_STATUS] === CertModel::STATUS_ACTIVE) {
                $stats['active']++;
            }
            if ($cert[CertModel::schema_fields_HTTPS_ENABLED]) {
                $stats['https_enabled']++;
            }
            
            $expiresAt = $cert[CertModel::schema_fields_EXPIRES_AT] ?? '';
            if ($expiresAt) {
                $expiresTime = \strtotime($expiresAt);
                if ($expiresTime < $now) {
                    $stats['expired']++;
                    $cert['_expiry_status'] = 'expired';
                } elseif ($expiresTime < $warningTime) {
                    $stats['expiring_soon']++;
                    $cert['_expiry_status'] = 'warning';
                } else {
                    $cert['_expiry_status'] = 'ok';
                }
                
                // 计算剩余天数
                $cert['_days_left'] = \max(0, \floor(($expiresTime - $now) / 86400));
            } else {
                $cert['_expiry_status'] = 'unknown';
                $cert['_days_left'] = null;
            }
        }
        
        $this->assign('certificates', $certificates);
        $this->assign('stats', $stats);
        $this->assign('title', __('SSL 证书管理'));
        
        return $this->fetch('index');
    }
    
    /**
     * 获取证书列表（AJAX）
     */
    public function getList(): string
    {
        $this->sslService->syncCertificatesFromStorage();

        $certificates = $this->certModel->clearQuery()
            ->order(CertModel::schema_fields_DOMAIN)
            ->select()
            ->fetchArray();
        $certificates = \array_map([$this, 'stripSensitiveCertificateFields'], $certificates);

        return $this->fetchJson(['success' => true, 'data' => $certificates]);
    }
    
    /**
     * 切换 HTTPS 状态
     */
    public function postToggleHttps(): string
    {
        $domain = $this->request->getPost('domain');
        $enabled = (bool) $this->request->getPost('enabled');
        
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
        }
        
        $result = $this->sslService->toggleHttps($domain, $enabled);
        
        return $this->fetchJson($result);
    }

    /**
     * 更新证书策略（force_https / force_root_to_www）
     */
    public function postUpdatePolicy(): string
    {
        $certId = (int) $this->request->getPost('cert_id');
        $field = (string) $this->request->getPost('field');
        $value = (int) $this->request->getPost('value');

        if ($certId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的证书 ID')]);
        }

        $allowed = [CertModel::schema_fields_FORCE_HTTPS, CertModel::schema_fields_FORCE_ROOT_TO_WWW];
        if (!\in_array($field, $allowed, true)) {
            return $this->fetchJson(['success' => false, 'message' => __('不支持的策略字段')]);
        }

        try {
            $cert = $this->certModel->clearQuery()->load($certId);
            if (!$cert->getCertId()) {
                return $this->fetchJson(['success' => false, 'message' => __('证书不存在')]);
            }

            $cert->setData($field, $value ? 1 : 0)->save();

            return $this->fetchJson([
                'success' => true,
                'message' => __('策略已更新'),
                'domain' => $cert->getDomain(),
                'field' => $field,
                'value' => $value ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * 申请证书
     */
    public function postRequest(): string
    {
        $domain = $this->request->getPost('domain');
        $email = $this->request->getPost('email');
        $provider = $this->request->getPost('provider', SslCertificateService::PROVIDER_LETS_ENCRYPT);
        $websiteId = (int) $this->request->getPost('website_id', 0);
        
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
        }
        
        if (empty($email)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定联系邮箱')]);
        }
        
        // no-SSL 环境下申请证书前提示：Windows 需先安装 event 扩展才能启动 HTTPS
        $readinessWarning = $this->sslService->getHttpsReadinessWarning();
        if ($readinessWarning !== null) {
            return $this->fetchJson(['success' => false, 'message' => $readinessWarning]);
        }
        
        $webroot = BP . 'pub';
        $result = $this->sslService->requestCertificate($domain, $webroot, $email, $websiteId, (string) $provider);
        
        if ($result['cert']) {
            $result['cert'] = $result['cert']->toSafeArray();
        }
        
        return $this->fetchJson($result);
    }
    
    /**
     * 续签证书
     */
    public function postRenew(): string
    {
        $domain = $this->request->getPost('domain');
        $email = $this->request->getPost('email');
        
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
        }
        
        $cert = $this->certModel->clearQuery()->loadByDomain($domain);
        if (!$cert->getCertId()) {
            return $this->fetchJson(['success' => false, 'message' => __('未找到证书记录')]);
        }
        
        $webroot = BP . 'pub';
        $result = $this->sslService->renewCertificate($cert, $webroot, $email ?: 'admin@' . $domain);
        
        if ($result['cert']) {
            $result['cert'] = $result['cert']->toSafeArray();
        }
        
        return $this->fetchJson($result);
    }
    
    /**
     * 本地/回环域名（禁止删除，用于后台访问）
     */
    private const PROTECTED_DOMAINS = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

    /**
     * 删除证书
     */
    public function postDelete(): string
    {
        $domain = \strtolower(\trim((string) $this->request->getPost('domain', '')));
        
        if ($domain === '') {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
        }

        if (\in_array($domain, self::PROTECTED_DOMAINS, true)) {
            return $this->fetchJson(['success' => false, 'message' => __('本地域名证书（localhost/127.0.0.1）不允许删除，用于后台 HTTPS 访问')]);
        }
        
        $cert = $this->certModel->clearQuery()->loadByDomain($domain);
        if (!$cert->getCertId()) {
            return $this->fetchJson(['success' => false, 'message' => __('未找到证书记录')]);
        }
        
        // 先禁用 HTTPS
        if ($cert->isHttpsEnabled()) {
            $cert->setHttpsEnabled(false)->save();
        }
        
        // 删除证书文件
        $certDir = $cert->getCertificateDir();
        if (\is_dir($certDir)) {
            $files = \glob($certDir . '*');
            foreach ($files as $file) {
                @\unlink($file);
            }
            @\rmdir($certDir);
        }
        
        $certId = $cert->getCertId();

        // 删除数据库记录
        $cert->clearQuery()
            ->where(CertModel::schema_fields_ID, $certId)
            ->delete()
            ->fetch();

        // 通知其他模块清除关联状态（域名池 HTTPS/可建站）
        $this->sslService->dispatchCertificateDeletedEvent($domain, $certId, (string) __('后台手动删除'));

        return $this->fetchJson(['success' => true, 'message' => __('证书已删除')]);
    }
    
    /**
     * 获取证书详情
     */
    public function getDetail(): string
    {
        $domain = $this->request->getGet('domain');
        
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
        }
        
        $cert = $this->certModel->clearQuery()->loadByDomain($domain);
        if (!$cert->getCertId()) {
            return $this->fetchJson(['success' => false, 'message' => __('未找到证书记录')]);
        }
        
        $data = $cert->toSafeArray();
        
        // 解析证书信息
        if ($cert->certificateFilesExist()) {
            $certInfo = $this->sslService->parseCertificate($cert->getCertPath());
            $data['cert_info'] = $certInfo;
        }
        
        return $this->fetchJson(['success' => true, 'data' => $data]);
    }
    
    /**
     * 同步网站域名（通过事件获取域名列表）
     */
    public function postSync(): string
    {
        $email = $this->request->getPost('email', 'admin@localhost');
        
        // 通过事件获取域名列表（解耦模块间依赖）
        $domains = $this->sslService->requestDomainList([
            'has_certificate' => false, // 只获取没有证书的域名
        ]);
        
        $synced = 0;
        
        foreach ($domains as $domainData) {
            $domain = $domainData['domain'] ?? '';
            if (empty($domain)) {
                continue;
            }
            
            // 检查是否已有证书
            $existingCert = $this->certModel->clearQuery()->loadByDomain($domain);
            if (!$existingCert->getCertId()) {
                // 创建证书记录（待申请状态）
                $newCert = clone $this->certModel;
                $newCert->setDomain($domain)
                    ->setWebsiteId((int) ($domainData['website_id'] ?? 0))
                    ->setStatus(CertModel::STATUS_PENDING)
                    ->setAutoRenew(true)
                    ->save();
                $synced++;
            }
        }
        
        return $this->fetchJson([
            'success' => true,
            'message' => __('同步完成，新增 %{1} 个域名', [$synced]),
            'synced' => $synced,
        ]);
    }
    
    /**
     * 获取可选域名列表（通过事件获取）
     * 
     * 用于证书签发时选择域名
     */
    public function getDomains(): string
    {
        $filter = [];
        
        // 解析过滤条件
        if ($this->request->getGet('has_certificate') !== null) {
            $filter['has_certificate'] = (bool) $this->request->getGet('has_certificate');
        }
        if ($this->request->getGet('group_by_root')) {
            $filter['group_by_root'] = true;
        }
        if ($rootDomain = $this->request->getGet('root_domain')) {
            $filter['root_domain'] = $rootDomain;
        }
        
        // 通过事件获取域名列表
        $domains = $this->sslService->requestDomainList($filter);
        
        return $this->fetchJson([
            'success' => true,
            'data' => $domains,
            'count' => \count($domains),
        ]);
    }
    
    /**
     * 为选定域名签发证书
     */
    public function postIssueCertificate(): string
    {
        $domains = $this->request->getPost('domains', []);
        $email = $this->request->getPost('email', '');
        $provider = $this->request->getPost('provider', SslCertificateService::PROVIDER_LETS_ENCRYPT);
        
        if (empty($domains)) {
            return $this->fetchJson(['success' => false, 'message' => __('请选择要签发证书的域名')]);
        }
        
        if (!\is_array($domains)) {
            $domains = [$domains];
        }
        
        $results = [
            'success' => [],
            'failed' => [],
        ];
        
        $webroot = BP . 'pub';
        
        foreach ($domains as $domain) {
            $domain = \trim($domain);
            if (empty($domain)) {
                continue;
            }
            
            $result = $this->sslService->requestCertificate(
                $domain,
                $webroot,
                $email ?: 'admin@' . $domain,
                0,
                (string) $provider
            );
            
            if ($result['success']) {
                $results['success'][] = [
                    'domain' => $domain,
                    'message' => $result['message'] ?? __('证书签发成功'),
                ];
            } else {
                $results['failed'][] = [
                    'domain' => $domain,
                    'message' => $result['message'] ?? __('证书签发失败'),
                ];
            }
        }
        
        $successCount = \count($results['success']);
        $failedCount = \count($results['failed']);
        
        return $this->fetchJson([
            'success' => $failedCount === 0,
            'message' => __('签发完成：成功 %{1} 个，失败 %{2} 个', [$successCount, $failedCount]),
            'results' => $results,
        ]);
    }

    /**
     * 批量删除证书
     */
    public function postBatchDelete(): string
    {
        $domains = $this->request->getPost('domains', []);
        if (!\is_array($domains) || $domains === []) {
            return $this->fetchJson(['success' => false, 'message' => __('请选择要删除的证书')]);
        }

        $deleted = 0;
        $skipped = [];
        foreach ($domains as $domain) {
            $domain = \strtolower(\trim((string) $domain));
            if ($domain === '') {
                continue;
            }
            if (\in_array($domain, self::PROTECTED_DOMAINS, true)) {
                $skipped[] = $domain;
                continue;
            }
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                continue;
            }
            if ($cert->isHttpsEnabled()) {
                $cert->setHttpsEnabled(false)->save();
            }
            $certDir = $cert->getCertificateDir();
            if (\is_dir($certDir)) {
                $files = @\scandir($certDir);
                if ($files !== false) {
                    foreach ($files as $f) {
                        if ($f !== '.' && $f !== '..') {
                            @\unlink($certDir . $f);
                        }
                    }
                }
                @\rmdir($certDir);
            }
            $certId = $cert->getCertId();
            $cert->clearQuery()
                ->where(CertModel::schema_fields_ID, $certId)
                ->delete()
                ->fetch();
            $this->sslService->dispatchCertificateDeletedEvent($domain, $certId, (string) __('后台批量删除'));
            $deleted++;
        }

        $msg = __('已删除 %{1} 个证书', [$deleted]);
        if ($skipped !== []) {
            $msg .= '，' . __('跳过 %{1} 个本地域名（%{2}）', [\count($skipped), \implode(', ', $skipped)]);
        }
        return $this->fetchJson(['success' => true, 'message' => $msg, 'deleted' => $deleted, 'skipped' => $skipped]);
    }

    /**
     * 批量续签证书
     */
    public function postBatchRenew(): string
    {
        $domains = $this->request->getPost('domains', []);
        if (!\is_array($domains) || $domains === []) {
            return $this->fetchJson(['success' => false, 'message' => __('请选择要续签的证书')]);
        }

        $success = 0;
        $failed = 0;
        $errors = [];
        $webroot = \defined('PUB') ? PUB : (BP . 'pub');

        foreach ($domains as $domain) {
            $domain = \strtolower(\trim((string) $domain));
            if ($domain === '') {
                continue;
            }
            $cert = $this->certModel->clearQuery()->loadByDomain($domain);
            if (!$cert->getCertId()) {
                $failed++;
                $errors[] = __('%{1}：证书记录不存在', [$domain]);
                continue;
            }
            $email = 'admin@' . $domain;
            $result = $this->sslService->renewCertificate($cert, $webroot, $email);
            if ($result['success'] ?? false) {
                $success++;
            } else {
                $failed++;
                $errors[] = __('%{1}：%{2}', [$domain, $result['message'] ?? __('续签失败')]);
            }
        }

        $msg = __('续签完成：成功 %{1} 个，失败 %{2} 个', [$success, $failed]);
        return $this->fetchJson(['success' => $failed === 0, 'message' => $msg, 'success_count' => $success, 'failed_count' => $failed, 'errors' => $errors]);
    }

    protected function stripSensitiveCertificateFields(array $certificate): array
    {
        unset(
            $certificate[CertModel::schema_fields_CERT_PEM],
            $certificate[CertModel::schema_fields_KEY_PEM],
            $certificate[CertModel::schema_fields_CHAIN_PEM]
        );
        return $certificate;
    }
}
