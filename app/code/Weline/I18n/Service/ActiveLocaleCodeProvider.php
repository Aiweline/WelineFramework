<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locals;

class ActiveLocaleCodeProvider
{
    /** @var string[]|null */
    private ?array $installedActiveCodes = null;

    public function __construct(
        private readonly Locals $locals,
        private readonly Locale $locale,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function getInstalledActiveCodeMap(): array
    {
        $allowedLocaleMap = [];
        foreach ($this->getInstalledActiveCodes() as $code) {
            $allowedLocaleMap[$code] = true;
            $allowedLocaleMap[\strtolower($code)] = true;
        }

        return $allowedLocaleMap;
    }

    /**
     * @return string[]
     */
    public function getInstalledActiveCodes(): array
    {
        if ($this->installedActiveCodes !== null) {
            return $this->installedActiveCodes;
        }

        // 与 LanguageSelect 对齐：Locals 翻译行 + Locale 安装态都参与「已安装+已激活」集合。
        // 仅读 Locals 会漏掉 Locale 已安装、但 Locals 目标语言行尚未补齐的语言。
        $codes = [];
        $seen = [];
        $this->appendInstalledActiveCodes(
            $codes,
            $seen,
            $this->fetchModelInstalledActiveCodes(
                $this->locals,
                Locals::schema_fields_CODE,
                Locals::schema_fields_IS_INSTALL,
                Locals::schema_fields_IS_ACTIVE
            )
        );
        $this->appendInstalledActiveCodes(
            $codes,
            $seen,
            $this->fetchModelInstalledActiveCodes(
                $this->locale,
                Locale::schema_fields_CODE,
                Locale::schema_fields_IS_INSTALL,
                Locale::schema_fields_IS_ACTIVE
            )
        );

        return $this->installedActiveCodes = $codes;
    }

    /**
     * @param object $model
     * @return string[]
     */
    private function fetchModelInstalledActiveCodes(
        object $model,
        string $codeField,
        string $installField,
        string $activeField
    ): array {
        try {
            $rows = $model->clearQuery()
                ->where($installField, 1)
                ->where($activeField, 1)
                ->select($codeField)
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($rows)) {
            return [];
        }

        $codes = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \trim((string)($row[$codeField] ?? $row['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param string[] $codes
     * @param array<string, true> $seen
     * @param string[] $candidates
     */
    private function appendInstalledActiveCodes(array &$codes, array &$seen, array $candidates): void
    {
        foreach ($candidates as $code) {
            $code = \trim((string)$code);
            if ($code === '') {
                continue;
            }
            $key = \strtolower($code);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $codes[] = $code;
        }
    }
}
