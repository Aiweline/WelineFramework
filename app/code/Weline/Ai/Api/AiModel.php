<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/**
 * Data-only model snapshot supplied to module-provided agents.
 *
 * Provider credentials/configuration remain opaque data. Only Weline_Ai can
 * hydrate this snapshot back into its private ORM model for provider calls.
 */
class AiModel
{
    public const PRIMARY_MODALITY_TEXT_TO_TEXT = 'text2text';
    public const PRIMARY_MODALITY_TEXT_TO_IMAGE = 'text2image';
    public const PRIMARY_MODALITY_TEXT_TO_VIDEO = 'text2video';
    public const PRIMARY_MODALITY_EMBEDDING = 'embedding';

    /** @param array<string,mixed> $data */
    public function __construct(
        private readonly array $data = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function getData(?string $field = null, mixed $default = null): mixed
    {
        if ($field === null) {
            return $this->data;
        }

        return $this->data[$field] ?? $default;
    }

    public function getId(): int
    {
        return (int)($this->data['id'] ?? 0);
    }

    public function getModelCode(): string
    {
        return (string)($this->data['model_code'] ?? '');
    }

    public function getVendor(): string
    {
        return (string)($this->data['supplier'] ?? '');
    }

    public function getSupplier(): string
    {
        return $this->getVendor();
    }

    public function getName(): string
    {
        return (string)($this->data['name'] ?? '');
    }

    public function getVersion(): string
    {
        return (string)($this->data['version'] ?? '');
    }

    public function getPrimaryModality(): string
    {
        return self::normalizePrimaryModality((string)($this->data['primary_modality'] ?? ''));
    }

    public function supportsPrimaryModality(string $primaryModality): bool
    {
        return $this->getPrimaryModality() === self::normalizePrimaryModality($primaryModality);
    }

    public function isActive(): bool
    {
        return (bool)($this->data['is_active'] ?? false);
    }

    public function isDefault(): bool
    {
        return (bool)($this->data['is_default'] ?? false);
    }

    public function getMaxTokens(): int
    {
        return max(0, (int)($this->data['max_tokens'] ?? 0));
    }

    public function getTokenPriceInput(): float
    {
        return (float)($this->data['token_price_input'] ?? 0.0);
    }

    public function getTokenPriceOutput(): float
    {
        return (float)($this->data['token_price_output'] ?? 0.0);
    }

    public static function normalizePrimaryModality(?string $primaryModality): string
    {
        $primaryModality = trim((string)$primaryModality);

        return in_array($primaryModality, self::supportedPrimaryModalities(), true)
            ? $primaryModality
            : self::PRIMARY_MODALITY_TEXT_TO_TEXT;
    }

    /** @return list<string> */
    public static function supportedPrimaryModalities(): array
    {
        return [
            self::PRIMARY_MODALITY_TEXT_TO_TEXT,
            self::PRIMARY_MODALITY_TEXT_TO_IMAGE,
            self::PRIMARY_MODALITY_TEXT_TO_VIDEO,
            self::PRIMARY_MODALITY_EMBEDDING,
        ];
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return $this->decodeArray($this->data['config'] ?? []);
    }

    /** @return array<string,mixed> */
    public function getProviderConfig(): array
    {
        return $this->decodeArray($this->data['provider_config'] ?? []);
    }

    /** @return array<string,mixed>|list<mixed> */
    public function getCapabilities(): array
    {
        return $this->decodeArray($this->data['capabilities'] ?? []);
    }

    /** @return array<mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
