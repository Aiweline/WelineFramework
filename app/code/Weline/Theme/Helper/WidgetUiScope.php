<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 部件 / 组件 UI 作用域：由类型级 weline-code 生成 CSS 类与 JS 命名空间。
 */
final class WidgetUiScope
{
    public readonly string $code;

    public readonly string $cssClass;

    public readonly string $jsNs;

    public readonly string $uid;

    private function __construct(string $code, ?string $uid = null)
    {
        $normalized = self::normalizeCode($code);
        $this->code = $normalized;
        $this->cssClass = self::codeToCssClass($normalized);
        $this->jsNs = self::codeToJsNamespace($normalized);
        $this->uid = $uid !== null && $uid !== ''
            ? self::sanitizeUid($uid)
            : self::generateUid($this->cssClass);
    }

    public static function fromCode(string $code, ?string $uid = null): self
    {
        return new self($code, $uid);
    }

    public static function forWidget(string $widgetCode, ?string $uid = null): self
    {
        $slug = self::slugify($widgetCode);

        return self::fromCode('theme.widget.' . $slug, $uid);
    }

    public static function forComponent(string $componentName, ?string $uid = null): self
    {
        $slug = self::slugify($componentName);

        return self::fromCode('theme.component.' . $slug, $uid);
    }

    public static function normalizeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('weline-code cannot be empty');
        }
        if (strlen($code) > 128) {
            throw new \InvalidArgumentException('weline-code is too long');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/i', $code)) {
            throw new \InvalidArgumentException('weline-code contains invalid characters');
        }

        return strtolower($code);
    }

    public static function codeToCssClass(string $code): string
    {
        $normalized = self::normalizeCode($code);
        $slug = str_replace(['.', '-'], '_', $normalized);
        $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';
        if ($slug === '') {
            throw new \InvalidArgumentException('weline-code cannot produce css class');
        }

        return 'wc-' . $slug;
    }

    public static function codeToJsNamespace(string $code): string
    {
        $normalized = self::normalizeCode($code);

        return str_replace(['.', '-'], '_', $normalized);
    }

    public function cssSelector(): string
    {
        return '.' . $this->cssClass;
    }

    public function instanceSelector(): string
    {
        return '.' . $this->cssClass . '[data-uid="' . $this->uid . '"]';
    }

    public function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['/', '\\', '-'], '_', $value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value !== '' ? $value : 'unknown';
    }

    private static function sanitizeUid(string $uid): string
    {
        $uid = trim($uid);
        if ($uid === '' || strlen($uid) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $uid)) {
            throw new \InvalidArgumentException('widget uid is invalid');
        }

        return $uid;
    }

    private static function generateUid(string $cssClass): string
    {
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (\Throwable) {
            $suffix = substr(md5(uniqid('', true)), 0, 8);
        }

        return $cssClass . '-' . $suffix;
    }
}
