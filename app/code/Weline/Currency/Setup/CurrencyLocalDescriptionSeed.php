<?php

declare(strict_types=1);

namespace Weline\Currency\Setup;

use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Framework\Manager\ObjectManager;

class CurrencyLocalDescriptionSeed
{
    private const DEFAULT_LOCAL_NAMES = [
        'CNY' => [
            'zh_Hans_CN' => '人民币',
            'en_US' => 'Chinese Yuan',
        ],
        'USD' => [
            'zh_Hans_CN' => '美元',
            'en_US' => 'US Dollar',
        ],
        'EUR' => [
            'zh_Hans_CN' => '欧元',
            'en_US' => 'Euro',
        ],
        'GBP' => [
            'zh_Hans_CN' => '英镑',
            'en_US' => 'British Pound',
        ],
    ];

    public function seedDefaults(): void
    {
        if (!$this->storageReady()) {
            return;
        }

        foreach (self::DEFAULT_LOCAL_NAMES as $code => $names) {
            /** @var Currency $currency */
            $currency = ObjectManager::getInstance(Currency::class, [], false);
            $currency->clear()
                ->where(Currency::schema_fields_CODE, $code)
                ->find()
                ->fetch();

            if (!$currency->getId()) {
                continue;
            }

            foreach ($names as $localeCode => $name) {
                $this->saveLocalName($currency, $localeCode, $name);
            }
        }

        w_cache('currency')->clear();
    }

    private function saveLocalName(Currency $currency, string $localeCode, string $name): void
    {
        /** @var LocalDescription $localDescription */
        $localDescription = ObjectManager::getInstance(LocalDescription::class, [], false);
        $localDescription->clear()
            ->setData([
                LocalDescription::schema_fields_ID => (int)$currency->getId(),
                LocalDescription::schema_fields_local_code => $localeCode,
                LocalDescription::schema_fields_name => $name,
            ])
            ->forceCheck(true, [
                LocalDescription::schema_fields_ID,
                LocalDescription::schema_fields_local_code,
            ])
            ->save();
    }

    private function storageReady(): bool
    {
        try {
            /** @var Currency $currency */
            $currency = ObjectManager::getInstance(Currency::class, [], false);
            /** @var LocalDescription $localDescription */
            $localDescription = ObjectManager::getInstance(LocalDescription::class, [], false);
            $connector = $currency->getConnection()->getConnector();

            return $connector->tableExist($currency->getTable())
                && $connector->tableExist($localDescription->getTable());
        } catch (\Throwable) {
            return false;
        }
    }
}
