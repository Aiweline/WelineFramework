<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\PasswordResetToken;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

class PasswordResetService
{
    public function __construct(
        private readonly CustomerAccountService $customerAccountService,
        private readonly PasswordResetToken $passwordResetToken
    ) {
    }

    public function requestReset(string $email, string $resetUrl): bool
    {
        $email = $this->customerAccountService->normalizeEmail($email);
        $customer = $this->customerAccountService->findByEmail($email);
        if (!$customer) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + 3600;

        $this->passwordResetToken->reset()
            ->where(PasswordResetToken::schema_fields_USER_ID, (int) $customer->getId())
            ->delete()
            ->fetch();

        $this->passwordResetToken->reset()
            ->clearData()
            ->setData(PasswordResetToken::schema_fields_USER_ID, (int) $customer->getId())
            ->setData(PasswordResetToken::schema_fields_EMAIL, $email)
            ->setData(PasswordResetToken::schema_fields_TOKEN, $token)
            ->setData(PasswordResetToken::schema_fields_EXPIRES_AT, $expiresAt)
            ->save();

        $tokenUrl = $resetUrl . (str_contains($resetUrl, '?') ? '&' : '?') . 'token=' . $token;
        $params = [
            'module' => 'Weline_Customer',
            'channel' => 'Weline_Customer::password_reset',
            'to' => $email,
            'vars' => [
                'reset_url' => $tokenUrl,
                'customer_email' => $email,
            ],
        ];
        $scope = $this->resolveSendScope($customer);
        if ($scope['website_code'] !== '') {
            $params['website_code'] = $scope['website_code'];
        }
        if ($scope['locale'] !== '') {
            $params['locale'] = $scope['locale'];
        }
        w_query('smtp', 'send', $params);

        return true;
    }

    /**
     * Prefer RequestContext website/locale; otherwise customer website when available.
     *
     * @return array{website_code:string,locale:string}
     */
    private function resolveSendScope(object $customer): array
    {
        $websiteCode = '';
        $locale = '';
        try {
            $identity = RequestContext::scopeIdentity();
            if ($identity instanceof ScopeIdentity && !$identity->isGlobal()) {
                $websiteCode = trim((string)($identity->websiteCode ?? ''));
            }
            $lang = trim((string)RequestContext::getWelineUserLang());
            if ($lang !== '' && $lang !== 'default') {
                $locale = $lang;
            }
        } catch (\Throwable) {
        }

        if ($websiteCode === '') {
            try {
                if (method_exists($customer, 'getData')) {
                    $code = trim((string)$customer->getData('website_code'));
                    if ($code !== '') {
                        $websiteCode = $code;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return ['website_code' => $websiteCode, 'locale' => $locale];
    }

    public function validateToken(string $token): ?PasswordResetToken
    {
        /** @var PasswordResetToken $record */
        $record = $this->passwordResetToken->reset()
            ->where(PasswordResetToken::schema_fields_TOKEN, trim($token))
            ->find()
            ->fetch();

        if (!$record->getId() || $record->isExpired() || $record->isUsed()) {
            return null;
        }

        return $record;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $record = $this->validateToken($token);
        if (!$record) {
            return false;
        }

        $this->customerAccountService->validatePasswordStrength($newPassword);
        $email = (string) $record->getData(PasswordResetToken::schema_fields_EMAIL);
        $customer = $this->customerAccountService->findByEmail($email);
        if (!$customer) {
            return false;
        }

        $customer->setPassword($newPassword)->save();
        $record->setData(PasswordResetToken::schema_fields_USED_AT, date('Y-m-d H:i:s'))->save();

        return true;
    }
}
