<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\BrandBasicsIdentityProviderInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

/**
 * 把 Website / Store / SalesChannel 真实名称（及网站简介）挂到主题编辑器「基础信息」。
 */
final class ThemeBrandBasicsIdentityProvider implements BrandBasicsIdentityProviderInterface
{
    private const NAME_MAX = 128;
    private const DESCRIPTION_MAX = 500;

    public function __construct(
        private readonly Website $website,
        private readonly Store $store,
        private readonly SalesChannel $channel,
        private readonly BackendConfigStore $backendConfig,
    ) {
    }

    public function getCode(): string
    {
        return 'websites_scope_identity';
    }

    public function getModule(): string
    {
        return 'Weline_Websites';
    }

    public function supports(ScopeIdentity $identity): bool
    {
        return \in_array($identity->scopeKind, [
            ScopeIdentity::KIND_WEBSITE,
            ScopeIdentity::KIND_STORE,
            ScopeIdentity::KIND_CHANNEL,
        ], true);
    }

    public function load(ScopeIdentity $identity): array
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_WEBSITE => $this->loadWebsite($identity),
            ScopeIdentity::KIND_STORE => $this->loadStore($identity),
            ScopeIdentity::KIND_CHANNEL => $this->loadChannel($identity),
            default => throw new \InvalidArgumentException('websites_brand_basics_scope_unsupported'),
        };
    }

    public function save(ScopeIdentity $identity, array $values): array
    {
        return match ($identity->scopeKind) {
            ScopeIdentity::KIND_WEBSITE => $this->saveWebsite($identity, $values),
            ScopeIdentity::KIND_STORE => $this->saveStore($identity, $values),
            ScopeIdentity::KIND_CHANNEL => $this->saveChannel($identity, $values),
            default => throw new \InvalidArgumentException('websites_brand_basics_scope_unsupported'),
        };
    }

    /** @return array<string,mixed> */
    private function loadWebsite(ScopeIdentity $identity): array
    {
        $website = $this->requireWebsite($identity);
        $description = \trim((string)($this->backendConfig->getConfig('site_description', 'Weline_Backend') ?? ''));

        return [
            'label' => (string)__('网站身份'),
            'scope_kind' => ScopeIdentity::KIND_WEBSITE,
            'fields' => [
                [
                    'key' => 'name',
                    'type' => 'text',
                    'label' => (string)__('网站名称'),
                    'required' => true,
                    'max' => self::NAME_MAX,
                ],
                [
                    'key' => 'description',
                    'type' => 'textarea',
                    'label' => (string)__('网站简介'),
                    'required' => false,
                    'max' => self::DESCRIPTION_MAX,
                    'rows' => 3,
                ],
            ],
            'values' => [
                'name' => \trim((string)$website->getName()),
                'description' => $description,
            ],
        ];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    private function saveWebsite(ScopeIdentity $identity, array $values): array
    {
        $website = $this->requireWebsite($identity);
        $name = $this->requireName($values['name'] ?? '');
        $description = $this->normalizeDescription($values['description'] ?? '');

        $website->setName($name);
        if (!$website->save()) {
            throw new \RuntimeException((string)__('网站名称保存失败'));
        }

        if (!$this->backendConfig->setConfig('site_name', $name, 'Weline_Backend')) {
            throw new \RuntimeException((string)__('网站名称同步到后台配置失败'));
        }
        if (!$this->backendConfig->setConfig('site_description', $description, 'Weline_Backend')) {
            throw new \RuntimeException((string)__('网站简介保存失败'));
        }

        return $this->loadWebsite($identity);
    }

    /** @return array<string,mixed> */
    private function loadStore(ScopeIdentity $identity): array
    {
        $store = $this->requireStore($identity);

        return [
            'label' => (string)__('店铺身份'),
            'scope_kind' => ScopeIdentity::KIND_STORE,
            'fields' => [
                [
                    'key' => 'name',
                    'type' => 'text',
                    'label' => (string)__('店铺名称'),
                    'required' => true,
                    'max' => self::NAME_MAX,
                ],
            ],
            'values' => [
                'name' => \trim((string)$store->getName()),
            ],
        ];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    private function saveStore(ScopeIdentity $identity, array $values): array
    {
        $store = $this->requireStore($identity);
        $name = $this->requireName($values['name'] ?? '');
        $store->setName($name);
        if (!$store->save()) {
            throw new \RuntimeException((string)__('店铺名称保存失败'));
        }

        return $this->loadStore($identity);
    }

    /** @return array<string,mixed> */
    private function loadChannel(ScopeIdentity $identity): array
    {
        $channel = $this->requireChannel($identity);

        return [
            'label' => (string)__('渠道身份'),
            'scope_kind' => ScopeIdentity::KIND_CHANNEL,
            'fields' => [
                [
                    'key' => 'name',
                    'type' => 'text',
                    'label' => (string)__('渠道名称'),
                    'required' => true,
                    'max' => self::NAME_MAX,
                ],
            ],
            'values' => [
                'name' => \trim((string)$channel->getName()),
            ],
        ];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    private function saveChannel(ScopeIdentity $identity, array $values): array
    {
        $channel = $this->requireChannel($identity);
        $name = $this->requireName($values['name'] ?? '');
        $channel->setName($name);
        if (!$channel->save()) {
            throw new \RuntimeException((string)__('渠道名称保存失败'));
        }

        return $this->loadChannel($identity);
    }

    private function requireWebsite(ScopeIdentity $identity): Website
    {
        $websiteId = $identity->websiteId;
        $code = \trim((string)($identity->websiteCode ?? ''));
        $row = clone $this->website;
        $row->clearData()->clearQuery();

        if ($websiteId !== null) {
            $row->where(Website::schema_fields_ID, (int)$websiteId)->find()->fetch();
            if ($row->hasData(Website::schema_fields_CODE)) {
                return $row;
            }
        }

        if ($code !== '') {
            $row->clearData()->clearQuery()
                ->where(Website::schema_fields_CODE, $code)
                ->find()
                ->fetch();
            if ($row->hasData(Website::schema_fields_CODE)) {
                return $row;
            }
        }

        throw new \InvalidArgumentException('websites_brand_basics_website_not_found');
    }

    private function requireStore(ScopeIdentity $identity): Store
    {
        $websiteId = (int)($identity->websiteId ?? 0);
        $storeCode = \trim((string)($identity->storeCode ?? ''));
        if ($storeCode === '') {
            throw new \InvalidArgumentException('websites_brand_basics_store_code_required');
        }
        $row = clone $this->store;
        $row->clearData()->clearQuery()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Store::schema_fields_CODE, $storeCode)
            ->find()
            ->fetch();
        if (!(int)$row->getId()) {
            throw new \InvalidArgumentException('websites_brand_basics_store_not_found');
        }

        return $row;
    }

    private function requireChannel(ScopeIdentity $identity): SalesChannel
    {
        $websiteId = (int)($identity->websiteId ?? 0);
        $storeCode = \trim((string)($identity->storeCode ?? ''));
        $channelCode = \trim((string)($identity->channelCode ?? ''));
        if ($storeCode === '' || $channelCode === '') {
            throw new \InvalidArgumentException('websites_brand_basics_channel_code_required');
        }
        $store = $this->requireStore($identity);
        $row = clone $this->channel;
        $row->clearData()->clearQuery()
            ->where(SalesChannel::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SalesChannel::schema_fields_STORE_ID, (int)$store->getId())
            ->where(SalesChannel::schema_fields_CODE, $channelCode)
            ->find()
            ->fetch();
        if (!(int)$row->getId()) {
            throw new \InvalidArgumentException('websites_brand_basics_channel_not_found');
        }

        return $row;
    }

    private function requireName(string $name): string
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException((string)__('名称不能为空'));
        }
        if (\mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw new \InvalidArgumentException((string)__('名称不能超过 %{1} 个字符', [self::NAME_MAX]));
        }

        return $name;
    }

    private function normalizeDescription(string $description): string
    {
        $description = \trim($description);
        if (\mb_strlen($description, 'UTF-8') > self::DESCRIPTION_MAX) {
            throw new \InvalidArgumentException((string)__('简介不能超过 %{1} 个字符', [self::DESCRIPTION_MAX]));
        }

        return $description;
    }
}
