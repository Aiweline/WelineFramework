<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\View\Template;
use Weline\I18n\Model\LanguageSupportRequest;
use Weline\I18n\Model\LanguageSupportRequestItem;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\SystemConfig\Api\ConfigReader;

final class LanguageSupportRequestService
{
    private const MAX_LOCALES = 20;
    private const DUPLICATE_WINDOW_SECONDS = 86400;
    private const RATE_WINDOW_SECONDS = 3600;
    private const RATE_MAX_REQUESTS = 5;

    public function __construct(
        private readonly LanguageSupportRequest $requests,
        private readonly LanguageSupportRequestItem $items,
        private readonly SessionFactory $sessions,
        private readonly Request $request,
        private readonly Template $template,
        private readonly CaptchaManagerInterface $captcha,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly ConfigReader $config,
    ) {
    }

    public function enabled(): bool
    {
        $value = $this->config->get(
            'i18n/language_request/enabled',
            'Weline_I18n',
            ConfigReader::area_FRONTEND,
            true,
        );
        return \is_bool($value)
            ? $value
            : \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    public function renderForm(): array
    {
        if (!$this->enabled()) {
            throw new \RuntimeException((string)__('当前站点未开放语言支持申请'));
        }

        $profile = $this->customerProfile();
        [$prefillFirst, $prefillLast] = $this->splitDisplayName($profile['name']);
        $html = (string)$this->template->fetchModuleThemeHtml(
            'Weline_I18n::templates/Frontend/language-support-request.phtml',
            [
                'form_id' => 'weline-language-request-' . \bin2hex(\random_bytes(6)),
                'prefill_first_name' => $prefillFirst,
                'prefill_last_name' => $prefillLast,
                'prefill_email' => $profile['email'],
                'max_locales' => self::MAX_LOCALES,
            ]
        );

        return ['success' => true, 'html' => $html, 'loaded_at' => \time()];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    public function submit(array $params): array
    {
        if (!$this->enabled()) {
            throw new \RuntimeException((string)__('当前站点未开放语言支持申请'));
        }

        $websiteId = RequestContext::getWelineWebsiteId();
        $hostname = $this->hostname();
        $ip = (string)$this->request->clientIP();

        $name = $this->resolveApplicantName($params);
        $email = \strtolower(\trim((string)($params['email'] ?? '')));
        if (\filter_var($email, FILTER_VALIDATE_EMAIL) === false || \strlen($email) > 190) {
            throw new \InvalidArgumentException((string)__('请输入有效的邮箱地址'));
        }

        $requestedLocales = $this->normalizeRequestedLocales($params['locales'] ?? []);
        if ($requestedLocales === []) {
            throw new \InvalidArgumentException((string)__('请至少选择一种希望支持的语言'));
        }
        if (\count($requestedLocales) > self::MAX_LOCALES) {
            throw new \InvalidArgumentException((string)__('每次最多可申请 %{1} 种语言', [self::MAX_LOCALES]));
        }
        $supported = \array_fill_keys($this->supportedWebsiteLocales(), true);
        $requestedLocales = \array_values(\array_filter(
            $requestedLocales,
            static fn(string $locale): bool => !isset($supported[$locale])
        ));
        if ($requestedLocales === []) {
            throw new \InvalidArgumentException((string)__('所选语言已由当前站点支持，无需重复申请'));
        }

        $profile = $this->customerProfile();
        $customerId = (int)$profile['customer_id'];
        $ipHash = \hash('sha256', $ip . '|' . (string)\w_env('app.key', 'weline'));
        $this->assertRateLimit($ipHash);
        if (!$this->captcha->verifySubmission($params, 'i18n.language_support_request', $hostname, $ip)) {
            throw new \RuntimeException((string)__('人机验证失败或已过期，请重试'));
        }
        $duplicates = $this->findRecentDuplicateLocales($websiteId, $customerId, $email, $requestedLocales);
        $duplicateLocales = \array_keys($duplicates);
        $requestedLocales = \array_values(\array_diff($requestedLocales, $duplicateLocales));
        if ($requestedLocales === []) {
            $duplicate = (string)(\reset($duplicates) ?: '');
            return [
                'success' => true,
                'duplicate' => true,
                'public_id' => $duplicate,
                'locales' => $duplicateLocales,
                'message' => (string)__('相同语言申请已提交，无需重复发送'),
            ];
        }

        $catalog = [];
        foreach (LanguageSelect::getLanguageItems('en', 'global') as $item) {
            $catalog[(string)$item['code']] = $item;
        }

        return $this->transactions->runWrite(
            $this->requests->getConnection(),
            function () use ($websiteId, $customerId, $name, $email, $hostname, $ipHash, $requestedLocales, $duplicateLocales, $catalog): array {
                $publicId = 'LR-' . \date('Ymd') . '-' . \strtoupper(\substr(\bin2hex(\random_bytes(8)), 0, 12));
                $now = \date('Y-m-d H:i:s');
                $request = clone $this->requests;
                $request->clearData()
                    ->setData(LanguageSupportRequest::schema_fields_PUBLIC_ID, $publicId)
                    ->setData(LanguageSupportRequest::schema_fields_WEBSITE_ID, $websiteId)
                    ->setData(LanguageSupportRequest::schema_fields_CUSTOMER_ID, $customerId > 0 ? $customerId : null)
                    ->setData(LanguageSupportRequest::schema_fields_NAME, $name)
                    ->setData(LanguageSupportRequest::schema_fields_EMAIL, $email)
                    ->setData(LanguageSupportRequest::schema_fields_SOURCE_DOMAIN, $hostname)
                    ->setData(LanguageSupportRequest::schema_fields_IP_HASH, $ipHash)
                    ->setData(LanguageSupportRequest::schema_fields_CREATED_AT, $now)
                    ->setData(LanguageSupportRequest::schema_fields_UPDATED_AT, $now)
                    ->save();
                $requestId = (int)$request->getId();
                if ($requestId <= 0) {
                    throw new \RuntimeException((string)__('语言申请保存失败'));
                }

                $rows = [];
                foreach ($requestedLocales as $locale) {
                    $rows[] = [
                        LanguageSupportRequestItem::schema_fields_REQUEST_ID => $requestId,
                        LanguageSupportRequestItem::schema_fields_WEBSITE_ID => $websiteId,
                        LanguageSupportRequestItem::schema_fields_LOCALE => $locale,
                        LanguageSupportRequestItem::schema_fields_COUNTRY => (string)($catalog[$locale]['country_code'] ?? ''),
                        LanguageSupportRequestItem::schema_fields_STATUS => LanguageSupportRequestItem::STATUS_PENDING,
                        LanguageSupportRequestItem::schema_fields_CREATED_AT => $now,
                        LanguageSupportRequestItem::schema_fields_UPDATED_AT => $now,
                    ];
                }
                $item = clone $this->items;
                $item->clearData(true)->clearQuery()->insert($rows)->fetch();

                return [
                    'success' => true,
                    'duplicate' => false,
                    'public_id' => $publicId,
                    'locales' => $requestedLocales,
                    'skipped_duplicate_locales' => $duplicateLocales,
                    'message' => (string)__('语言支持申请已提交，申请编号：%{1}', [$publicId]),
                ];
            }
        );
    }

    /** @return array{customer_id:int,name:string,email:string} */
    private function customerProfile(): array
    {
        $session = $this->sessions->createFrontendSession();
        $session->start();
        $user = $session->getUser();
        if (!$session->isLoggedIn() || $user === null || !$user->getAuthIdentifier()) {
            return ['customer_id' => 0, 'name' => '', 'email' => ''];
        }
        $email = \trim((string)$user->getData('email'));
        $name = \trim((string)($user->getData('name') ?: $user->getData('username')));
        if ($name === '' && $email !== '') {
            $name = (string)\strstr($email, '@', true);
        }
        return ['customer_id' => (int)$user->getAuthIdentifier(), 'name' => $name, 'email' => $email];
    }

    /**
     * Western order: given name + family name. Still accepts legacy combined `name`.
     *
     * @param array<string, mixed> $params
     */
    private function resolveApplicantName(array $params): string
    {
        $first = \trim((string)($params['first_name'] ?? $params['given_name'] ?? ''));
        $last = \trim((string)($params['last_name'] ?? $params['family_name'] ?? ''));
        if ($first !== '' || $last !== '') {
            if ($first === '' || $last === '') {
                throw new \InvalidArgumentException((string)__('请填写名和姓'));
            }
            if (\mb_strlen($first) > 60 || \mb_strlen($last) > 60) {
                throw new \InvalidArgumentException((string)__('名或姓长度不能超过 60 个字符'));
            }
            $name = \trim($first . ' ' . $last);
        } else {
            $name = \trim((string)($params['name'] ?? ''));
        }
        if (\mb_strlen($name) < 2 || \mb_strlen($name) > 120) {
            throw new \InvalidArgumentException((string)__('姓名长度必须在 2 到 120 个字符之间'));
        }

        return $name;
    }

    /** @return array{0:string,1:string} */
    private function splitDisplayName(string $name): array
    {
        $normalized = \trim((string)\preg_replace('/\s+/u', ' ', $name));
        if ($normalized === '') {
            return ['', ''];
        }
        $parts = \explode(' ', $normalized, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /** @param mixed $raw
     *  @return list<string>
     */
    private function normalizeRequestedLocales(mixed $raw): array
    {
        $values = \is_array($raw) ? $raw : \preg_split('/[\s,]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
        $global = [];
        foreach (LanguageSelect::getLanguageItems('en', 'global') as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code !== '') {
                $global[\strtolower($code)] = $code;
            }
        }
        $locales = [];
        foreach ((array)$values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $key = \strtolower(\str_replace('-', '_', \trim((string)$value)));
            if (isset($global[$key])) {
                $locales[$global[$key]] = true;
            }
        }
        return \array_keys($locales);
    }

    /** @return list<string> */
    private function supportedWebsiteLocales(): array
    {
        try {
            $result = \w_query('websites', 'getWebsiteLanguageCodes', [
                'website_id' => RequestContext::getWelineWebsiteId(),
            ]);
            $codes = \is_array($result) ? ($result['languages'] ?? $result['data'] ?? $result) : [];
            if (\is_array($codes)) {
                return \array_values(\array_unique(\array_filter(\array_map('strval', $codes))));
            }
        } catch (\Throwable) {
        }
        return [];
    }

    private function assertRateLimit(string $ipHash): void
    {
        $count = (clone $this->requests)->clearData()->clearQuery()
            ->where(LanguageSupportRequest::schema_fields_IP_HASH, $ipHash)
            ->where(LanguageSupportRequest::schema_fields_CREATED_AT, \date('Y-m-d H:i:s', \time() - self::RATE_WINDOW_SECONDS), '>=')
            ->count();
        if ((int)$count >= self::RATE_MAX_REQUESTS) {
            throw new \RuntimeException((string)__('提交过于频繁，请稍后再试'));
        }
    }

    /**
     * @param list<string> $locales
     * @return array<string, string> locale => public request id
     */
    private function findRecentDuplicateLocales(int $websiteId, int $customerId, string $email, array $locales): array
    {
        $query = (clone $this->requests)->clearData()->clearQuery()
            ->where(LanguageSupportRequest::schema_fields_WEBSITE_ID, $websiteId)
            ->where(LanguageSupportRequest::schema_fields_CREATED_AT, \date('Y-m-d H:i:s', \time() - self::DUPLICATE_WINDOW_SECONDS), '>=');
        if ($customerId > 0) {
            $query->where(LanguageSupportRequest::schema_fields_CUSTOMER_ID, $customerId);
        } else {
            $query->where(LanguageSupportRequest::schema_fields_EMAIL, $email);
        }
        $headers = $query->order(LanguageSupportRequest::schema_fields_ID, 'DESC')->select()->fetchArray();
        $remaining = \array_fill_keys($locales, true);
        $duplicates = [];
        foreach ((array)$headers as $header) {
            $requestId = (int)($header[LanguageSupportRequest::schema_fields_ID] ?? 0);
            if ($requestId <= 0) {
                continue;
            }
            $rows = (clone $this->items)->clearData()->clearQuery()
                ->where(LanguageSupportRequestItem::schema_fields_REQUEST_ID, $requestId)
                ->select()
                ->fetchArray();
            foreach ((array)$rows as $row) {
                $locale = (string)($row[LanguageSupportRequestItem::schema_fields_LOCALE] ?? '');
                if ($locale === '' || !isset($remaining[$locale])) {
                    continue;
                }
                $duplicates[$locale] = (string)($header[LanguageSupportRequest::schema_fields_PUBLIC_ID] ?? '');
                unset($remaining[$locale]);
            }
            if ($remaining === []) {
                break;
            }
        }
        return $duplicates;
    }

    private function hostname(): string
    {
        $raw = \strtolower(\trim((string)$this->request->getHeader('Host')));
        if ($raw === '' || \strlen($raw) > 255 || \preg_match('/[\x00-\x20\x7F\/\\\\]/', $raw) === 1) {
            return 'localhost';
        }
        $host = \parse_url('//' . $raw, PHP_URL_HOST);
        if (!\is_string($host) || $host === '' || \strlen($host) > 190) {
            return 'localhost';
        }
        if (\filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        return \preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) === 1
            ? $host
            : 'localhost';
    }
}
