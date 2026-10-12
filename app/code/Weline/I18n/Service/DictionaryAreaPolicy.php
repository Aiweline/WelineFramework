<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * 词典区（frontend|backend|shared）：路径证据、证据集收口、单调升级。
 * 收集期发现前后端交集 → shared；禁止降级 shared。
 *
 * 无区路径（Service/Model/CSV 等）= 无 exclusive 证据，不得把已有 frontend/backend 抬成 shared。
 */
final class DictionaryAreaPolicy
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';
    public const AREA_SHARED = 'shared';

    /** 路径无前后端信号：不进入证据集，也不作为 upgrade 目标。 */
    public const EVIDENCE_NONE = '';

    public const AREAS = [
        self::AREA_FRONTEND,
        self::AREA_BACKEND,
        self::AREA_SHARED,
    ];

    public static function normalize(?string $area): string
    {
        $area = strtolower(trim((string)$area));
        return match ($area) {
            self::AREA_FRONTEND, 'front' => self::AREA_FRONTEND,
            self::AREA_BACKEND, 'back', 'admin' => self::AREA_BACKEND,
            self::AREA_SHARED, 'cell', 'common', 'both' => self::AREA_SHARED,
            default => self::AREA_SHARED,
        };
    }

    /**
     * 从文件/逻辑路径推断单次证据区。
     * 无前后端信号时返回 EVIDENCE_NONE（空串），禁止记成 shared 证据。
     */
    public static function evidenceFromPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $lower = strtolower($normalized);

        if (
            preg_match('#/(?:view/)?templates/frontend/#', $lower) === 1
            || preg_match('#/(?:view/)?theme/frontend/#', $lower) === 1
            || preg_match('#/app/design/[^/]+/[^/]+/frontend/#', $lower) === 1
            || preg_match('#/controller/frontend/#', $lower) === 1
        ) {
            return self::AREA_FRONTEND;
        }

        // 兼容历史 Backend 目录名（大小写）
        if (
            preg_match('#/(?:view/)?templates/backend/#', $lower) === 1
            || preg_match('#/(?:view/)?theme/backend/#', $lower) === 1
            || preg_match('#/app/design/[^/]+/[^/]+/backend/#', $lower) === 1
            || preg_match('#/controller/(?:backend|admin)/#', $lower) === 1
        ) {
            return self::AREA_BACKEND;
        }

        return self::EVIDENCE_NONE;
    }

    public static function isExclusiveArea(string $area): bool
    {
        $area = self::normalize($area);

        return $area === self::AREA_FRONTEND || $area === self::AREA_BACKEND;
    }

    /**
     * @param list<string>|array<string,bool> $evidenceAreas
     * @return string|null frontend|backend|shared；无 exclusive 证据时返回 null（保持原区 / 新词默认 shared）
     */
    public static function classifyFromEvidence(array $evidenceAreas): ?string
    {
        $seen = [
            self::AREA_FRONTEND => false,
            self::AREA_BACKEND => false,
        ];
        foreach ($evidenceAreas as $key => $value) {
            $raw = is_int($key) ? (string)$value : (string)$key;
            if ($value === false || $raw === self::EVIDENCE_NONE) {
                continue;
            }
            $area = strtolower(trim($raw));
            if ($area === self::AREA_FRONTEND || $area === 'front') {
                $seen[self::AREA_FRONTEND] = true;
            } elseif ($area === self::AREA_BACKEND || $area === 'back' || $area === 'admin') {
                $seen[self::AREA_BACKEND] = true;
            }
            // shared / cell 等非 exclusive 证据忽略
        }

        if ($seen[self::AREA_FRONTEND] && $seen[self::AREA_BACKEND]) {
            return self::AREA_SHARED;
        }
        if ($seen[self::AREA_FRONTEND]) {
            return self::AREA_FRONTEND;
        }
        if ($seen[self::AREA_BACKEND]) {
            return self::AREA_BACKEND;
        }

        return null;
    }

    /**
     * 单调升级：只升不降；shared 为终态。
     * FE⊕BE → shared；无区/默认 shared 目标不得覆盖已有 exclusive。
     */
    public static function upgrade(?string $current, string $evidenceOrTarget): string
    {
        $current = $current === null || $current === ''
            ? ''
            : self::normalize($current);
        $incomingRaw = strtolower(trim($evidenceOrTarget));
        if ($incomingRaw === self::EVIDENCE_NONE || $incomingRaw === 'none') {
            return $current === '' ? self::AREA_SHARED : $current;
        }
        $incoming = self::normalize($incomingRaw);

        if ($current === self::AREA_SHARED) {
            return self::AREA_SHARED;
        }
        if ($current === '') {
            return $incoming;
        }
        // 无 exclusive 信号的「shared」不得抬升已有 frontend/backend
        if ($incoming === self::AREA_SHARED) {
            // 仅当调用方明确用 shared 表示「交集终态」时才写入：见 upgradeToShared()
            return $current;
        }
        if ($current === $incoming) {
            return $current;
        }

        // frontend XOR backend → shared
        return self::AREA_SHARED;
    }

    /**
     * 交集升级到 shared（终态）。与 upgrade(..., shared) 区分：后者保留 exclusive。
     */
    public static function upgradeToShared(?string $current): string
    {
        return self::AREA_SHARED;
    }

    /**
     * persist 用：有分类结果时合并；null=本批无 exclusive 证据。
     */
    public static function applyClassified(?string $current, ?string $classified): string
    {
        if ($classified === null || $classified === self::EVIDENCE_NONE) {
            return ($current === null || $current === '')
                ? self::AREA_SHARED
                : self::normalize($current);
        }
        $classified = self::normalize($classified);
        if ($classified === self::AREA_SHARED) {
            return self::upgradeToShared($current);
        }

        return self::upgrade($current, $classified);
    }

    /**
     * 历史 is_backend 回填。
     */
    public static function fromLegacyIsBackend(int|string|bool|null $isBackend): string
    {
        return ((int)$isBackend) === 1 ? self::AREA_BACKEND : self::AREA_FRONTEND;
    }

    /**
     * dictionary_register / 遗留 collect 事件：路径证据权威；禁止 is_backend=0 → frontend。
     *
     * @param array<string, mixed> $translation
     */
    public static function resolveRegistrationArea(array $translation): string
    {
        $rawArea = $translation['area'] ?? null;
        if ($rawArea !== null && $rawArea !== '' && $rawArea !== self::EVIDENCE_NONE) {
            return self::normalize((string)$rawArea);
        }
        if (isset($translation['is_backend']) && (int)$translation['is_backend'] === 1) {
            return self::AREA_BACKEND;
        }

        return self::AREA_SHARED;
    }

    public static function bagSuffix(string $area): string
    {
        return self::normalize($area);
    }

    public static function localeBagBasename(string $localeCode, string $area): string
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        $area = self::normalize($area);

        return $localeCode . '.' . $area . '.php';
    }
}
