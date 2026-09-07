<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\Region\LocalDescription as RegionLocalDescription;
use Weline\Shipping\Model\Street;
use Weline\Shipping\Model\Street\LocalDescription as StreetLocalDescription;

/**
 * 触发 Region/Street/PostalPlace LocalModel 翻译扫描。
 * 源文优先取自主表同名字段（Local 可为空）；目标语言由 LocalModelTranslation 补齐。
 */
final class RegionLocalSeedService
{
    public const FALLBACK_SOURCE_LOCALE = 'zh_Hans_CN';

    private const PAGE_SIZE = 500;

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @return array{translation_queue_id:int,source_locale:string,region_source:int,street_source:int,note:string}
     */
    public function seedAll(): array
    {
        $sourceLocale = $this->resolveSourceLocale();

        // Do not require writing source-locale Local rows: LocalModelTranslation
        // uses parent/main table names when Local is empty.
        return [
            'source_locale' => $sourceLocale,
            'region_source' => 0,
            'street_source' => 0,
            'translation_queue_id' => $this->enqueueLocalModelTranslation(),
            'note' => 'source_from_parent_when_local_empty',
        ];
    }

    public function resolveSourceLocale(): string
    {
        try {
            /** @var AiTranslationConfig $config */
            $config = $this->objectManager->getInstance(AiTranslationConfig::class);
            $locale = trim((string)$config->getSourceLocale());
            if ($locale !== '') {
                return $locale;
            }
        } catch (\Throwable) {
            // fall through
        }

        return self::FALLBACK_SOURCE_LOCALE;
    }

    public function seedRegionDefaults(?string $locale = null): int
    {
        $locale = $locale !== null && trim($locale) !== '' ? trim($locale) : $this->resolveSourceLocale();
        /** @var Region $region */
        $region = $this->objectManager->getInstance(Region::class);
        $count = 0;
        $offset = 0;
        while (true) {
            $items = $region->reset()
                ->order(Region::schema_fields_ID, 'ASC')
                ->limit(self::PAGE_SIZE, $offset)
                ->select()
                ->fetch()
                ->getItems();
            if ($items === []) {
                break;
            }
            $rows = [];
            foreach ($items as $item) {
                if (!$item instanceof Region || !(int)$item->getId()) {
                    continue;
                }
                $name = trim((string)$item->getData(Region::schema_fields_REGION_NAME));
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    RegionLocalDescription::schema_fields_ID => (int)$item->getId(),
                    RegionLocalDescription::schema_fields_local_code => $locale,
                    RegionLocalDescription::schema_fields_REGION_NAME => $name,
                ];
            }
            $count += $this->upsertRegionLocals($rows);
            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return $count;
    }

    public function seedStreetDefaults(?string $locale = null): int
    {
        $locale = $locale !== null && trim($locale) !== '' ? trim($locale) : $this->resolveSourceLocale();
        /** @var Street $street */
        $street = $this->objectManager->getInstance(Street::class);
        $count = 0;
        $offset = 0;
        while (true) {
            $items = $street->reset()
                ->order(Street::schema_fields_ID, 'ASC')
                ->limit(self::PAGE_SIZE, $offset)
                ->select()
                ->fetch()
                ->getItems();
            if ($items === []) {
                break;
            }
            $rows = [];
            foreach ($items as $item) {
                if (!$item instanceof Street || !(int)$item->getId()) {
                    continue;
                }
                $name = trim((string)$item->getData(Street::schema_fields_STREET_NAME));
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    StreetLocalDescription::schema_fields_ID => (int)$item->getId(),
                    StreetLocalDescription::schema_fields_local_code => $locale,
                    StreetLocalDescription::schema_fields_STREET_NAME => $name,
                ];
            }
            $count += $this->upsertStreetLocals($rows);
            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return $count;
    }

    /**
     * 可选离线回填：从 region-locals/{CC}.{locale}.tsv 写入指定语言 Local（非默认升级路径）。
     */
    public function importLocalePack(string $countryCode, string $locale, string $path): int
    {
        $countryCode = strtoupper(trim($countryCode));
        $locale = trim($locale);
        if ($countryCode === '' || $locale === '' || !is_file($path)) {
            return 0;
        }

        $map = [];
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return 0;
        }
        $header = fgetcsv($fh, 0, "\t", '"', '\\');
        if (!is_array($header)) {
            fclose($fh);

            return 0;
        }
        while (($row = fgetcsv($fh, 0, "\t", '"', '\\')) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $assoc = @array_combine($header, $row);
            if (!is_array($assoc)) {
                continue;
            }
            $code = trim((string)($assoc['region_code'] ?? ''));
            $name = trim((string)($assoc['region_name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }
            $map[$code] = $name;
        }
        fclose($fh);
        if ($map === []) {
            return 0;
        }

        /** @var Region $region */
        $region = $this->objectManager->getInstance(Region::class);
        $count = 0;
        $offset = 0;
        while (true) {
            $items = $region->reset()
                ->where(Region::schema_fields_COUNTRY_CODE, $countryCode)
                ->order(Region::schema_fields_ID, 'ASC')
                ->limit(self::PAGE_SIZE, $offset)
                ->select()
                ->fetch()
                ->getItems();
            if ($items === []) {
                break;
            }
            $rows = [];
            foreach ($items as $item) {
                if (!$item instanceof Region || !(int)$item->getId()) {
                    continue;
                }
                $code = trim((string)$item->getData(Region::schema_fields_REGION_CODE));
                $name = $map[$code] ?? '';
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    RegionLocalDescription::schema_fields_ID => (int)$item->getId(),
                    RegionLocalDescription::schema_fields_local_code => $locale,
                    RegionLocalDescription::schema_fields_REGION_NAME => $name,
                ];
            }
            $count += $this->upsertRegionLocals($rows);
            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            $offset += self::PAGE_SIZE;
        }

        return $count;
    }

    private function enqueueLocalModelTranslation(): int
    {
        try {
            /** @var LocalModelTranslationQueueService $queue */
            $queue = $this->objectManager->getInstance(LocalModelTranslationQueueService::class);

            return $queue->enqueue('Weline_Shipping:RegionLocalSeedService', false);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsertRegionLocals(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        /** @var RegionLocalDescription $model */
        $model = $this->objectManager->getInstance(RegionLocalDescription::class);
        $chunkSize = 200;
        $count = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $model->reset()->insert(
                $chunk,
                RegionLocalDescription::schema_fields_ID . ',local_code',
                RegionLocalDescription::schema_fields_REGION_NAME,
            )->fetch();
            $count += count($chunk);
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsertStreetLocals(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        /** @var StreetLocalDescription $model */
        $model = $this->objectManager->getInstance(StreetLocalDescription::class);
        $chunkSize = 200;
        $count = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $model->reset()->insert(
                $chunk,
                StreetLocalDescription::schema_fields_ID . ',local_code',
                StreetLocalDescription::schema_fields_STREET_NAME,
            )->fetch();
            $count += count($chunk);
        }

        return $count;
    }
}
