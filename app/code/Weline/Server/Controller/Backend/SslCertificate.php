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
        // 获取所有证书
        $certificates = $this->certModel->clearQuery()
            ->order(CertModel::fields_DOMAIN)
            ->select()
            ->fetchArray();
        
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
            if ($cert[CertModel::fields_STATUS] === CertModel::STATUS_ACTIVE) {
                $stats['active']++;
            }
            if ($cert[CertModel::fields_HTTPS_ENABLED]) {
                $stats['https_enabled']++;
            }
            
            $expiresAt = $cert[CertModel::fields_EXPIRES_AT] ?? '';
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
        
        return $this->fetch();
    }
    
    /**
     * 获取证书列表（AJAX）
     */
    public function getList(): string
    {
        $certificates = $this->certModel->clearQuery()
            ->order(CertModel::fields_DOMAIN)
            ->select()
            ->fetchArray();
        
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
     * 申请证书
     */
    public function postRequest(): string
    {
        $domain = $this->request->getPost('domain');
        $email = $this->request->getPost('email');
        $websiteId = (int) $this->request->getPost('website_id', 0);
        
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
        }
        
        if (empty($email)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定联系邮箱')]);
        }
        
        $webroot = BP . 'pub';
        $result = $this->sslService->requestCertificate($domain, $webroot, $email, $websiteId);
        
        if ($result['cert']) {
            $result['cert'] = $result['cert']->getData();
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
            $result['cert'] = $result['cert']->getData();
        }
        
        return $this->fetchJson($result);
    }
    
    /**
     * 删除证书
     */
    public function postDelete(): string
    {
        $domain = $this->request->getPost('domain');
        
        if (empty($domain)) {
            return $this->fetchJson(['success' => false, 'message' => __('请指定域名')]);
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
        
        // 删除数据库记录
        $cert->clearQuery()
            ->where(CertModel::fields_ID, $cert->getCertId())
            ->delete()
            ->fetch();
        
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
        
        $data = $cert->getData();
        
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
                0
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
}
