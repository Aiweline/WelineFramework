<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Visitor\Model\PixelChannel;

/**
 * B02：pixel_channel code/name 纯校验（不查库）。
 * 唯一冲突通过注入的 $codeExists 探测器判定，便于单测与后续 B04 复用。
 */
class PixelChannelValidationService
{
    /** campaign code：小写字母/数字开头，2–32 位，仅 a-z0-9_- */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,31}$/D';

    public const NAME_MAX_LENGTH = 255;
    public const CODE_MAX_LENGTH = 64;

    /**
     * 校验新建数据。返回错误消息列表；空数组=通过。
     *
     * @param array<string,mixed> $data
     * @param callable(string $code, int $websiteId): bool|null $codeExists 冲突探测器（同 website_id 下 code 是否已存在；含全局 0 由调用方决定口径）
     * @return list<string>
     */
    public function validateForCreate(array $data, ?callable $codeExists = null): array
    {
        $errors = $this->validateCommon($data);

        $code = $this->str($data, PixelChannel::schema_fields_CODE);
        $websiteId = (int)($data[PixelChannel::schema_fields_WEBSITE_ID] ?? 0);
        if ($code !== '' && $codeExists !== null && $codeExists($code, $websiteId)) {
            $errors[] = (string)__('渠道码 %{1} 在该站点下已存在', $code);
        }

        return $errors;
    }

    /**
     * 校验编辑数据。campaign 的 code 创建后不可改。
     *
     * @param array<string,mixed> $data 提交的新数据
     * @param array<string,mixed> $original 库中原始行
     * @return list<string>
     */
    public function validateForUpdate(array $data, array $original): array
    {
        $errors = $this->validateCommon($data);

        $newCode = $this->str($data, PixelChannel::schema_fields_CODE);
        $oldCode = $this->str($original, PixelChannel::schema_fields_CODE);
        $oldKind = $this->str($original, PixelChannel::schema_fields_KIND) ?: PixelChannel::KIND_CAMPAIGN;
        if ($oldKind === PixelChannel::KIND_CAMPAIGN && $newCode !== '' && $newCode !== $oldCode) {
            $errors[] = (string)__('campaign 渠道码创建后不可修改');
        }

        return $errors;
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function validateCommon(array $data): array
    {
        $errors = [];

        $kind = $this->str($data, PixelChannel::schema_fields_KIND) ?: PixelChannel::KIND_CAMPAIGN;
        if (!in_array($kind, PixelChannel::KINDS, true)) {
            $errors[] = (string)__('渠道类型无效：%{1}', $kind);
        }

        $name = $this->str($data, PixelChannel::schema_fields_NAME);
        if ($name === '') {
            $errors[] = (string)__('渠道名称必填');
        } elseif (\mb_strlen($name) > self::NAME_MAX_LENGTH) {
            $errors[] = (string)__('渠道名称过长（最多 %{1} 字符）', self::NAME_MAX_LENGTH);
        }

        $code = $this->str($data, PixelChannel::schema_fields_CODE);
        if ($code === '') {
            $errors[] = (string)__('渠道码必填');
        } elseif ($kind === PixelChannel::KIND_CAMPAIGN) {
            if (\preg_match(self::CODE_PATTERN, $code) !== 1) {
                $errors[] = (string)__('渠道码格式无效：需 2-32 位，小写字母或数字开头，仅含 a-z 0-9 _ -');
            }
        } elseif (\strlen($code) > self::CODE_MAX_LENGTH || \preg_match('/\s/', $code) === 1) {
            $errors[] = (string)__('rule 渠道码无效：不含空白且最多 %{1} 字符', self::CODE_MAX_LENGTH);
        }

        $trafficType = $this->str($data, PixelChannel::schema_fields_TRAFFIC_TYPE);
        if ($trafficType !== '' && !in_array($trafficType, PixelChannel::TRAFFIC_TYPES, true)) {
            $errors[] = (string)__('流量类型无效：%{1}', $trafficType);
        }

        $websiteId = $data[PixelChannel::schema_fields_WEBSITE_ID] ?? 0;
        if (!\is_numeric($websiteId) || (int)$websiteId < 0) {
            $errors[] = (string)__('站点ID无效');
        }

        $matchMode = $this->str($data, PixelChannel::schema_fields_MATCH_MODE);
        $matchValue = $this->str($data, PixelChannel::schema_fields_MATCH_VALUE);
        if ($kind === PixelChannel::KIND_RULE) {
            if (!in_array($matchMode, PixelChannel::MATCH_MODES, true)) {
                $errors[] = (string)__('rule 匹配模式无效：%{1}', $matchMode);
            }
            if ($matchValue === '') {
                $errors[] = (string)__('rule 匹配值必填');
            }
        } elseif ($matchMode !== '' || $matchValue !== '') {
            $errors[] = (string)__('campaign 渠道不接受匹配模式/匹配值');
        }

        return $errors;
    }

    /** @param array<string,mixed> $data */
    private function str(array $data, string $key): string
    {
        return \trim((string)($data[$key] ?? ''));
    }
}
