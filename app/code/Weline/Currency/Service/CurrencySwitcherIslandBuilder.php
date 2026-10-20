<?php

declare(strict_types=1);

namespace Weline\Currency\Service;


use Weline\Framework\Env\WelineEnv;
use Weline\Currency\Data\CurrencyData;
use Weline\Currency\Helper\CurrencyDisplayName;
use Weline\Currency\Helper\CurrencySymbol;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Framework\App\State;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessSharedInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Helper\SwitcherInstanceId;

/**
 * Precomputed currency-switcher options island (HotCache) + request stamp.
 *
 * Shared bag is selection-neutral and has no SwitcherInstanceId. Serve path
 * stamps instance DOM ids and the active currency. Chrome shell builds emit
 * pending placeholders only (see CTX_SHELL_BUILD).
 */
final class CurrencySwitcherIslandBuilder implements ProcessSharedInterface
{
    public const POOL = 'weline_currency_switcher_island';
    public const LOGICAL_KEY_PREFIX = 'currency.switcher.island.v1.';
    public const CTX_SHELL_BUILD = 'theme.chrome.shell_build';
    /** Request memo for selection-neutral options bag only — never stamped HTML (unique DOM ids). */
    public const CTX_OPTIONS_BAG = 'currency.switcher.island.options_bag';
    public const CTX_ASSETS_RENDERED = 'view.i18n.header_choice_assets_rendered';

