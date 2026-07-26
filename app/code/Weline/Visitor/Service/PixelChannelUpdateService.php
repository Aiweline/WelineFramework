<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelChannel;

/**
 * B05：编辑/停用 campaign；code 只读；不做删除。
 */
class PixelChannelUpdateService
{
    public function __construct(
        private ?PixelChannelValidationService $validation = null,
        private ?PixelChannelCreateService $create = null
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadRow(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            /** @var PixelChannel $model */
            $model = ObjectManager::getInstance(PixelChannel::class);
            $model->clear()->load($id);
            if ((int)$model->getId() <= 0) {
                return null;
            }
            $data = $model->getData();

            return \is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 组装更新行：强制沿用原 code / kind / utm_campaign；允许改 name/type/utm_source|medium/enabled 等。
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $original
     * @return array<string,mixed>
     */
    public function assembleUpdateRow(array $input, array $original): array
    {
        $code = \trim((string)($original[PixelChannel::schema_fields_CODE] ?? ''));
        $kind = \trim((string)($original[PixelChannel::schema_fields_KIND] ?? PixelChannel::KIND_CAMPAIGN))
            ?: PixelChannel::KIND_CAMPAIGN;
        $name = \trim((string)($input[PixelChannel::schema_fields_NAME] ?? $input['name'] ?? ''));
        $trafficType = \trim((string)($input[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? $input['traffic_type'] ?? ''));
        if ($trafficType === '' || !\in_array($trafficType, PixelChannel::TRAFFIC_TYPES, true)) {
            $trafficType = \trim((string)($original[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? PixelChannel::TRAFFIC_CUSTOM))
                ?: PixelChannel::TRAFFIC_CUSTOM;
        }
        $websiteId = (int)($input[PixelChannel::schema_fields_WEBSITE_ID] ?? $input['website_id']
            ?? $original[PixelChannel::schema_fields_WEBSITE_ID] ?? 0);
        if ($websiteId < 0) {
            $websiteId = 0;
        }
        $description = \trim((string)($input[PixelChannel::schema_fields_DESCRIPTION] ?? $input['description']
            ?? $original[PixelChannel::schema_fields_DESCRIPTION] ?? ''));
        $enabled = isset($input['enabled'])
            ? ((int)$input['enabled'] === 1 || $input['enabled'] === true || $input['enabled'] === '1')
            : ((int)($original[PixelChannel::schema_fields_ENABLED] ?? 1) === 1);

        $utm = $this->create()->buildUtmPackage(
            $code,
            $trafficType,
            \array_key_exists('utm_source', $input) ? (string)$input['utm_source'] : (string)($original['utm_source'] ?? ''),
            \array_key_exists('utm_medium', $input) ? (string)$input['utm_medium'] : (string)($original['utm_medium'] ?? ''),
        );

        return [
            PixelChannel::schema_fields_ID => (int)($original[PixelChannel::schema_fields_ID] ?? 0),
            PixelChannel::schema_fields_KIND => $kind,
            PixelChannel::schema_fields_CODE => $code,
            PixelChannel::schema_fields_NAME => $name,
            PixelChannel::schema_fields_TRAFFIC_TYPE => $trafficType,
            PixelChannel::schema_fields_UTM_SOURCE => $utm['utm_source'],
            PixelChannel::schema_fields_UTM_MEDIUM => $utm['utm_medium'],
            PixelChannel::schema_fields_UTM_CAMPAIGN => $code,
            PixelChannel::schema_fields_MATCH_MODE => $original[PixelChannel::schema_fields_MATCH_MODE] ?? null,
            PixelChannel::schema_fields_MATCH_VALUE => $original[PixelChannel::schema_fields_MATCH_VALUE] ?? null,
            PixelChannel::schema_fields_PRIORITY => (int)($original[PixelChannel::schema_fields_PRIORITY] ?? 100),
            PixelChannel::schema_fields_ENABLED => $enabled ? 1 : 0,
            PixelChannel::schema_fields_WEBSITE_ID => $websiteId,
            PixelChannel::schema_fields_DESCRIPTION => $description !== '' ? $description : null,
            PixelChannel::schema_fields_CREATED_AT => $original[PixelChannel::schema_fields_CREATED_AT] ?? null,
            '_wch' => $utm['wch'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok: bool, errors: list<string>, row: array<string,mixed>, id: int|null, exception: string}
     */
    public function updateCampaign(int $id, array $input): array
    {
        $original = $this->loadRow($id);
        if ($original === null) {
            return [
                'ok' => false,
                'errors' => [(string)__('渠道不存在')],
                'row' => [],
                'id' => null,
                'exception' => '',
            ];
        }

        // 强制忽略提交中的 code 篡改
        $input['code'] = (string)($original[PixelChannel::schema_fields_CODE] ?? '');
        $row = $this->assembleUpdateRow($input, $original);
        $errors = $this->validation()->validateForUpdate($row, $original);

        $oldWebsite = (int)($original[PixelChannel::schema_fields_WEBSITE_ID] ?? 0);
        $newWebsite = (int)$row[PixelChannel::schema_fields_WEBSITE_ID];
        if ($newWebsite !== $oldWebsite && $this->codeExistsElsewhere($row['code'], $newWebsite, $id)) {
            $errors[] = (string)__('渠道码 %{1} 在该站点下已存在', $row['code']);
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'row' => $row, 'id' => $id, 'exception' => ''];
        }

        try {
            /** @var PixelChannel $model */
            $model = ObjectManager::getInstance(PixelChannel::class);
            $model->clear()->load($id);
            if ((int)$model->getId() <= 0) {
                return [
                    'ok' => false,
                    'errors' => [(string)__('渠道不存在')],
                    'row' => $row,
                    'id' => null,
                    'exception' => '',
                ];
            }
            foreach ([
                PixelChannel::schema_fields_NAME,
                PixelChannel::schema_fields_TRAFFIC_TYPE,
                PixelChannel::schema_fields_UTM_SOURCE,
                PixelChannel::schema_fields_UTM_MEDIUM,
                PixelChannel::schema_fields_UTM_CAMPAIGN,
                PixelChannel::schema_fields_ENABLED,
                PixelChannel::schema_fields_WEBSITE_ID,
                PixelChannel::schema_fields_DESCRIPTION,
            ] as $field) {
                $model->setData($field, $row[$field] ?? null);
            }
            // 明确不写 code / kind
            $model->save();

            return ['ok' => true, 'errors' => [], 'row' => $row, 'id' => $id, 'exception' => ''];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'errors' => [(string)__('保存渠道失败：%{1}', [$e->getMessage()])],
                'row' => $row,
                'id' => $id,
                'exception' => $e->getMessage(),
            ];
        }
    }

    /**
     * 仅切换启用状态（停用/启用）。
     *
     * @return array{ok: bool, errors: list<string>, enabled: int|null}
     */
    public function setEnabled(int $id, bool $enabled): array
    {
        $original = $this->loadRow($id);
        if ($original === null) {
            return ['ok' => false, 'errors' => [(string)__('渠道不存在')], 'enabled' => null];
        }
        $result = $this->updateCampaign($id, [
            'name' => (string)($original[PixelChannel::schema_fields_NAME] ?? ''),
            'traffic_type' => (string)($original[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? PixelChannel::TRAFFIC_CUSTOM),
            'website_id' => (int)($original[PixelChannel::schema_fields_WEBSITE_ID] ?? 0),
            'description' => (string)($original[PixelChannel::schema_fields_DESCRIPTION] ?? ''),
            'utm_source' => (string)($original[PixelChannel::schema_fields_UTM_SOURCE] ?? ''),
            'utm_medium' => (string)($original[PixelChannel::schema_fields_UTM_MEDIUM] ?? ''),
            'enabled' => $enabled ? 1 : 0,
        ]);

        return [
            'ok' => $result['ok'],
            'errors' => $result['errors'],
            'enabled' => $result['ok'] ? ($enabled ? 1 : 0) : null,
        ];
    }

    public function codeExistsElsewhere(string $code, int $websiteId, int $excludeId): bool
    {
        try {
            /** @var PixelChannel $model */
            $model = ObjectManager::getInstance(PixelChannel::class);
            $model->reset()
                ->where(PixelChannel::schema_fields_CODE, $code)
                ->where(PixelChannel::schema_fields_WEBSITE_ID, $websiteId)
                ->where(PixelChannel::schema_fields_ID, $excludeId, '!=')
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

    private function create(): PixelChannelCreateService
    {
        return $this->create ??= ObjectManager::getInstance(PixelChannelCreateService::class);
    }
}
