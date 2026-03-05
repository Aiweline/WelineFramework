<?php

declare(strict_types=1);

/*
 * Multipass 站点后台控制器
 * 管理站点的增删改查
 */

namespace Weline\Multipass\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\MultipassSite;

class Site extends BackendController
{
    private MultipassSite $siteModel;
    
    public function __construct()
    {
        $this->siteModel = ObjectManager::getInstance(MultipassSite::class);
    }
    
    /**
     * 站点列表页
     */
    public function index()
    {
        $sites = $this->siteModel->clear()
            ->order('site_id', 'DESC')
            ->select()
            ->fetch()
            ->getItems();
        
        $this->assign([
            'sites' => $sites,
            'page_title' => __('站点管理'),
        ]);
        
        return $this->fetch();
    }
    
    /**
     * 快速保存站点（AJAX）
     * 用于 offcanvas 快速创建站点
     */
    public function quickSave()
    {
        try {
            // 获取参数
            $siteName = trim($this->request->getParam('site_name', ''));
            $siteUrl = trim($this->request->getParam('site_url', ''));
            $secretKey = trim($this->request->getParam('secret_key', ''));
            $userType = $this->request->getParam('user_type', 'frontend');
            $isEnabled = $this->request->getParam('is_enabled') ? 1 : 0;
            
            // 验证必填字段
            if (empty($siteName)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('站点名称不能为空'),
                ]);
            }
            
            if (empty($siteUrl)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('站点URL不能为空'),
                ]);
            }
            
            // 验证URL格式
            if (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('站点URL格式不正确'),
                ]);
            }
            
            // 检查站点URL是否已存在
            $existingSite = clone $this->siteModel;
            $existingSite->clear()
                ->where(MultipassSite::schema_fields_SITE_URL, $siteUrl)
                ->find()
                ->fetch();
            
            if ($existingSite->getId()) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('该站点URL已存在'),
                ]);
            }
            
            // 如果没有提供密钥，自动生成
            if (empty($secretKey)) {
                $secretKey = $this->generateSecretKey();
            }
            
            // 创建站点
            $site = clone $this->siteModel;
            $site->setData([
                MultipassSite::schema_fields_SITE_NAME => $siteName,
                MultipassSite::schema_fields_SITE_URL => rtrim($siteUrl, '/'),
                MultipassSite::schema_fields_SECRET_KEY => $secretKey,
                MultipassSite::schema_fields_USER_TYPE => $userType,
                MultipassSite::schema_fields_IS_ENABLED => $isEnabled,
            ])->save(true);
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('站点创建成功'),
                'site' => [
                    'site_id' => $site->getId(),
                    'site_name' => $site->getSiteName(),
                    'site_url' => $site->getSiteUrl(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存失败: ') . $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 保存站点
     */
    public function save()
    {
        try {
            $siteId = (int)$this->request->getParam('site_id');
            $siteName = trim($this->request->getParam('site_name', ''));
            $siteUrl = trim($this->request->getParam('site_url', ''));
            $secretKey = trim($this->request->getParam('secret_key', ''));
            $userType = $this->request->getParam('user_type', 'frontend');
            $isEnabled = $this->request->getParam('is_enabled') ? 1 : 0;
            
            // 验证
            if (empty($siteName) || empty($siteUrl)) {
                $this->getMessageManager()->addError(__('站点名称和URL不能为空'));
                return $this->redirect($this->getBackendUrl('*/backend/site/index'));
            }
            
            // 获取或创建站点
            $site = clone $this->siteModel;
            if ($siteId) {
                $site->load($siteId);
                if (!$site->getId()) {
                    $this->getMessageManager()->addError(__('站点不存在'));
                    return $this->redirect($this->getBackendUrl('*/backend/site/index'));
                }
            }
            
            // 自动生成密钥
            if (empty($secretKey)) {
                $secretKey = $this->generateSecretKey();
            }
            
            // 保存
            $site->setData([
                MultipassSite::schema_fields_SITE_NAME => $siteName,
                MultipassSite::schema_fields_SITE_URL => rtrim($siteUrl, '/'),
                MultipassSite::schema_fields_SECRET_KEY => $secretKey,
                MultipassSite::schema_fields_USER_TYPE => $userType,
                MultipassSite::schema_fields_IS_ENABLED => $isEnabled,
            ])->save(!$siteId);
            
            $this->getMessageManager()->addSuccess(__('站点保存成功'));
            return $this->redirect($this->getBackendUrl('*/backend/site/index'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('保存失败: ') . $e->getMessage());
            return $this->redirect($this->getBackendUrl('*/backend/site/index'));
        }
    }
    
    /**
     * 删除站点
     */
    public function delete()
    {
        try {
            $siteId = (int)$this->request->getParam('site_id');
            
            if (!$siteId) {
                $this->getMessageManager()->addError(__('站点ID不能为空'));
                return $this->redirect($this->getBackendUrl('*/backend/site/index'));
            }
            
            $site = clone $this->siteModel;
            $site->load($siteId);
            
            if (!$site->getId()) {
                $this->getMessageManager()->addError(__('站点不存在'));
                return $this->redirect($this->getBackendUrl('*/backend/site/index'));
            }
            
            $site->delete();
            
            $this->getMessageManager()->addSuccess(__('站点删除成功'));
            return $this->redirect($this->getBackendUrl('*/backend/site/index'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('删除失败: ') . $e->getMessage());
            return $this->redirect($this->getBackendUrl('*/backend/site/index'));
        }
    }
    
    /**
     * 获取所有启用的站点（AJAX）
     */
    public function getList()
    {
        try {
            $sites = $this->siteModel->clear()
                ->where(MultipassSite::schema_fields_IS_ENABLED, 1)
                ->order(MultipassSite::schema_fields_SITE_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            $list = [];
            foreach ($sites as $site) {
                $list[] = [
                    'site_id' => $site->getId(),
                    'site_name' => $site->getSiteName(),
                    'site_url' => $site->getSiteUrl(),
                ];
            }
            
            return $this->fetchJson([
                'success' => true,
                'sites' => $list,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * 生成随机密钥
     */
    private function generateSecretKey(int $length = 32): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $key;
    }
}
