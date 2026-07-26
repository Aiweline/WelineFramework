<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelChannel;

/**
 * B04：新建 campaign（name+code→自动 UTM 包）。
 * 链接预览属 B06；本服务只负责组装与落库。
 */
class PixelChannelCreateService
{
    public const DEFAULT_UTM_SOURCE = 'weline';

    /** @var array<string, string> traffic_type → 默认 utm_medium */
    public const MEDIUM_BY_TRAFFIC_TYPE = [
        PixelChannel::TRAFFIC_PAID => 'cpc',
        PixelChannel::TRAFFIC_SOCIAL => 'social',
        PixelChannel::TRAFFIC_ORGANIC => 'organic',
        PixelChannel::TRAFFIC_EMAIL => 'email',
        PixelChannel::TRAFFIC_REFERRAL => 'referral',
        PixelChannel::TRAFFIC_DIRECT => 'none',
        PixelChannel::TRAFFIC_CUSTOM => 'custom',
    ];

    public function __construct(
        private ?PixelChannelValidationService $validation = null
    ) {
    }

    /**
     * 自动 UTM 包：utm_campaign=code，wch=code，utm_source 默认 weline，utm_medium 随 type。
     *
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, wch: string}
     */
    public function buildUtmPackage(
        string $code,
        string $trafficType,
        ?string $utmSource = null,
        ?string $utmMedium = null
    ): array {
        $code = \trim($code);
        $trafficType = \trim($trafficType) ?: PixelChannel::TRAFFIC_CUSTOM;
        $source = \trim((string)$utmSource);
        if ($source === '') {
            $source = self::DEFAULT_UTM_SOURCE;
        }
        $medium = \trim((string)$utmMedium);
        if ($medium === '') {
            $medium = self::MEDIUM_BY_TRAFFIC_TYPE[$trafficType] ?? 'custom';
        }

        return [
            'utm_source' => $source,
            'utm_medium' => $medium,
            'utm_campaign' => $code,
            'wch' => $code,
        ];
    }

    public function defaultMediumForTrafficType(string $trafficType): string
    {
        $trafficType = \trim($trafficType) ?: PixelChannel::TRAFFIC_CUSTOM;

        return self::MEDIUM_BY_TRAFFIC_TYPE[$trafficType] ?? 'custom';
    }

    /**
     * 组装待保存的 campaign 行（含自动 UTM；不落库）。
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function assembleCampaignRow(array $input): array
    {
        $code = \strtolower(\trim((string)($input[PixelChannel::schema_fields_CODE] ?? $input['code'] ?? '')));
        $name = \trim((string)($input[PixelChannel::schema_fields_NAME] ?? $input['name'] ?? ''));
        $trafficType = \trim((string)($input[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? $input['traffic_type'] ?? PixelChannel::TRAFFIC_CUSTOM));
        if ($trafficType === '' || !\in_array($trafficType, PixelChannel::TRAFFIC_TYPES, true)) {
            $trafficType = PixelChannel::TRAFFIC_CUSTOM;
        }
        $websiteId = (int)($input[PixelChannel::schema_fields_WEBSITE_ID] ?? $input['website_id'] ?? 0);
        if ($websiteId < 0) {
            $websiteId = 0;
        }
        $description = \trim((string)($input[PixelChannel::schema_fields_DESCRIPTION] ?? $input['description'] ?? ''));
        $enabled = isset($input['enabled']) ? ((int)$input['enabled'] === 1 || $input['enabled'] === true || $input['enabled'] === '1') : true;

        $utm = $this->buildUtmPackage(
            $code,
            $trafficType,
            isset($input['utm_source']) ? (string)$input['utm_source'] : null,
            isset($input['utm_medium']) ? (string)$input['utm_medium'] : null,
        );

        return [
            PixelChannel::schema_fields_KIND => PixelChannel::KIND_CAMPAIGN,
            PixelChannel::schema_fields_CODE => $code,
            PixelChannel::schema_fields_NAME => $name,
            PixelChannel::schema_fields_TRAFFIC_TYPE => $trafficType,
            PixelChannel::schema_fields_UTM_SOURCE => $utm['utm_source'],
            PixelChannel::schema_fields_UTM_MEDIUM => $utm['utm_medium'],
            PixelChannel::schema_fields_UTM_CAMPAIGN => $utm['utm_campaign'],
            PixelChannel::schema_fields_MATCH_MODE => null,
            PixelChannel::schema_fields_MATCH_VALUE => null,
            PixelChannel::schema_fields_PRIORITY => 100,
            PixelChannel::schema_fields_ENABLED => $enabled ? 1 : 0,
            PixelChannel::schema_fields_WEBSITE_ID => $websiteId,
            PixelChannel::schema_fields_DESCRIPTION => $description !== '' ? $description : null,
            PixelChannel::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
            // 非列字段：供 B06 链接助手使用
            '_wch' => $utm['wch'],
        ];
    }

    /**
     * 校验并创建 campaign。
     *
     * @param array<string,mixed> $input
     * @param callable(string $code, int $websiteId): bool|null $codeExists
     * @return array{ok: bool, errors: list<string>, row: array<string,mixed>, id: int|null, exception: string}
     */
    public function createCampaign(array $input, ?callable $codeExists = null): array
    {
        $row = $this->assembleCampaignRow($input);
        $exists = $codeExists ?? [$this, 'codeExistsInDatabase'];
        $errors = $this->validation()->validateForCreate($row, $exists);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'row' => $row, 'id' => null, 'exception' => ''];
        }

        try {
            /** @var PixelChannel $model */
            $model = ObjectManager::getInstance(PixelChannel::class);
            $model->clear();
            foreach ($row as $key => $value) {
                if (\str_starts_with((string)$key, '_')) {
                    continue;
                }
                $model->setData($key, $value);
            }
            $model->save();
            $id = (int)$model->getId();
            if ($id <= 0) {
                return [
                    'ok' => false,
                    'errors' => [(string)__('保存渠道失败：未获得主键')],
                    'row' => $row,
                    'id' => null,
                    'exception' => '',
                ];
            }

            return ['ok' => true, 'errors' => [], 'row' => $row, 'id' => $id, 'exception' => ''];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'errors' => [(string)__('保存渠道失败：%{1}', [$e->getMessage()])],
                'row' => $row,
                'id' => null,
                'exception' => $e->getMessage(),
            ];
        }
    }

    public function codeExistsInDatabase(string $code, int $websiteId): bool
    {
        try {
            /** @var PixelChannel $model */
            $model = ObjectManager::getInstance(PixelChannel::class);
            $model->reset()
                ->where(PixelChannel::schema_fields_CODE, $code)
                ->where(PixelChannel::schema_fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();

            return (int)$model->getId() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function validation(): PixelChannelValidationService
    {
        return $this->validation ??= ObjectManager::getInstance(PixelChannelValidationService::class);
    }
}
