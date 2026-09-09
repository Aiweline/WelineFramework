<?php

declare(strict_types=1);

namespace Weline\FileManager\Api\Data;

final readonly class ImageUsage
{
    public const VERSION = 1;
    public const ALT_CONFIRMED = 'confirmed';
    public const ALT_NEEDS_REVIEW = 'needs_review';
    private const MAX_WIDTHS = 16;
    private const MAX_ALT_BYTES = 2048;
    private const MAX_CAPTION_BYTES = 4096;
    private const MAX_SIZES_BYTES = 1024;

    /** @param list<int> $widths */
    public function __construct(
        public string $assetId,
        public string $localeCode,
        public string $alt,
        public string $altState = self::ALT_CONFIRMED,
        public bool $decorative = false,
        public ?string $caption = null,
        public string $loading = 'lazy',
        public string $priority = 'auto',
        public array $widths = [480, 768, 1280],
        public string $sizes = '100vw',
        public int $version = self::VERSION,
        public bool $complement = true,
        /** UI 占位宽（HTML width，用于 CLS 宽高比；非强制显示像素） */
        public ?int $layoutWidth = null,
        /** UI 占位高（HTML height，配合 CSS height:auto 做响应式） */
        public ?int $layoutHeight = null,
    ) {
        if (
            $version !== self::VERSION
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $assetId) !== 1
            || !self::isCanonicalLocale($localeCode)
        ) {
            throw new \InvalidArgumentException((string)__('图片使用数据版本或身份无效。'));
        }
        if (!in_array($altState, [self::ALT_CONFIRMED, self::ALT_NEEDS_REVIEW], true)) {
            throw new \InvalidArgumentException((string)__('图片 alt 状态无效。'));
        }
        if (!in_array($loading, ['lazy', 'eager'], true) || !in_array($priority, ['auto', 'high', 'low'], true)) {
            throw new \InvalidArgumentException((string)__('图片加载策略无效。'));
        }
        if (count($widths) > self::MAX_WIDTHS || count(array_unique($widths, SORT_REGULAR)) !== count($widths)) {
            throw new \InvalidArgumentException((string)__('图片响应式宽度数量或唯一性无效。'));
        }
        foreach ($widths as $width) {
            if (!is_int($width) || $width < 1 || $width > 10000) {
                throw new \InvalidArgumentException((string)__('图片响应式宽度无效。'));
            }
        }
        if (
            strlen($alt) > self::MAX_ALT_BYTES
            || ($caption !== null && strlen($caption) > self::MAX_CAPTION_BYTES)
            || strlen($sizes) > self::MAX_SIZES_BYTES
            || preg_match('//u', $alt . ($caption ?? '') . $sizes) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $alt . ($caption ?? '') . $sizes) === 1
        ) {
            throw new \InvalidArgumentException((string)__('图片文本或响应式 sizes 超过长度限制。'));
        }
        if ($decorative && trim($alt) !== '') {
            throw new \InvalidArgumentException((string)__('装饰图片的 alt 必须为空。'));
        }
        if (($layoutWidth === null) !== ($layoutHeight === null)) {
            throw new \InvalidArgumentException((string)__('图片 UI 宽高必须成对设置。'));
        }
        if (
            $layoutWidth !== null
            && $layoutHeight !== null
            && ($layoutWidth < 1 || $layoutHeight < 1 || $layoutWidth > 10000 || $layoutHeight > 10000)
        ) {
            throw new \InvalidArgumentException((string)__('图片 UI 宽高无效。'));
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $widths = is_array($data['widths'] ?? null) ? $data['widths'] : [480, 768, 1280];
        if (count($widths) > self::MAX_WIDTHS) {
            throw new \InvalidArgumentException((string)__('图片响应式宽度数量或唯一性无效。'));
        }
        $normalizedWidths = [];
        foreach ($widths as $width) {
            if (!is_int($width) && !(is_string($width) && ctype_digit($width))) {
                throw new \InvalidArgumentException((string)__('图片响应式宽度无效。'));
            }
            $normalizedWidths[] = (int)$width;
        }
        $layout = self::layoutPairFromMixed(
            $data['layout_width'] ?? $data['width'] ?? null,
            $data['layout_height'] ?? $data['height'] ?? null,
            isset($data['aspect_ratio']) ? (string)$data['aspect_ratio'] : '',
        );

        return new self(
            (string)($data['asset_id'] ?? ''),
            (string)($data['locale_code'] ?? ''),
            (string)($data['alt'] ?? ''),
            (string)($data['alt_state'] ?? self::ALT_CONFIRMED),
            self::boolean($data['decorative'] ?? false, 'decorative'),
            isset($data['caption']) ? (string)$data['caption'] : null,
            (string)($data['loading'] ?? 'lazy'),
            (string)($data['priority'] ?? 'auto'),
            array_values($normalizedWidths),
            (string)($data['sizes'] ?? '100vw'),
            (int)($data['version'] ?? self::VERSION),
            self::boolean($data['complement'] ?? true, 'complement'),
            $layout[0],
            $layout[1],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = [
            'version' => $this->version,
            'asset_id' => $this->assetId,
            'locale_code' => $this->localeCode,
            'alt' => $this->alt,
            'alt_state' => $this->altState,
            'decorative' => $this->decorative,
            'caption' => $this->caption,
            'loading' => $this->loading,
            'priority' => $this->priority,
            'widths' => $this->widths,
            'sizes' => $this->sizes,
            'complement' => $this->complement,
        ];
        if ($this->layoutWidth !== null && $this->layoutHeight !== null) {
            $data['layout_width'] = $this->layoutWidth;
            $data['layout_height'] = $this->layoutHeight;
        }

        return $data;
    }

    /**
     * 解析 UI 宽高比（如 16/9、16:9），得到 HTML width/height 占位整数。
     *
     * @return array{0:?int,1:?int}
     */
    public static function parseAspectRatio(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }
        if (preg_match('/^(\d{1,5})\s*[\/:xX]\s*(\d{1,5})$/', $raw, $matches) !== 1) {
            throw new \InvalidArgumentException((string)__('图片宽高比格式无效，请使用如 16/9 或 16:9。'));
        }
        $width = (int)$matches[1];
        $height = (int)$matches[2];
        if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000) {
            throw new \InvalidArgumentException((string)__('图片宽高比数值无效。'));
        }

        return [$width, $height];
    }

    /**
     * 合并显式宽高与宽高比；二者须成对出现。
     *
     * @return array{0:?int,1:?int}
     */
    public static function layoutPairFromMixed(mixed $width, mixed $height, string $aspectRatio = ''): array
    {
        $parsedWidth = self::optionalPositiveInt($width, 'layout_width');
        $parsedHeight = self::optionalPositiveInt($height, 'layout_height');
        if ($parsedWidth !== null && $parsedHeight !== null) {
            return [$parsedWidth, $parsedHeight];
        }
        if ($parsedWidth !== null || $parsedHeight !== null) {
            throw new \InvalidArgumentException((string)__('图片 UI 宽高必须成对设置。'));
        }
        return self::parseAspectRatio($aspectRatio);
    }

    private static function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value > 0 && $value <= 10000) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int)$value;
            if ($int > 0 && $int <= 10000) {
                return $int;
            }
        }
        throw new \InvalidArgumentException((string)__('图片 UI 尺寸字段无效：%{1}', [$field]));
    }

    public function assertPublishable(string $expectedLocale): void
    {
        if ($this->localeCode !== $expectedLocale) {
            throw new \RuntimeException((string)__('图片语境语言与发布语言不一致。'));
        }
        if ($this->altState !== self::ALT_CONFIRMED) {
            throw new \RuntimeException((string)__('图片 alt 尚未审核确认。'));
        }
        if (!$this->decorative && trim($this->alt) === '') {
            throw new \RuntimeException((string)__('信息图片必须填写 alt。'));
        }
    }

    private static function isCanonicalLocale(string $locale): bool
    {
        if (strlen($locale) > 16 || preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $locale) !== 1) {
            return false;
        }
        return !str_contains($locale, '__');
    }

    private static function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true' => true,
                '0', 'false', '' => false,
                default => throw new \InvalidArgumentException((string)__(
                    '图片使用数据布尔字段无效：%{1}',
                    [$field],
                )),
            };
        }
        throw new \InvalidArgumentException((string)__('图片使用数据布尔字段无效：%{1}', [$field]));
    }
}
