<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Symfony\Component\Intl\Countries as IntlCountries;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\EmbargoRegion;

/**
 * 系统默认禁运：list/add/deactivate/activate；种子(origin=seed)禁物理删除；自建(origin=manual)可删；禁 replaceForScope。
 */
final class SystemEmbargoAdminService
{
    public const ERROR_DELETE_FORBIDDEN = 'system_embargo_delete_forbidden';
    public const ERROR_INVALID_COUNTRY = 'system_embargo_invalid_country';
    public const ERROR_INVALID_REGION = 'system_embargo_invalid_region';
    public const ERROR_REPLACE_FORBIDDEN = 'system_embargo_replace_forbidden';

    /** @var list<string> */
    private const ALLOWED_REGION_TYPES = [
        EmbargoRegion::TYPE_COUNTRY,
        EmbargoRegion::TYPE_PROVINCE,
    ];

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly SystemEmbargoResourceChangePublisher $resourceChanges,
        private readonly RegionLocalNameResolver $localNames,
        private readonly EmbargoReasonAdminService $reasons,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $items = $model->reset()
            ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, EmbargoRegion::SCOPE_SYSTEM)
            ->where(EmbargoRegion::schema_fields_SCOPE_ID, 0)
            ->order(EmbargoRegion::schema_fields_COUNTRY_CODE, 'ASC')
            ->order(EmbargoRegion::schema_fields_REGION_TYPE, 'ASC')
            ->order(EmbargoRegion::schema_fields_REGION_ID, 'ASC')
            ->order(EmbargoRegion::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $regionIds = [];
        foreach ($items as $item) {
            if (!$item instanceof EmbargoRegion) {
                continue;
            }
            $rid = (int)$item->getData(EmbargoRegion::schema_fields_REGION_ID);
            if ($rid > 0) {
                $regionIds[] = $rid;
            }
        }
        $regionNames = $this->localNames->namesByRegionIds($regionIds);

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof EmbargoRegion) {
                continue;
            }
            $out[] = $this->rowFromModel($item, $regionNames);
        }

        return $out;
    }

    /**
     * Upsert 国家级系统禁运（默认启用）。不删除已有行。
     *
     * @return array<string, mixed>
     */
    public function addCountry(string $countryCode, string $reasonCode = 'no_commerce'): array
    {
        return $this->addRegion(
            EmbargoRegion::TYPE_COUNTRY,
            $countryCode,
            0,
            '',
            $reasonCode,
            EmbargoRegion::ORIGIN_MANUAL,
        );
    }

    /**
     * Upsert 系统禁运（国家或省份）。不删除已有行。
     *
     * @return array<string, mixed>
     */
    public function addRegion(
        string $regionType,
        string $countryCode,
        int $regionId = 0,
        string $regionCode = '',
        string $reasonCode = 'no_commerce',
        string $origin = EmbargoRegion::ORIGIN_MANUAL,
    ): array {
        $type = strtolower(trim($regionType));
        if (!in_array($type, self::ALLOWED_REGION_TYPES, true)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID_REGION);
        }
        $cc = strtoupper(trim($countryCode));
        if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID_COUNTRY);
        }
        $id = max(0, $regionId);
        $code = trim($regionCode);
        if ($type === EmbargoRegion::TYPE_COUNTRY) {
            $id = 0;
            $code = '';
        } elseif ($id <= 0 && $code === '') {
            throw new \InvalidArgumentException(self::ERROR_INVALID_REGION);
        }
        $reason = trim($reasonCode) !== '' ? trim($reasonCode) : 'no_commerce';
        $originNorm = strtolower(trim($origin));
        // 种子仅允许国家级；省份等一律按自建，避免后续录入被标成系统种子。
        if ($originNorm !== EmbargoRegion::ORIGIN_SEED || $type !== EmbargoRegion::TYPE_COUNTRY) {
            $originNorm = EmbargoRegion::ORIGIN_MANUAL;
        }
        $existing = $this->findNatural($cc, $type, $id, $code, 0);
        $now = date('Y-m-d H:i:s');
        if ($existing instanceof EmbargoRegion) {
            $wasActive = (int)$existing->getData(EmbargoRegion::schema_fields_IS_ACTIVE) === 1;
            // 后台自建路径：已在禁运中则直接提示已存在，避免重复「成功新增」错觉。
            if ($wasActive && $originNorm === EmbargoRegion::ORIGIN_MANUAL) {
                $row = $this->rowFromModel($existing);
                $row['outcome'] = 'already_active';

                return $row;
            }
            $existing->setData(EmbargoRegion::schema_fields_REASON_CODE, $reason);
            $existing->setData(EmbargoRegion::schema_fields_IS_ACTIVE, 1);
            $existing->setData(EmbargoRegion::schema_fields_DISABLED_BY, '');
            $existing->setData(EmbargoRegion::schema_fields_DISABLED_AT, null);
            $existing->setData(EmbargoRegion::schema_fields_UPDATED_AT, $now);
            // 种子导入可升格为 seed；人工新增不得把 seed 降为 manual。
            if ($originNorm === EmbargoRegion::ORIGIN_SEED) {
                $existing->setData(EmbargoRegion::schema_fields_ORIGIN, EmbargoRegion::ORIGIN_SEED);
            }
            $existing->save();
            $row = $this->rowFromModel($existing);
            $row['outcome'] = $wasActive ? 'already_active' : 'reactivated';

            return $row;
        }

        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $model->clearData()->setData([
            EmbargoRegion::schema_fields_SCOPE_TYPE => EmbargoRegion::SCOPE_SYSTEM,
            EmbargoRegion::schema_fields_SCOPE_ID => 0,
            EmbargoRegion::schema_fields_REGION_TYPE => $type,
            EmbargoRegion::schema_fields_COUNTRY_CODE => $cc,
            EmbargoRegion::schema_fields_REGION_ID => $id,
            EmbargoRegion::schema_fields_REGION_CODE => $code,
            EmbargoRegion::schema_fields_STREET_ID => 0,
            EmbargoRegion::schema_fields_REASON_CODE => $reason,
            EmbargoRegion::schema_fields_ORIGIN => $originNorm,
            EmbargoRegion::schema_fields_IS_ACTIVE => 1,
            EmbargoRegion::schema_fields_DISABLED_BY => '',
            EmbargoRegion::schema_fields_CREATED_AT => $now,
            EmbargoRegion::schema_fields_UPDATED_AT => $now,
        ])->save();

        $row = $this->rowFromModel($model);
        $row['outcome'] = 'created';

        return $row;
    }

    /**
     * 解禁：is_active=0，保留行。全站生效。
     *
     * @return array<string, mixed>
     */
    public function deactivate(int $embargoId, string $operator = 'admin'): array
    {
        $row = $this->requireSystemRow($embargoId);
        $before = (array)$row->getData();
        $now = date('Y-m-d H:i:s');
        $row->setData(EmbargoRegion::schema_fields_IS_ACTIVE, 0);
        $row->setData(EmbargoRegion::schema_fields_DISABLED_BY, trim($operator) !== '' ? trim($operator) : 'admin');
        $row->setData(EmbargoRegion::schema_fields_DISABLED_AT, $now);
        $row->setData(EmbargoRegion::schema_fields_UPDATED_AT, $now);
        $row->save();
        $this->resourceChanges->publish(
            $row->getConnection(),
            'upsert',
            $embargoId,
            $before,
            (array)$row->getData(),
            'shipping.system_embargo.deactivate',
        );

        return $this->rowFromModel($row);
    }

    /**
     * 再启用禁运。
     *
     * @return array<string, mixed>
     */
    public function activate(int $embargoId): array
    {
        $row = $this->requireSystemRow($embargoId);
        $before = (array)$row->getData();
        $now = date('Y-m-d H:i:s');
        $row->setData(EmbargoRegion::schema_fields_IS_ACTIVE, 1);
        $row->setData(EmbargoRegion::schema_fields_DISABLED_BY, '');
        $row->setData(EmbargoRegion::schema_fields_DISABLED_AT, null);
        $row->setData(EmbargoRegion::schema_fields_UPDATED_AT, $now);
        $row->save();
        $this->resourceChanges->publish(
            $row->getConnection(),
            'upsert',
            $embargoId,
            $before,
            (array)$row->getData(),
            'shipping.system_embargo.activate',
        );

        return $this->rowFromModel($row);
    }

    /**
     * 物理删除自建条目；种子(origin=seed)禁止删除。
     */
    public function delete(int $embargoId): void
    {
        $row = $this->requireSystemRow($embargoId);
        $origin = strtolower(trim((string)$row->getData(EmbargoRegion::schema_fields_ORIGIN)));
        if ($origin !== EmbargoRegion::ORIGIN_MANUAL) {
            throw new \RuntimeException(self::ERROR_DELETE_FORBIDDEN);
        }
        $before = (array)$row->getData();
        $connection = $row->getConnection();
        $row->delete();
        $this->resourceChanges->publish(
            $connection,
            'delete',
            $embargoId,
            $before,
            null,
            'shipping.system_embargo.delete',
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function deleteForbidden(): never
    {
        throw new \RuntimeException(self::ERROR_DELETE_FORBIDDEN);
    }

    /**
     * Seed upsert from TSV rows. Never deletes. Always origin=seed.
     *
     * @param list<array{country_code:string,reason_code?:string}> $rows
     */
    public function seedFromRows(array $rows): int
    {
        $n = 0;
        $canonical = [];
        foreach ($rows as $row) {
            $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
            if ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc)) {
                continue;
            }
            $canonical[$cc] = true;
            $this->addRegion(
                EmbargoRegion::TYPE_COUNTRY,
                $cc,
                0,
                '',
                (string)($row['reason_code'] ?? 'no_commerce'),
                EmbargoRegion::ORIGIN_SEED,
            );
            $n++;
        }
        // 清理误标 seed 的非 TSV 行（如后续手工录入的中国省份被标成系统种子）。
        $this->purgeNonCanonicalSeeds(array_keys($canonical));

        return $n;
    }

    /**
     * 删除「origin=seed」但并非 TSV 国家级种子的系统行（含省份伪种子、CN 等）。
     *
     * @param list<string>|null $canonicalCountryCodes null 时从默认 TSV 读取
     */
    public function purgeNonCanonicalSeeds(?array $canonicalCountryCodes = null): int
    {
        $allowed = [];
        foreach ($canonicalCountryCodes ?? $this->loadCanonicalSeedCountries() as $cc) {
            $cc = strtoupper(trim((string)$cc));
            if ($cc !== '' && preg_match('/^[A-Z]{2}$/', $cc)) {
                $allowed[$cc] = true;
            }
        }

        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $items = $model->reset()
            ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, EmbargoRegion::SCOPE_SYSTEM)
            ->where(EmbargoRegion::schema_fields_SCOPE_ID, 0)
            ->where(EmbargoRegion::schema_fields_ORIGIN, EmbargoRegion::ORIGIN_SEED)
            ->select()
            ->fetch()
            ->getItems();

        $removed = 0;
        foreach ($items as $item) {
            if (!$item instanceof EmbargoRegion) {
                continue;
            }
            $type = (string)$item->getData(EmbargoRegion::schema_fields_REGION_TYPE);
            $cc = strtoupper(trim((string)$item->getData(EmbargoRegion::schema_fields_COUNTRY_CODE)));
            $isCanonicalCountrySeed = $type === EmbargoRegion::TYPE_COUNTRY && isset($allowed[$cc]);
            if ($isCanonicalCountrySeed) {
                continue;
            }
            $embargoId = (int)$item->getId();
            $before = (array)$item->getData();
            $connection = $item->getConnection();
            $item->delete();
            $this->resourceChanges->publish(
                $connection,
                'delete',
                $embargoId,
                $before,
                null,
                'shipping.system_embargo.purge_non_canonical_seed',
            );
            $removed++;
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    public function loadCanonicalSeedCountries(): array
    {
        $path = BP . 'app/code/Weline/Shipping/data/system-embargo/countries.tsv';
        if (!is_file($path)) {
            return [];
        }
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $i => $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || ($i === 0 && str_contains($line, 'country_code'))) {
                continue;
            }
            $parts = preg_split("/\t|,\s*/", $line) ?: [];
            $cc = strtoupper(trim((string)($parts[0] ?? '')));
            if ($cc !== '' && preg_match('/^[A-Z]{2}$/', $cc)) {
                $out[] = $cc;
            }
        }

        return array_values(array_unique($out));
    }

    private function requireSystemRow(int $embargoId): EmbargoRegion
    {
        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $model->load($embargoId);
        if (!(int)$model->getId()
            || (string)$model->getData(EmbargoRegion::schema_fields_SCOPE_TYPE) !== EmbargoRegion::SCOPE_SYSTEM
        ) {
            throw new \InvalidArgumentException('system_embargo_not_found');
        }

        return $model;
    }

    private function findNatural(
        string $countryCode,
        string $regionType,
        int $regionId,
        string $regionCode,
        int $streetId,
    ): ?EmbargoRegion {
        /** @var EmbargoRegion $model */
        $model = $this->objectManager->getInstance(EmbargoRegion::class);
        $items = $model->reset()
            ->where(EmbargoRegion::schema_fields_SCOPE_TYPE, EmbargoRegion::SCOPE_SYSTEM)
            ->where(EmbargoRegion::schema_fields_SCOPE_ID, 0)
            ->where(EmbargoRegion::schema_fields_REGION_TYPE, $regionType)
            ->where(EmbargoRegion::schema_fields_COUNTRY_CODE, $countryCode)
            ->where(EmbargoRegion::schema_fields_REGION_ID, $regionId)
            ->where(EmbargoRegion::schema_fields_REGION_CODE, $regionCode)
            ->where(EmbargoRegion::schema_fields_STREET_ID, $streetId)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($items as $item) {
            if ($item instanceof EmbargoRegion) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $regionNames
     * @return array<string, mixed>
     */
    private function rowFromModel(EmbargoRegion $item, array $regionNames = []): array
    {
        $active = (int)$item->getData(EmbargoRegion::schema_fields_IS_ACTIVE) === 1;
        $type = (string)$item->getData(EmbargoRegion::schema_fields_REGION_TYPE);
        $cc = strtoupper(trim((string)$item->getData(EmbargoRegion::schema_fields_COUNTRY_CODE)));
        $regionCode = trim((string)$item->getData(EmbargoRegion::schema_fields_REGION_CODE));
        $regionId = (int)$item->getData(EmbargoRegion::schema_fields_REGION_ID);
        $reasonCode = trim((string)$item->getData(EmbargoRegion::schema_fields_REASON_CODE));

        $countryName = $this->localizedCountryName($cc);
        $regionName = '';
        if ($regionId > 0) {
            $regionName = trim((string)($regionNames[$regionId] ?? ''));
            if ($regionName === '') {
                $regionName = $this->localNames->nameByRegionId($regionId);
            }
        }
        if ($regionName === '' && $regionCode !== '') {
            $regionName = strtoupper($regionCode);
        }

        if ($type === EmbargoRegion::TYPE_PROVINCE) {
            $label = $countryName !== '' && $regionName !== ''
                ? ($countryName . ' / ' . $regionName)
                : ($countryName !== '' ? $countryName : ($cc !== '' ? $cc : '#' . $regionId));
            if ($regionName === '' && $regionId > 0) {
                $label .= ' #' . $regionId;
            }
        } else {
            $label = $countryName !== '' ? $countryName : $cc;
        }

        $origin = strtolower(trim((string)$item->getData(EmbargoRegion::schema_fields_ORIGIN)));
        if ($origin !== EmbargoRegion::ORIGIN_MANUAL) {
            $origin = EmbargoRegion::ORIGIN_SEED;
        }

        return [
            'embargo_id' => (int)$item->getId(),
            'scope_type' => EmbargoRegion::SCOPE_SYSTEM,
            'scope_id' => 0,
            'region_type' => $type,
            'country_code' => $cc,
            'country_name' => $countryName,
            'region_id' => $regionId,
            'region_code' => $regionCode,
            'region_name' => $regionName,
            'street_id' => (int)$item->getData(EmbargoRegion::schema_fields_STREET_ID),
            'reason_code' => $reasonCode,
            'reason_label' => $this->reasons->labelForCode($reasonCode),
            'origin' => $origin,
            'is_seed' => $origin === EmbargoRegion::ORIGIN_SEED ? 1 : 0,
            'can_delete' => $origin === EmbargoRegion::ORIGIN_MANUAL ? 1 : 0,
            'is_active' => $active ? 1 : 0,
            'status' => $active ? 'active' : 'deactivated',
            'disabled_by' => (string)$item->getData(EmbargoRegion::schema_fields_DISABLED_BY),
            'disabled_at' => $item->getData(EmbargoRegion::schema_fields_DISABLED_AT),
            'label' => $label,
        ];
    }

    private function localizedCountryName(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));
        if ($countryCode === '') {
            return '';
        }
        try {
            return IntlCountries::getName($countryCode, $this->intlLocale());
        } catch (\Throwable) {
            return $countryCode;
        }
    }

    private function intlLocale(): string
    {
        $locale = trim((string)Cookie::getLangLocal());
        if ($locale === '') {
            $locale = $this->localNames->currentLocale();
        }
        if ($locale === 'zh_Hans_CN') {
            return 'zh_Hans';
        }
        if ($locale === 'zh_Hant_TW') {
            return 'zh_Hant';
        }

        return $locale !== '' ? $locale : 'en';
    }
}
