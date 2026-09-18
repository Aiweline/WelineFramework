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
        'CAD' => [
            'zh_Hans_CN' => '加拿大元',
            'en_US' => 'Canadian Dollar',
        ],
        'CHF' => [
            'zh_Hans_CN' => '瑞士法郎',
            'en_US' => 'Swiss Franc',
        ],
        'SEK' => [
            'zh_Hans_CN' => '瑞典克朗',
            'en_US' => 'Swedish Krona',
        ],
        'NOK' => [
            'zh_Hans_CN' => '挪威克朗',
            'en_US' => 'Norwegian Krone',
        ],
        'DKK' => [
            'zh_Hans_CN' => '丹麦克朗',
            'en_US' => 'Danish Krone',
        ],
        'PLN' => [
            'zh_Hans_CN' => '波兰兹罗提',
            'en_US' => 'Polish Zloty',
        ],
        'MXN' => [
            'zh_Hans_CN' => '墨西哥比索',
            'en_US' => 'Mexican Peso',
        ],
    ];

    /** @var array<string, array{name:string,symbol:string,rate:float}> */
    private const CATALOG_DEFAULTS = [
        'CNY' => ['name' => '人民币', 'symbol' => '￥', 'rate' => 1.0],
        'USD' => ['name' => '美元', 'symbol' => '$', 'rate' => 8.0],
        // Relative to CNY base: 1 foreign = rate CNY (manual mode placeholders; live rates via exchangerate-api).
        'EUR' => ['name' => '欧元', 'symbol' => '€', 'rate' => 7.8],
        'GBP' => ['name' => '英镑', 'symbol' => '£', 'rate' => 9.0],
        'CAD' => ['name' => '加拿大元', 'symbol' => 'C$', 'rate' => 0.0],
        'CHF' => ['name' => '瑞士法郎', 'symbol' => 'CHF', 'rate' => 0.0],
        'SEK' => ['name' => '瑞典克朗', 'symbol' => 'kr', 'rate' => 0.0],
        'NOK' => ['name' => '挪威克朗', 'symbol' => 'kr', 'rate' => 0.0],
        'DKK' => ['name' => '丹麦克朗', 'symbol' => 'kr', 'rate' => 0.0],
        'PLN' => ['name' => '波兰兹罗提', 'symbol' => 'zł', 'rate' => 0.0],
        'MXN' => ['name' => '墨西哥比索', 'symbol' => 'MX$', 'rate' => 0.0],
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
                $meta = self::CATALOG_DEFAULTS[$code] ?? ['name' => $code, 'symbol' => '', 'rate' => 0.0];
                $currency->clear()
                    ->setCode($code)
                    ->setName((string)$meta['name'])
                    ->setRate((float)$meta['rate'])
                    ->setSymbol((string)$meta['symbol'])
                    ->setPosition('left')
                    ->setFormat('1,0')
                    ->setStatus(true)
                    ->setIcon((string)$meta['symbol'])
                    ->setThousandSeparator(',')
                    ->setDecimalSeparator('.')
                    ->setBaseCurrency('CNY')
                    ->save();
                $currency->clear()
                    ->where(Currency::schema_fields_CODE, $code)
                    ->find()
                    ->fetch();
                if (!$currency->getId()) {
                    continue;
                }
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
