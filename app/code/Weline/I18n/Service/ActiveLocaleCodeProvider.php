<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Locals;

class ActiveLocaleCodeProvider
{
    private const FIELD_IS_INSTALL = 'is_install';
    private const FIELD_IS_ACTIVE = 'is_active';
    private const FIELD_CODE = 'code';

    /** @var string[]|null */
    private ?array $installedActiveCodes = null;

    public function __construct(
        private readonly Locals $locals,
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

        $rows = $this->locals->clearQuery()
            ->where(self::FIELD_IS_INSTALL, 1)
            ->where(self::FIELD_IS_ACTIVE, 1)
            ->select(self::FIELD_CODE)
            ->fetchArray();

        $codes = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $code = \trim((string)($row[self::FIELD_CODE] ?? ''));
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

        return $this->installedActiveCodes = $codes;
    }
}
