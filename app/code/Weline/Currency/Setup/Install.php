<?php

declare(strict_types=1);

namespace Weline\Currency\Setup;

use Weline\Currency\Model\Currency;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $currency = ObjectManager::getInstance(Currency::class);
        $cny = clone $currency;
        $cny->clear()->where(Currency::schema_fields_CODE, 'CNY')->find()->fetch();
        if (!$cny->getId()) {
            $currency->clear()
                ->setCode('CNY')
                ->setName('人民币')
                ->setRate(1)
                ->setSymbol('￥')
                ->setPosition('left')
                ->setFormat('1,0')
                ->setStatus(true)
                ->setIcon('￥')
                ->setThousandSeparator(',')
                ->setDecimalSeparator('.')
                ->setBaseCurrency('CNY')
                ->save();
        } else {
            $cny->setBaseCurrency('CNY')->setRate(1)->setStatus(true)->save();
        }
        $usd = clone $currency;
        $usd->clear()->where(Currency::schema_fields_CODE, 'USD')->find()->fetch();
        if (!$usd->getId()) {
            $currency->clear()
                ->setCode('USD')
                ->setName('美刀')
                ->setRate(8)
                ->setSymbol('$')
                ->setPosition('left')
                ->setFormat('1,0')
                ->setStatus(true)
                ->setIcon('$')
                ->setThousandSeparator(',')
                ->setDecimalSeparator('.')
                ->setBaseCurrency('CNY')
                ->save();
        }
        try {
            $config = ObjectManager::getInstance(\Weline\Currency\Model\Config::class);
            if ($config->getBaseCurrency() !== 'CNY') {
                $config->setBaseCurrency('CNY');
            }
        } catch (\Throwable $e) {
        }

        ObjectManager::getInstance(CurrencyLocalDescriptionSeed::class)->seedDefaults();
    }
}
