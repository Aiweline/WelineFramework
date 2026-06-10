<?php

declare(strict_types=1);

namespace Weline\Seo\Block\Widget;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Block;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Service\SitemapAdapterRegistry;
use Weline\Websites\Model\Website;

class WebsiteAccountEnablement extends Block
{
    protected string $_template = 'Weline_Seo::Widget/WebsiteAccountEnablement.phtml';

    public function __init(): void
    {
        parent::__init();

        $website = (array)($this->getData('website') ?? []);
        $websiteId = (int)($this->getData('website_id') ?? $website['website_id'] ?? $website['id'] ?? 0);
        $scope = trim((string)($this->getData('scope') ?? ''));

        if ($websiteId > 0 && $website === []) {
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            $websiteModel->reset()->load($websiteId);
            if ($websiteModel->getId()) {
                $website = $websiteModel->getData();
            }
        }

        if ($scope === '' && isset($website[Website::schema_fields_SCOPE])) {
            $scope = trim((string)$website[Website::schema_fields_SCOPE]);
        }

        /** @var SeoAccount $accountModel */
        $accountModel = ObjectManager::getInstance(SeoAccount::class);
        $accounts = $this->loadAccounts($accountModel, $scope);

        /** @var SeoWebsiteAccount $websiteAccountModel */
        $websiteAccountModel = ObjectManager::getInstance(SeoWebsiteAccount::class);
        $bindings = $websiteId > 0 ? $websiteAccountModel->reset()->getByWebsiteId($websiteId) : [];
        $bindingsMap = [];
        foreach ($bindings as $binding) {
            $accountId = (int)($binding[SeoWebsiteAccount::schema_fields_ACCOUNT_ID] ?? 0);
            if ($accountId > 0) {
                $bindingsMap[$accountId] = $binding;
            }
        }

        /** @var SitemapAdapterRegistry $registry */
        $registry = ObjectManager::getInstance(SitemapAdapterRegistry::class);

        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);

        $mode = (string)($this->getData('mode') ?? 'embedded');
        $fieldPrefix = trim((string)($this->getData('field_prefix') ?? 'extensions[seo]'));
        if ($mode === 'standalone' && !$this->hasData('field_prefix')) {
            $fieldPrefix = '';
        }

        $this->assign([
            'widget_id' => (string)($this->getData('widget_id') ?? ('seo_website_accounts_' . md5((string)$websiteId . '_' . uniqid('', true)))),
            'website_id' => $websiteId,
            'website' => $website,
            'scope' => $scope,
            'accounts' => $accounts,
            'bindingsMap' => $bindingsMap,
            'platforms' => $registry->getPlatformInfo(),
            'mode' => $mode,
            'field_prefix' => $fieldPrefix,
            'show_submit' => (bool)($this->getData('show_submit') ?? ($mode === 'standalone')),
            'action_url' => (string)($this->getData('action_url') ?? $url->getBackendUrl('seo/backend/websiteaccount/save')),
            'manage_url' => $url->getBackendUrl('seo/backend/account/index', ['scope' => $scope]),
        ]);
    }

    private function loadAccounts(SeoAccount $accountModel, string $scope): array
    {
        if ($scope === '') {
            return $accountModel->reset()
                ->select()
                ->where(SeoAccount::schema_fields_IS_ACTIVE, SeoAccount::STATUS_ACTIVE)
                ->order(SeoAccount::schema_fields_CREATED_AT, 'DESC')
                ->fetchArray();
        }

        $scoped = $accountModel->reset()
            ->select()
            ->where(SeoAccount::schema_fields_SCOPE, $scope)
            ->where(SeoAccount::schema_fields_IS_ACTIVE, SeoAccount::STATUS_ACTIVE)
            ->fetchArray();

        $global = $accountModel->reset()
            ->select()
            ->where(SeoAccount::schema_fields_SCOPE, '')
            ->where(SeoAccount::schema_fields_IS_ACTIVE, SeoAccount::STATUS_ACTIVE)
            ->fetchArray();

        $map = [];
        foreach (array_merge($scoped, $global) as $account) {
            $accountId = (int)($account[SeoAccount::schema_fields_ACCOUNT_ID] ?? 0);
            if ($accountId > 0) {
                $map[$accountId] = $account;
            }
        }

        $accounts = array_values($map);
        usort($accounts, static function (array $left, array $right): int {
            return strtotime((string)($right[SeoAccount::schema_fields_CREATED_AT] ?? '')) <=> strtotime((string)($left[SeoAccount::schema_fields_CREATED_AT] ?? ''));
        });

        return $accounts;
    }
}
