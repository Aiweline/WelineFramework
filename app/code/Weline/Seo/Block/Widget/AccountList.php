<?php

declare(strict_types=1);

/*
 * SEO 账户列表 Widget Block
 * 
 * 提供可复用的 SEO 账户列表展示组件
 * 支持按 scope 过滤，可在其他模块的配置页面中引用
 */

namespace Weline\Seo\Block\Widget;

use Weline\Framework\View\Block;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Url;
use Weline\Seo\Model\SeoAccount;

/**
 * SEO 账户列表 Widget
 * 
 * 使用方式：
     * <w:block class='Weline\Seo\Block\Widget\AccountList'
     *          readonly='1' />
 */
class AccountList extends Block
{
    protected string $_template = 'Weline_Seo::Widget/AccountList.phtml';
    
    private SeoAccount $accountModel;
    private Url $url;
    
    public function __init(): void
    {
        parent::__init();
        
        $this->accountModel = ObjectManager::getInstance(SeoAccount::class);
        $this->url = ObjectManager::getInstance(Url::class);
        
        // 从属性中解析参数
        $scope = (string)($this->getData('scope') ?? '');
        $readonly = (bool)($this->getData('readonly') ?? true);
        $title = (string)($this->getData('title') ?? __('SEO账户配置'));
        $showManageLink = (bool)($this->getData('show_manage_link') ?? true);
        
        // 获取账户列表
        $accounts = $this->getAccounts($scope);
        
        // 构建 SEO 管理页面链接
        $manageUrl = $this->url->getBackendUrl('seo/backend/account/index', [
            'scope' => $scope,
        ]);
        
        // 生成唯一ID
        $widgetId = 'seo_account_widget_' . md5($scope . '_' . uniqid());
        
        $this->assign([
            'widget_id' => $widgetId,
            'scope' => $scope,
            'readonly' => $readonly,
            'title' => $title,
            'accounts' => $accounts,
            'manage_url' => $manageUrl,
            'show_manage_link' => $showManageLink,
            'has_accounts' => !empty($accounts),
        ]);
    }
    
    /**
     * 获取账户列表
     * 
     * @param string $scope 业务 scope
     * @return array 账户列表
     */
    private function getAccounts(string $scope): array
    {
        $query = $this->accountModel->reset()->select();
        
        // 按 scope 过滤
        if ($scope !== '') {
            $query->where(SeoAccount::schema_fields_SCOPE, $scope);
        }
        
        $query->order(SeoAccount::schema_fields_CREATED_AT, 'DESC');
        
        return $query->fetchArray();
    }
}
