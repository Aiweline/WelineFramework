<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Model\IpWhitelist;
use Weline\Framework\Manager\ObjectManager;

/**
 * Owns IP whitelist validation and persistence shared by backend UI adapters.
 */
final class IpWhitelistManagementService
{
    /** @return array{success: bool, message: string, data: array<string, mixed>} */
    public function create(string $ip, string $description = '', bool $isActive = true): array
    {
        $ip = \trim($ip);
        $description = \trim($description);
        if ($ip === '') {
            return $this->result(false, (string)__('IP地址不能为空'));
        }
        if (!$this->isValidIpOrRange($ip)) {
            return $this->result(false, (string)__('IP地址格式不正确'));
        }

        /** @var IpWhitelist $existing */
        $existing = ObjectManager::getInstance(IpWhitelist::class, [], false);
        $existing->reset()
            ->where(IpWhitelist::schema_fields_IP, $ip)
            ->find()
            ->fetch();
        if ($existing->getId()) {
            return $this->result(false, (string)__('该IP地址已存在'));
        }

        try {
            /** @var IpWhitelist $item */
            $item = ObjectManager::getInstance(IpWhitelist::class, [], false);
            $item->setData(IpWhitelist::schema_fields_IP, $ip);
            $item->setData(IpWhitelist::schema_fields_DESCRIPTION, $description);
            $item->setData(IpWhitelist::schema_fields_IS_ACTIVE, $isActive ? 1 : 0);
            if (!$item->save()) {
                return $this->result(false, (string)__('添加失败'));
            }
        } catch (\Throwable $throwable) {
            return $this->result(
                false,
                (string)__('添加失败：%{1}', $throwable->getMessage()),
                ['error_code' => 'ip_whitelist_persist_failed'],
            );
        }

        return $this->result(true, (string)__('添加成功'), [
            'id' => (int)$item->getId(),
            'ip' => $ip,
            'description' => $description,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    private function isValidIpOrRange(string $value): bool
    {
        if (\filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (!\str_contains($value, '/')) {
            return false;
        }

        [$ip, $prefix] = \array_pad(\explode('/', $value, 2), 2, '');
        if (!\filter_var($ip, FILTER_VALIDATE_IP) || !\ctype_digit($prefix)) {
            return false;
        }
        $max = \str_contains($ip, ':') ? 128 : 32;

        return (int)$prefix >= 0 && (int)$prefix <= $max;
    }

    /** @param array<string, mixed> $data */
    private function result(bool $success, string $message, array $data = []): array
    {
        return ['success' => $success, 'message' => $message, 'data' => $data];
    }
}
