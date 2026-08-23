<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/** Immutable layout identity exchanged across module boundaries. */
final readonly class LayoutIdentity
{
    public const REQUEST_CONTEXT_KEY = 'theme.layout_identity';

    public string $layoutOption;
    public string $scope;
    public string $targetType;
    public int $targetId;
    public string $localeCode;

    public function __construct(
        string $layoutOption = 'default',
        string $scope = 'default',
        string $targetType = 'global',
        int $targetId = 0,
        string $localeCode = '',
    ) {
        $layoutOption = trim($layoutOption);
        $scope = trim($scope);
        $targetType = trim($targetType);
        $localeCode = self::normalizeLocaleCode($localeCode);

        if (
            $layoutOption === ''
            // ThemeLayout, ThemeLayoutVersion and default-injection rows all
            // persist this identity in varchar(100). Validate at the public
            // boundary so MySQL strict-mode differences cannot truncate it.
            || strlen($layoutOption) > 100
            || preg_match('#^[a-zA-Z0-9][a-zA-Z0-9/_-]*$#', $layoutOption) !== 1
            || str_contains($layoutOption, '..')
            || $scope === ''
            || strlen($scope) > 400
            || preg_match('/[\x00-\x1F\x7F]/', $scope) === 1
            || $targetType === ''
            || strlen($targetType) > 50
            || preg_match('/^[a-z][a-z0-9_.-]*$/', $targetType) !== 1
            || $targetId < 0
        ) {
            throw new \InvalidArgumentException((string)__('Theme 布局身份无效。'));
        }

        $this->layoutOption = $layoutOption;
        $this->scope = $scope;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->localeCode = $localeCode;
    }

    /** @param array<string,mixed> $identity */
    public static function fromArray(array $identity): self
    {
        return new self(
            (string)($identity['layout_option'] ?? 'default'),
            (string)($identity['scope'] ?? 'default'),
            (string)($identity['target_type'] ?? $identity['theme_layout_target_type'] ?? 'global'),
            (int)($identity['target_id'] ?? $identity['theme_layout_target_id'] ?? 0),
            (string)($identity['locale_code'] ?? $identity['locale'] ?? ''),
        );
    }

    /** @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string} */
    public function toArray(): array
    {
        return [
            'layout_option' => $this->layoutOption,
            'scope' => $this->scope,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'locale_code' => $this->localeCode,
        ];
    }

    private static function normalizeLocaleCode(string $localeCode): string
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return '';
        }
        if (preg_match('/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{4})?(?:_(?:[a-zA-Z]{2}|[0-9]{3}))?$/D', $localeCode) !== 1) {
            throw new \InvalidArgumentException((string)__('布局语言代码无效：%{1}', [$localeCode]));
        }
        $parts = explode('_', $localeCode);
        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1])) {
            $parts[1] = strlen($parts[1]) === 4 ? ucfirst(strtolower($parts[1])) : strtoupper($parts[1]);
        }
        if (isset($parts[2])) {
            $parts[2] = strtoupper($parts[2]);
        }
        return implode('_', $parts);
    }
}
