<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Customer\Service\CustomerAdminPickerService;
use Weline\Websites\Service\WebsiteSelectOptions;

/**
 * Admin 客户组成员展示富化：主显姓名/邮箱，次显 #id，站点显示名称。
 */
final class GroupMemberDisplayPresenter
{
    /**
     * @param callable(int):(?array<string,mixed>) $resolveCustomer
     * @param callable(int):string $resolveWebsiteLabel
     */
    public function __construct(
        private readonly mixed $resolveCustomer,
        private readonly mixed $resolveWebsiteLabel,
    ) {
    }

    public static function createDefault(): self
    {
        $picker = CustomerAdminPickerService::create();
        $options = [];
        try {
            $options = WebsiteSelectOptions::loadViaQuery('backend');
        } catch (\Throwable) {
            $options = [];
        }

        return new self(
            static function (int $customerId) use ($picker): ?array {
                if ($customerId <= 0) {
                    return null;
                }
                try {
                    return $picker->resolve($customerId);
                } catch (\Throwable) {
                    return null;
                }
            },
            static function (int $websiteId) use ($options): string {
                $label = WebsiteSelectOptions::resolveDisplay($options, (string) $websiteId);
                if ($label === '' || $label === '#' . $websiteId) {
                    return $websiteId === 0
                        ? (string) __('默认站')
                        : ((string) __('站点') . ' #' . $websiteId);
                }

                return $label;
            },
        );
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    public function enrich(array $members): array
    {
        $out = [];
        foreach ($members as $member) {
            if (!\is_array($member)) {
                continue;
            }
            $out[] = $this->enrichOne($member);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $member
     * @return array<string,mixed>
     */
    public function enrichOne(array $member): array
    {
        $customerId = trim((string) ($member['customer_id'] ?? ''));
        $websiteId = (int) ($member['website_id'] ?? 0);
        $resolved = null;
        if ($customerId !== '' && ctype_digit($customerId)) {
            $resolved = ($this->resolveCustomer)((int) $customerId);
        }

        $label = '';
        $email = '';
        $username = '';
        $meta = '';
        if (\is_array($resolved)) {
            $label = trim((string) ($resolved['label'] ?? ''));
            $email = strtolower(trim((string) ($resolved['email'] ?? '')));
            $username = trim((string) ($resolved['username'] ?? ''));
            $meta = trim((string) ($resolved['meta'] ?? ''));
        }
        if ($label === '') {
            $label = $customerId !== '' ? $customerId : (string) __('未知客户');
        }

        return array_merge($member, [
            'display_name' => $label,
            'email' => $email,
            'username' => $username,
            'display_meta' => $meta !== '' ? $meta : $email,
            'website_name' => ($this->resolveWebsiteLabel)($websiteId),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $members
     * @return list<array<string,mixed>>
     */
    public function filter(array $members, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $members;
        }
        $needle = strtolower($query);
        $out = [];
        foreach ($members as $member) {
            if (!\is_array($member)) {
                continue;
            }
            $haystack = strtolower(implode(' ', [
                (string) ($member['customer_id'] ?? ''),
                (string) ($member['display_name'] ?? ''),
                (string) ($member['email'] ?? ''),
                (string) ($member['username'] ?? ''),
                (string) ($member['display_meta'] ?? ''),
                (string) ($member['website_name'] ?? ''),
            ]));
            if (str_contains($haystack, $needle)) {
                $out[] = $member;
            }
        }

        return $out;
    }
}
