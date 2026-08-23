<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Data;

final readonly class MetaConfigIdentity
{
    private const FINGERPRINT_VERSION = "weline-meta-config-identity-v1\0";

    public const NAMESPACE_MAX_CHARS = 100;
    public const CONFIG_KEY_MAX_CHARS = 255;
    public const CONFIG_VALUE_MAX_CHARS = 65535;
    public const SCOPE_MAX_CHARS = 100;
    public const LOCALE_MAX_CHARS = 20;
    public const OWNER_MAX_CHARS = 255;
    public const META_ID_MAX = 2147483647;

    public function __construct(
        public string $namespace,
        public string $configKey,
        public string $scope,
        public ?string $locale = null,
        public ?string $identifyId = null,
        public ?int $metaId = null,
        public ?string $metaIdentify = null,
    ) {
        self::assertRequiredText($this->namespace, 'namespace', self::NAMESPACE_MAX_CHARS);
        self::assertRequiredText($this->configKey, 'configKey', self::CONFIG_KEY_MAX_CHARS);
        self::assertRequiredText($this->scope, 'scope', self::SCOPE_MAX_CHARS);
        self::assertOptionalText($this->locale, 'locale', self::LOCALE_MAX_CHARS, true);
        self::assertOptionalText($this->identifyId, 'identifyId', self::OWNER_MAX_CHARS, false);
        self::assertOptionalText($this->metaIdentify, 'metaIdentify', self::OWNER_MAX_CHARS, false);
        if (!$this->hasOwnerIdentity()) {
            throw new \InvalidArgumentException('Meta config identity requires identifyId, metaId, or metaIdentify.');
        }
        if ($this->metaId !== null && ($this->metaId < 1 || $this->metaId > self::META_ID_MAX)) {
            throw new \InvalidArgumentException('Meta config metaId must fit a positive signed 32-bit integer.');
        }
    }

    public function hasOwnerIdentity(): bool
    {
        return ($this->identifyId !== null && trim($this->identifyId) !== '')
            || $this->metaId !== null
            || ($this->metaIdentify !== null && trim($this->metaIdentify) !== '');
    }

    /**
     * Stable SHA-256 identity over the raw UTF-8 bytes of all seven identity fields.
     *
     * This method deliberately does not trim, normalize, or case-fold values. Each
     * segment carries a type tag and a big-endian byte length, so NULL, an empty
     * string, a string value, and an integer value cannot share an encoding.
     */
    public function fingerprint(): string
    {
        return self::generateFingerprint(
            $this->namespace,
            $this->configKey,
            $this->scope,
            $this->locale,
            $this->identifyId,
            $this->metaId,
            $this->metaIdentify,
        );
    }

    public static function generateFingerprint(
        ?string $namespace,
        ?string $configKey,
        ?string $scope,
        ?string $locale,
        ?string $identifyId,
        ?int $metaId,
        ?string $metaIdentify,
    ): string {
        $payload = self::FINGERPRINT_VERSION;
        foreach ([
            $namespace,
            $configKey,
            $scope,
            $locale,
            $identifyId,
            $metaId,
            $metaIdentify,
        ] as $value) {
            $payload .= self::encodeFingerprintSegment($value);
        }

        return hash('sha256', $payload);
    }

    public static function assertNamespace(string $value): void
    {
        self::assertRequiredText($value, 'namespace', self::NAMESPACE_MAX_CHARS);
    }

    public static function assertConfigKey(string $value): void
    {
        self::assertRequiredText($value, 'configKey', self::CONFIG_KEY_MAX_CHARS);
    }

    public static function assertConfigKeyPrefix(?string $value): void
    {
        self::assertOptionalText($value, 'configKeyPrefix', self::CONFIG_KEY_MAX_CHARS, false);
    }

    public static function assertScope(string $value): void
    {
        self::assertRequiredText($value, 'scope', self::SCOPE_MAX_CHARS);
    }

    public static function assertLocale(?string $value): void
    {
        self::assertOptionalText($value, 'locale', self::LOCALE_MAX_CHARS, true);
    }

    public static function assertIdentifyId(?string $value): void
    {
        self::assertOptionalText($value, 'identifyId', self::OWNER_MAX_CHARS, false);
    }

    public static function assertMetaIdentify(?string $value): void
    {
        self::assertOptionalText($value, 'metaIdentify', self::OWNER_MAX_CHARS, false);
    }

    public static function assertValue(string $value): void
    {
        self::assertUtf8AndLength($value, 'value', self::CONFIG_VALUE_MAX_CHARS);
    }

    private static function assertRequiredText(string $value, string $field, int $maxChars): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException("Meta config {$field} must be non-empty.");
        }
        self::assertUtf8AndLength($value, $field, $maxChars);
    }

    private static function assertOptionalText(
        ?string $value,
        string $field,
        int $maxChars,
        bool $rejectBlank,
    ): void {
        if ($value === null) {
            return;
        }
        if ($rejectBlank && trim($value) === '') {
            throw new \InvalidArgumentException("Meta config {$field} must be NULL or non-empty.");
        }
        self::assertUtf8AndLength($value, $field, $maxChars);
    }

    private static function assertUtf8AndLength(string $value, string $field, int $maxChars): void
    {
        $validUtf8 = function_exists('mb_check_encoding')
            ? mb_check_encoding($value, 'UTF-8')
            : preg_match('//u', $value) === 1;
        if (!$validUtf8) {
            throw new \InvalidArgumentException("Meta config {$field} must be valid UTF-8.");
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $maxChars) {
            throw new \InvalidArgumentException(
                "Meta config {$field} cannot exceed {$maxChars} characters.",
            );
        }
    }

    private static function encodeFingerprintSegment(string|int|null $value): string
    {
        if ($value === null) {
            return "n";
        }

        $encoded = is_int($value) ? (string)$value : $value;
        $type = is_int($value) ? 'i' : 's';

        return $type . pack('N', strlen($encoded)) . $encoded;
    }
}
