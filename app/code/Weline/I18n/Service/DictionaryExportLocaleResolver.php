<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;

/**
 * collect 后 Export 语种集：全量 / --republish-all → 全部发布语种；
 * 单模块默认仅源语 + en_US（店面基线），避免 O(locales×N) 全表导出。
 */
final class DictionaryExportLocaleResolver
{
    public const BASELINE_EN = 'en_US';

    public function __construct(
        private readonly AiTranslationConfig $aiTranslationConfig,
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolveForCollect(?string $moduleName, bool $republishAll): array
    {
        $all = $this->aiTranslationConfig->getDictionaryPublishLocaleCodes();
        $moduleName = is_string($moduleName) ? trim($moduleName) : '';
        if ($republishAll || $moduleName === '') {
            return $all;
        }

        $source = trim(str_replace('-', '_', (string)$this->aiTranslationConfig->getSourceLocale()));
        if ($source === '') {
            $source = Env::default_LANGUAGE_CODE;
        }
        $baseline = [];
        foreach ([$source, self::BASELINE_EN] as $code) {
            $code = trim(str_replace('-', '_', (string)$code));
            if ($code !== '') {
                $baseline[$code] = true;
            }
        }

        return array_keys($baseline);
    }
}
