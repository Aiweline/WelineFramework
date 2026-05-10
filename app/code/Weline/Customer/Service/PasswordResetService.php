<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Customer\Model\PasswordResetToken;

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

        w_query('smtp', 'send', [
            'module' => 'Weline_Customer',
            'to' => $email,
            'subject' => (string) __('Reset your password'),
            'content' => sprintf(
                '<p>%s</p><p><a href="%s">%s</a></p>',
                __('Click the link below to reset your password.'),
                htmlspecialchars($resetUrl . (str_contains($resetUrl, '?') ? '&' : '?') . 'token=' . $token, ENT_QUOTES),
                __('Reset Password')
            ),
        ]);

        return true;
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
