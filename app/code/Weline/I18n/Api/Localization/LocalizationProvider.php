<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\ActiveLocaleCodeProvider;

/**
 * I18n 的语言目录能力实现：只做「哪一问」到「谁来答」的映射。
 *
 * 已安装/已启用语言的读取权威是 {@see ActiveLocaleCodeProvider}（进程袋 + 命名空间代次
 * 失效 + 预热入口）。本类早期自带一套 `Locals` 全表查询，等于给同一事实开了第二条读路径：
 * 冷 Worker 首请求实测多付 2.2–3.3ms，且两条路径的失效点不在一处。这里全部委派，
 * 不再本地查库。
 */
final class LocalizationProvider implements LocalizationProviderInterface
{
    public function priority(): int
    {
        return 10;
    }

    public function languageCodes(): array
    {
        return $this->activeLocales()->getInstalledActiveCodes();
    }

    public function currencyCodes(): array
    {
        return [];
    }

    public function defaultLanguage(): ?string
    {
        return null;
    }

    public function defaultCurrency(): ?string
    {
        return null;
    }

    public function supportsLanguage(string $code): ?bool
    {
        $code = \trim($code);
        if ($code === '') {
            return false;
        }

        return isset($this->activeLocales()->getInstalledActiveCodeMap()[\strtolower($code)]);
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    public function installedLanguageCodes(): ?array
    {
        return $this->activeLocales()->getInstalledCodes();
    }

    private function activeLocales(): ActiveLocaleCodeProvider
    {
        return ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
    }
}