    private const SELECTOR_PAGE_SIZE = 50;
    private const BAG_VERSION = 1;

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly LocalDescription $localDescription,
        private readonly CurrencyRateService $rateService,
    ) {
    }

    public static function cachePolicy(): CachePolicy
    {
        return new CachePolicy(
            resource: 'currency.switcher.island',
            pool: self::POOL,
            scope: 'website',
            vary: ['lang'],
            dependencies: ['config', 'global/i18n'],
            freshTtlSeconds: 3600,
            staleTtlSeconds: 86400,
            singleFlightWaitMs: 800,
        );
    }

    public static function isChromeShellBuild(): bool
    {
        try {
            return (bool)RequestContext::get(self::CTX_SHELL_BUILD);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function beginChromeShellBuild(): void
    {
        try {
            RequestContext::set(self::CTX_SHELL_BUILD, true);
        } catch (\Throwable) {
        }
    }

    public static function endChromeShellBuild(): void
    {
        try {
            RequestContext::remove(self::CTX_SHELL_BUILD);
        } catch (\Throwable) {
        }
    }

    /** Empty island for lang-shell chrome bags (hydrate fills later). */
    public static function pendingIslandHtml(): string
    {
        return '<weline-chrome-island data-weline-chrome-island="currency-switcher" style="display:contents">'
            . '<!--weline-chrome-island-pending:currency-switcher-->'
            . '</weline-chrome-island>';
    }

    public static function invalidate(): void
    {
        try {
            ObjectManager::getInstance(self::class)->invalidateBags();
        } catch (\Throwable) {
        }
    }

    public function invalidateBags(): void
    {
        $this->hotCache->purgeProcessCacheForLogicalKey(self::LOGICAL_KEY_PREFIX);
        try {
            $pool = \w_cache(self::POOL);
            if (\is_object($pool) && \method_exists($pool, 'clear')) {
                $pool->clear();
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Live island HTML for one mount point. Always stamps a fresh SwitcherInstanceId.
     * Expensive options bag is HotCache + request-memoized; stamped markup is not.
     */
    public function renderLiveIslandHtml(): string
    {
        if (self::isChromeShellBuild()) {
            return self::pendingIslandHtml();
        }

        $locale = $this->activeLocale();
        $bag = $this->rememberOptionsBag($locale);

        return $this->stampIslandHtml($bag, WelineEnv::getCurrency(), $locale);
    }

    /**
     * @return array{version:int,items:list<array{code:string,name:string,symbol:string,search:string}>}
     */
    public function rememberOptionsBag(string $locale): array
    {
        $locale = \trim($locale) !== '' ? \trim($locale) : 'zh_Hans_CN';

        try {
            $memo = RequestContext::get(self::CTX_OPTIONS_BAG);
            if (\is_array($memo)
                && ($memo['locale'] ?? null) === $locale
                && isset($memo['bag']['items'])
                && \is_array($memo['bag']['items'])
            ) {
                return $memo['bag'];
            }
        } catch (\Throwable) {
        }

        $logicalKey = $this->logicalKey($locale);
        $bag = $this->hotCache->rememberPolicy(
            self::cachePolicy(),
            $logicalKey,
            function () use ($locale): array {
                return $this->buildOptionsBag($locale);
            },
        );

        if (!\is_array($bag) || !isset($bag['items']) || !\is_array($bag['items'])) {
            $bag = $this->buildOptionsBag($locale);
        }

        try {
            RequestContext::set(self::CTX_OPTIONS_BAG, [
                'locale' => $locale,
                'bag' => $bag,
            ]);
        } catch (\Throwable) {
        }

        return $bag;
    }

    private function logicalKey(string $locale): string
    {
        $fp = $this->catalogFingerprint($locale);

        return self::LOGICAL_KEY_PREFIX
            . \strtolower(\str_replace('-', '_', $locale))
            . '.'
            . $fp;
    }

    private function catalogFingerprint(string $locale): string
    {
        try {
            $rows = CurrencyData::getCurrencies();
        } catch (\Throwable) {
            $rows = [];
        }
        $parts = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = \strtoupper(\trim((string)($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $parts[] = $code
                . ':'
                . (string)($row['rate'] ?? '')
                . ':'
                . (string)($row['status'] ?? '')
                . ':'
                . (string)($row['symbol'] ?? '')
                . ':'
                . (int)($row['currency_id'] ?? $row['id'] ?? 0);
        }
        \sort($parts, \SORT_STRING);

        return \substr(\sha1($locale . "\n" . \implode("\n", $parts)), 0, 16);
    }

    /**
     * @return array{version:int,items:list<array{code:string,name:string,symbol:string,search:string}>}
     */
    private function buildOptionsBag(string $locale): array
    {
        $base = $this->storefrontBaseCurrency();
        $rows = [];
        try {
            $currencies = CurrencyData::getCurrencies();
            if (\is_array($currencies)) {
                foreach ($currencies as $currency) {
                    if (!\is_array($currency)) {
                        continue;
                    }
                    $code = \strtoupper(\trim((string)($currency['code'] ?? '')));
                    if ($code === '') {
                        continue;
                    }
                    $rate = (float)($currency['rate'] ?? 0);
                    if (!$this->isConvertible($code, $rate, $base)) {
                        continue;
                    }
                    $rows[] = $currency;
                }
            }
        } catch (\Throwable) {
            $rows = [];
        }

        if ($rows === []) {
            $rows = [
                ['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'currency_id' => 0, 'rate' => 1.0],
                ['code' => 'USD', 'name' => '美元', 'symbol' => '$', 'currency_id' => 0, 'rate' => 1.0],
                ['code' => 'EUR', 'name' => '欧元', 'symbol' => '€', 'currency_id' => 0, 'rate' => 1.0],
                ['code' => 'GBP', 'name' => '英镑', 'symbol' => '£', 'currency_id' => 0, 'rate' => 1.0],
            ];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['currency_id'] ?? $row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $localNamesById = $this->batchLocalNames(\array_values($ids));

        $aliasLocales = $this->aliasLocales($locale);
        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            $code = \strtoupper(\trim((string)($row['code'] ?? '')));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            $currencyId = (int)($row['currency_id'] ?? $row['id'] ?? 0);
            $localByLocale = $localNamesById[$currencyId] ?? [];
            $localOverride = \trim((string)($localByLocale[$locale] ?? ''));
            $name = CurrencyDisplayName::forCode($code, $locale, $localOverride);
            if ($name === '') {
                $name = $this->normalizeCurrencyName($code, (string)($row['name'] ?? $code), $locale);
            }

            $symbol = \trim((string)($row['symbol'] ?? $row['icon'] ?? ''));
            if ($symbol === '' || \strcasecmp($symbol, $code) === 0) {
                $symbol = CurrencySymbol::forCode($code);
            }

            $aliases = [$code, $symbol, $name];
            foreach ($aliasLocales as $aliasLocale) {
                $aliases[] = CurrencyDisplayName::forCode($code, $aliasLocale);
            }
            foreach ($localByLocale as $localName) {
                $aliases[] = $localName;
            }

            $items[] = [
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'search' => $this->normalizeSearchText($aliases),
            ];
        }

        \usort(
            $items,
            static function (array $a, array $b) use ($base): int {
                $ac = (string)$a['code'];
                $bc = (string)$b['code'];
                if ($ac === $base && $bc !== $base) {
                    return -1;
                }
                if ($bc === $base && $ac !== $base) {
                    return 1;
                }

                return $ac <=> $bc;
            },
        );

        return [
            'version' => self::BAG_VERSION,
            'items' => $items,
        ];
    }

    /**
     * @param list<int> $currencyIds
     * @return array<int, array<string, string>>
     */
    private function batchLocalNames(array $currencyIds): array
    {
        if ($currencyIds === []) {
            return [];
        }

        try {
            $rows = $this->localDescription->clear()
                ->where(LocalDescription::schema_fields_ID, $currencyIds, 'in')
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[LocalDescription::schema_fields_ID] ?? 0);
            $locale = \trim((string)($row[LocalDescription::schema_fields_local_code] ?? ''));
            if ($id <= 0 || $locale === '') {
                continue;
            }
            $out[$id][$locale] = (string)($row[LocalDescription::schema_fields_name] ?? '');
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function aliasLocales(string $currentLocale): array
    {
        $out = [];
        foreach ([$currentLocale, 'zh_Hans_CN', 'en_US'] as $locale) {
            $locale = \trim($locale);
            if ($locale === '') {
                continue;
            }
            $out[\strtolower($locale)] = $locale;
        }

        return \array_values($out);
    }

    /**
     * @param array{version?:int,items?:list<array{code:string,name:string,symbol:string,search:string}>} $bag
     */
    private function stampIslandHtml(array $bag, string $currentCurrency, string $locale): string
    {
        $items = $bag['items'] ?? [];
        if (!\is_array($items) || $items === []) {
            $items = $this->buildOptionsBag($locale)['items'];
        }

        $current = \strtoupper(\trim($currentCurrency));
        if ($current === '') {
            $current = $this->storefrontBaseCurrency();
        }

        $currentItems = [];
        $otherItems = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = \strtoupper((string)($item['code'] ?? ''));
            if ($code === $current) {
                $currentItems[] = $item;
            } else {
                $otherItems[] = $item;
            }
        }
        $ordered = \array_merge($currentItems, $otherItems);

        $triggerSymbol = '';
        foreach ($ordered as $item) {
            if (\strtoupper((string)($item['code'] ?? '')) === $current) {
                $triggerSymbol = (string)($item['symbol'] ?? '');
                break;
            }
        }
        if ($triggerSymbol === '' || \strcasecmp($triggerSymbol, $current) === 0) {
            $triggerSymbol = CurrencySymbol::forCode($current);
        }
        $triggerLabel = $triggerSymbol !== '' ? $triggerSymbol : $current;

        $escape = static fn(mixed $value): string => \htmlspecialchars((string)$value, \ENT_QUOTES, 'UTF-8');
        $i18n = static function (string $source): string {
            if (\class_exists(\Weline\Theme\Helper\WidgetI18n::class)) {
                return \Weline\Theme\Helper\WidgetI18n::label($source);
            }

            return $source;
        };

        $menuId = SwitcherInstanceId::create('w-currency-switcher-menu');
        $chevron = $this->iconSvg('chevron-down', 'xs');
        $check = $this->iconSvg('check', 'sm');

        $optionsHtml = '';
        foreach ($ordered as $index => $item) {
            $code = \strtoupper((string)($item['code'] ?? ''));
            $name = (string)($item['name'] ?? $code);
            $symbol = (string)($item['symbol'] ?? '');
            $search = (string)($item['search'] ?? $code);
            $isCurrent = $code === $current;
            $symbolOut = $symbol !== '' ? $symbol : $code;
            $hidden = $index >= self::SELECTOR_PAGE_SIZE ? ' hidden' : '';
            $optionsHtml .= '<button type="button"'
                . ' class="w-menu__item w-currency-switcher__option"'
                . ' data-currency-option="true"'
                . ' data-w-choice-option'
                . ' data-currency="' . $escape($code) . '"'
                . ' data-currency-symbol="' . $escape($symbolOut) . '"'
                . ' data-search="' . $escape($search) . '"'
                . ' role="menuitem"'
                . ' aria-current="' . ($isCurrent ? 'true' : 'false') . '"'
                . $hidden
                . '>'
                . '<span class="w-currency-switcher__symbol" aria-hidden="true">' . $escape($symbolOut) . '</span>'
                . '<span class="w-currency-switcher__copy">'
                . '<strong>' . $escape($name) . '</strong>'
                . '<small>' . $escape($code) . '</small>'
                . '</span>'
                . '<span class="w-currency-switcher__check">' . $check . '</span>'
                . '</button>';
        }

        $moreHidden = \count($ordered) <= self::SELECTOR_PAGE_SIZE ? ' hidden' : '';
        $codeHidden = $triggerLabel === $current ? ' hidden' : '';

        $html = '<weline-chrome-island data-weline-chrome-island="currency-switcher" style="display:contents">'
            . '<div class="w-currency-switcher w-menu-root"'
            . ' data-currency-switcher="true"'
            . ' data-w-component="menu choice-filter"'
            . ' data-w-placement="bottom-end"'
            . ' data-w-choice-page-size="' . self::SELECTOR_PAGE_SIZE . '"'
            . ' data-weline-load="currency">'
            . '<button type="button" class="w-button w-currency-switcher__trigger"'
            . ' data-tone="quiet" data-size="sm" data-w-menu-trigger'
            . ' aria-controls="' . $escape($menuId) . '"'
            . ' aria-expanded="false"'
            . ' aria-label="' . $escape($i18n('切换货币') . ' · ' . $current) . '">'
            . '<span class="w-currency-switcher__current current-currency">' . $escape($triggerLabel) . '</span>'
            . '<span class="w-currency-switcher__code"' . $codeHidden . '>' . $escape($current) . '</span>'
            . $chevron
            . '</button>'
            . '<div class="w-menu w-currency-switcher__panel" id="' . $escape($menuId) . '"'
            . ' data-w-menu-panel data-w-choice-panel role="menu"'
            . ' aria-label="' . $escape($i18n('货币选项')) . '" hidden>'
            . '<label class="w-field w-currency-switcher__search">'
            . '<span class="w-visually-hidden">' . $escape($i18n('搜索货币')) . '</span>'
            . '<input class="w-input" type="search" data-w-choice-search'
            . ' placeholder="' . $escape($i18n('搜索货币、货币码、符号或翻译')) . '" autocomplete="off">'
            . '</label>'
            . '<div class="w-currency-switcher__items" data-w-choice-items>'
            . $optionsHtml
            . '</div>'
            . '<div class="w-currency-switcher__empty" data-w-choice-empty hidden>'
            . $escape($i18n('无匹配货币'))
            . '</div>'
            . '<button type="button" class="w-button w-currency-switcher__more"'
            . ' data-tone="neutral" data-size="sm" data-w-choice-more' . $moreHidden . '>'
            . $escape($i18n('加载更多'))
            . '</button>'
            . '</div>'
            . '</div>'
            . $this->choiceAssetsHtml()
            . '</weline-chrome-island>';

        return $html;
    }

    private function choiceAssetsHtml(): string
    {
        try {
            if (RequestContext::get(self::CTX_ASSETS_RENDERED)) {
                return '';
            }
            RequestContext::set(self::CTX_ASSETS_RENDERED, true);
        } catch (\Throwable) {
            return '';
        }

        try {
            $template = ObjectManager::getInstance(\Weline\Framework\View\Template::class);
            if (\method_exists($template, 'fetch')) {
                return (string)$template->fetchHtml('Weline_I18n::templates/frontend/header-choice-selector-assets.phtml');
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function iconSvg(string $name, string $size): string
    {
        $paths = [
            'chevron-down' => '<polyline points="6 9 12 15 18 9"></polyline>',
            'check' => '<polyline points="20 6 9 17 4 12"></polyline>',
        ];
        $body = $paths[$name] ?? '';
        if ($body === '') {
            return '';
        }

        return '<svg class="w-icon w-icon--' . \htmlspecialchars($size, \ENT_QUOTES, 'UTF-8')
            . '" data-icon="' . \htmlspecialchars($name, \ENT_QUOTES, 'UTF-8')
            . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true">'
            . $body
            . '</svg>';
    }

    private function activeLocale(): string
    {
        $locale = \trim(WelineEnv::getLang());

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    private function storefrontBaseCurrency(): string
    {
        try {
            $base = \strtoupper(\trim($this->rateService->getBaseCurrency()));

            return $base !== '' ? $base : 'CNY';
        } catch (\Throwable) {
            return 'CNY';
        }
    }

    private function isConvertible(string $currencyCode, float $rate, string $base): bool
    {
        if ($currencyCode === '') {
            return false;
        }
        if ($currencyCode === $base) {
            return true;
        }

        return $rate > 0.0;
    }

    private function normalizeCurrencyName(string $currencyCode, string $currencyName, string $locale): string
    {
        $currencyCode = \strtoupper(\trim($currencyCode));
        $currencyName = \trim($currencyName);
        if ($currencyCode !== '' && ($currencyName === '' || \strtoupper($currencyName) === $currencyCode)) {
            if (CurrencyDisplayName::isChineseFamily($locale)) {
                return match ($currencyCode) {
                    'CNY' => '人民币',
                    'USD' => '美元',
                    'EUR' => '欧元',
                    'GBP' => '英镑',
                    default => $currencyName !== '' ? $currencyName : $currencyCode,
                };
            }

            return $currencyName !== '' ? $currencyName : $currencyCode;
        }

        return $currencyName;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function normalizeSearchText(array $tokens): string
    {
        $normalized = [];
        foreach ($tokens as $token) {
            $token = \trim(\strip_tags((string)$token));
            if ($token === '') {
                continue;
            }
            $key = \function_exists('mb_strtolower') ? \mb_strtolower($token, 'UTF-8') : \strtolower($token);
            $normalized[$key] = $token;
        }

        return \implode(' ', \array_values($normalized));
    }

    /**
     * Searchable currency list for the unsupported-path dialog.
     * Options are the website catalog already resolved on this request.
     * Each choice is a path href; the click does not ask the server for another catalog.
     */
    public function renderDialogColumnHtml(): string
    {
        $notice = ObjectManager::getInstance(UnsupportedPathCurrencyNotice::class);
        if (!$notice->isActive()) {
            return '';
        }
        $locale = $this->activeLocale();
        $bag = $this->rememberOptionsBag($locale);
        $items = \is_array($bag['items'] ?? null) ? $bag['items'] : [];
        $rejected = $notice->rejectedCode();
        $uri = $this->visitorUri();
        $safe = static fn (string $value): string => \htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $placeholder = \class_exists(\Weline\Theme\Helper\WidgetI18n::class)
            ? \Weline\Theme\Helper\WidgetI18n::label('搜索货币')
            : (string) __('搜索货币');
        $html = '<div class="w-currency-switcher" data-currency-switcher data-currency-surface="dialog">'
            . '<div class="w-currency-switcher__menu" role="menu" aria-label="' . $safe($placeholder) . '">'
            . '<div class="w-language-switcher__search-wrap">'
            . '<input class="w-input" type="search" placeholder="' . $safe($placeholder) . '"'
            . ' autocomplete="off" data-w-choice-search aria-label="' . $safe($placeholder) . '">'
            . '</div><div class="w-language-switcher__list">';
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = \strtoupper(\trim((string)($item['code'] ?? '')));
            if ($code === '' || $code === $rejected) {
                continue;
            }
            $name = \trim((string)($item['name'] ?? $code));
            $symbol = \trim((string)($item['symbol'] ?? ''));
            $search = \trim((string)($item['search'] ?? ($code . ' ' . $name)));
            $href = UnsupportedPathCurrencyNotice::replaceCurrencySegment($uri, $rejected, $code);
            $html .= '<a class="w-menu__item w-language-switcher__option" role="menuitem" href="'
                . $safe($href) . '" data-unsupported-currency="' . $safe($code) . '"'
                . ' data-w-search="' . $safe($search) . '">'
                . '<span class="w-language-switcher__copy"><strong>' . $safe($name) . '</strong>'
                . '<small>' . $safe($symbol !== '' ? $code . ' ' . $symbol : $code) . '</small></span></a>';
        }
        $html .= '</div></div></div>';

        return $html;
    }

    private function visitorUri(): string
    {
        $uri = \trim((string)WelineEnv::server('WELINE_VISITOR_REQUEST_URI', ''));
        if ($uri === '') {
            $uri = \trim((string)WelineEnv::server('REQUEST_URI', '/'));
        }
        if ($uri === '') {
            return '/';
        }
        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: $uri);
        $query = (string)(\parse_url($uri, \PHP_URL_QUERY) ?: '');

        return $query !== '' ? $path . '?' . $query : $path;
    }
}
