<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Model\SystemConfig;

class SocialScopePhoneConfig
{
    public const MODULE = 'Weline_Social';
    public const KEY_PREFIX = 'social/phone/';

    public function __construct(
        private readonly ConfigReader $configReader
    ) {
    }

    public function scopeForWebsite(int $websiteId, string $websiteCode = ''): string
    {
        $websiteId = \max(0, $websiteId);
        $code = \strtolower(\trim($websiteCode));
        if ($websiteId === 0) {
            $code = 'default';
        } elseif ($code === '' || $code === 'default') {
            $code = 'id-' . $websiteId;
        }

        return $this->configReader->normalizeScope($code . '.default.default');
    }

    public function getPhone(int $websiteId, string $websiteCode = ''): array
    {
        $scope = $this->scopeForWebsite($websiteId, $websiteCode);
        $map = $this->configReader->getConfigMapByModule(
            self::MODULE,
            ConfigReader::area_BACKEND,
            $scope,
            ConfigReader::LOCALE_DEFAULT
        );

        $phone = ['number' => '', 'operator' => '', 'note' => ''];
        foreach ($map as $key => $value) {
            $key = (string)$key;
            if (!\str_starts_with($key, self::KEY_PREFIX)) {
                continue;
            }
            $field = \substr($key, \strlen(self::KEY_PREFIX));
            if (!\array_key_exists($field, $phone)) {
                continue;
            }
            $phone[$field] = \trim((string)$value);
        }

        return [
            'number' => $phone['number'],
            'operator' => $phone['operator'],
            'note' => $phone['note'],
            'scope' => $scope,
            'website_id' => $websiteId,
        ];
    }

    public function savePhone(int $websiteId, array $data, string $websiteCode = ''): array
    {
        $scope = $this->scopeForWebsite($websiteId, $websiteCode);

        $number = \trim((string)($data['number'] ?? ''));
        if ($number !== '' && !\preg_match('/^\+?[0-9]{6,20}$/', $number)) {
            return [
                'success' => false,
                'message' => '手机号格式无效（仅数字与可选+前缀，6-20 位）',
            ];
        }

        $values = [
            'number' => $number,
            'operator' => \trim((string)($data['operator'] ?? '')),
            'note' => \trim((string)($data['note'] ?? '')),
        ];

        foreach ($values as $field => $value) {
            ObjectManager::getInstance(SystemConfig::class)->setScopedConfig(
                self::KEY_PREFIX . $field,
                $value,
                self::MODULE,
                ConfigReader::area_BACKEND,
                $scope,
                ConfigReader::LOCALE_DEFAULT
            );
        }

        return [
            'success' => true,
            'message' => '已保存',
            'number' => $values['number'],
            'operator' => $values['operator'],
            'note' => $values['note'],
            'scope' => $scope,
            'website_id' => $websiteId,
        ];
    }
}
