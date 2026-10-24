<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

/**
 * Mail 域名/账号标签选项组装（value / label / meta / search）。
 */
final class MailSelectOptions
{
    /**
     * @param list<mixed> $domains MailDomain[]（或含 getData 的对象/数组）
     * @return list<array{value: string, label: string, meta: string, search: string}>
     */
    public static function domainsForSelect(array $domains): array
    {
        $options = [];
        foreach ($domains as $row) {
            $id = self::id($row, MailDomain::schema_fields_ID);
            if ($id <= 0) {
                continue;
            }
            $label = \trim(self::value($row, MailDomain::schema_fields_DOMAIN_NAME));
            $meta = \trim(self::value($row, MailDomain::schema_fields_ENGINE));
            $value = (string)$id;
            $options[] = [
                'value' => $value,
                'label' => $label,
                'meta' => $meta,
                'search' => \strtolower($label . ' ' . $meta . ' ' . $value),
            ];
        }

        return $options;
    }

    /**
     * @param list<mixed> $domains MailDomain[]
     */
    public static function domainsForSelectJson(array $domains): string
    {
        return \json_encode(self::domainsForSelect($domains), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * @param list<mixed> $accounts MailAccount[]（或含 getData 的对象/数组）
     * @return list<array{value: string, label: string, meta: string, search: string}>
     */
    public static function accountsForSelect(array $accounts): array
    {
        $options = [];
        foreach ($accounts as $row) {
            $id = self::id($row, MailAccount::schema_fields_ID);
            if ($id <= 0) {
                continue;
            }
            $label = \trim(self::value($row, MailAccount::schema_fields_EMAIL));
            $value = (string)$id;
            $options[] = [
                'value' => $value,
                'label' => $label,
                'meta' => '',
                'search' => \strtolower($label . ' ' . $value),
            ];
        }

        return $options;
    }

    /**
     * @param list<mixed> $accounts MailAccount[]
     */
    public static function accountsForSelectJson(array $accounts): string
    {
        return \json_encode(self::accountsForSelect($accounts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private static function id(object|array $row, string $key): int
    {
        if (\is_object($row)) {
            $id = $row->getId() ?? $row->getData($key);
        } else {
            $id = $row['id'] ?? $row[$key] ?? null;
        }

        return (int)$id;
    }

    private static function value(object|array $row, string $key): string
    {
        $value = \is_object($row) ? $row->getData($key) : ($row[$key] ?? '');

        return (string)($value ?? '');
    }
}
