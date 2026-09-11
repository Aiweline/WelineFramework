<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Api\DropshipChannelManagerInterface;
use Weline\Dropship\Interface\DropshipProviderInterface;
use Weline\Dropship\Model\DropshipChannel;
use Weline\Framework\Manager\ObjectManager;

class DropshipChannelManager implements DropshipChannelManagerInterface
{
    /** @var array<string, DropshipProviderInterface>|null */
    private ?array $providersByCode = null;

    public function __construct(
        private readonly DropshipProviderScanner $scanner,
    ) {
    }

    public function registerAllProviders(bool $forceReload = false): array
    {
        $rows = [];
        /** @var DropshipChannel $model */
        $model = ObjectManager::getInstance(DropshipChannel::class);

        foreach ($this->scanner->instantiateProviders($forceReload) as $provider) {
            $code = $provider->getCode();
            $meta = $provider->getDisplayMetadata();
            $caps = $provider->getCapabilities();
            $existing = $model->clear()->where(DropshipChannel::schema_fields_CODE, $code)->find()->fetch();
            $data = [
                DropshipChannel::schema_fields_CODE => $code,
                DropshipChannel::schema_fields_NAME => (string)($meta['title'] ?? $code),
                DropshipChannel::schema_fields_PROVIDER_CLASS => $provider::class,
                DropshipChannel::schema_fields_PROVIDER_MODULE => (string)($meta['module'] ?? ''),
                DropshipChannel::schema_fields_SORT_ORDER => (int)($meta['sort_order'] ?? 100),
                DropshipChannel::schema_fields_CAPABILITIES_JSON => json_encode($caps, JSON_UNESCAPED_UNICODE),
                DropshipChannel::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
            ];
            if ($existing && $existing->getId()) {
                $existing->setData($data)->save();
            } else {
                $data[DropshipChannel::schema_fields_ENABLED] = 1;
                $data[DropshipChannel::schema_fields_CREATED_AT] = date('Y-m-d H:i:s');
                $model->clear()->setData($data)->save();
            }
            $rows[] = ['code' => $code, 'name' => $data[DropshipChannel::schema_fields_NAME]];
        }

        $this->providersByCode = null;

        return $rows;
    }

    public function getProviders(bool $forceReload = false): array
    {
        return array_values($this->mapProviders($forceReload));
    }

    public function getProvider(string $code): ?DropshipProviderInterface
    {
        $map = $this->mapProviders(false);

        return $map[$code] ?? null;
    }

    /**
     * @return array<string, DropshipProviderInterface>
     */
    private function mapProviders(bool $forceReload): array
    {
        if ($forceReload || $this->providersByCode === null) {
            $map = [];
            foreach ($this->scanner->instantiateProviders($forceReload) as $provider) {
                $map[$provider->getCode()] = $provider;
            }
            $this->providersByCode = $map;
        }

        return $this->providersByCode;
    }
}
