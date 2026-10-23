<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Setup;

use Weline\AiAcquisition\Model\BridgeEvent;
use Weline\AiAcquisition\Model\Continent;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadRun;
use Weline\AiAcquisition\Model\PromptProfile;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

/**
 * 表结构由 Model #[Col]/#[Index] 声明，RSSE SchemaManifest/planner 负责建表；
 * 本安装脚本只保留 AiAcquisition 的种子数据。
 */
class Install implements InstallInterface
{
    private const MODELS = [
        Continent::class,
        Lead::class,
        PromptProfile::class,
        LeadRun::class,
        BridgeEvent::class,
    ];

    private const CONTINENTS = [
        ['AS', '亚洲', 'Asia', 10],
        ['EU', '欧洲', 'Europe', 20],
        ['AF', '非洲', 'Africa', 30],
        ['NA', '北美洲', 'North America', 40],
        ['SA', '南美洲', 'South America', 50],
        ['OC', '大洋洲', 'Oceania', 60],
        ['AN', '南极洲', 'Antarctica', 70],
        ['UNKNOWN', '未知', 'Unknown', 99],
    ];

    public function setup(Setup $setup, Context $context): void
    {
        $this->seedContinents();
    }

    private function seedContinents(): void
    {
        /** @var Continent $probe */
        $probe = ObjectManager::getInstance(Continent::class);

        foreach (self::CONTINENTS as [$code, $nameZh, $nameEn, $sort]) {
            $existing = clone $probe;
            $row = $existing->clearData()->reset()
                ->where(Continent::schema_fields_CODE, $code)
                ->find()
                ->fetchArray();
            if (is_array($row) && $row !== []) {
                continue;
            }

            /** @var Continent $continent */
            $continent = clone $probe;
            $continent->clearData()->reset()->setData([
                Continent::schema_fields_CODE => $code,
                Continent::schema_fields_NAME_ZH => $nameZh,
                Continent::schema_fields_NAME_EN => $nameEn,
                Continent::schema_fields_SORT => $sort,
            ])->save();
        }
    }
}
