<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Service;

use WeShop\GoogleAuth\Model\GoogleBinding;

class GoogleBindingService
{
    public function __construct(
        private readonly GoogleBinding $googleBinding
    ) {
    }

    public function getBinding(string $area, int $localUserId): ?GoogleBinding
    {
        $area = $this->normalizeArea($area);
        if ($localUserId <= 0) {
            return null;
        }

        $binding = $this->googleBinding->reset()
            ->where(GoogleBinding::schema_fields_AREA, $area)
            ->where(GoogleBinding::schema_fields_LOCAL_USER_ID, $localUserId)
            ->find()
            ->fetch();

        return $binding->getId() ? $binding : null;
    }

    public function getByGoogleSubject(string $area, string $googleSubject): ?GoogleBinding
    {
        $area = $this->normalizeArea($area);
        $googleSubject = trim($googleSubject);
        if ($googleSubject === '') {
            return null;
        }

        $binding = $this->googleBinding->reset()
            ->where(GoogleBinding::schema_fields_AREA, $area)
            ->where(GoogleBinding::schema_fields_GOOGLE_SUBJECT, $googleSubject)
            ->find()
            ->fetch();

        return $binding->getId() ? $binding : null;
    }

    public function bind(string $area, int $localUserId, string $googleSubject, string $email): GoogleBinding
    {
        $area = $this->normalizeArea($area);
        $googleSubject = trim($googleSubject);
        $email = strtolower(trim($email));

        if ($localUserId <= 0 || $googleSubject === '' || $email === '') {
            throw new \InvalidArgumentException((string) __('Google binding data is incomplete.'));
        }

        $subjectBinding = $this->getByGoogleSubject($area, $googleSubject);
        if (
            $subjectBinding
            && (int) $subjectBinding->getData(GoogleBinding::schema_fields_LOCAL_USER_ID) !== $localUserId
        ) {
            throw new \RuntimeException((string) __('This Google account is already bound to another user.'));
        }

        $binding = $this->getBinding($area, $localUserId) ?? $subjectBinding ?? $this->googleBinding->reset()->clearData();
        $now = date('Y-m-d H:i:s');

        $binding->setData(GoogleBinding::schema_fields_AREA, $area)
            ->setData(GoogleBinding::schema_fields_LOCAL_USER_ID, $localUserId)
            ->setData(GoogleBinding::schema_fields_GOOGLE_SUBJECT, $googleSubject)
            ->setData(GoogleBinding::schema_fields_EMAIL, $email)
            ->setData(GoogleBinding::schema_fields_BOUND_AT, $binding->getId() ? ($binding->getData(GoogleBinding::schema_fields_BOUND_AT) ?: $now) : $now)
            ->setData(GoogleBinding::schema_fields_UPDATED_AT, $now);

        if (!$binding->getId()) {
            $binding->setData(GoogleBinding::schema_fields_CREATED_AT, $now);
        }

        $binding->save();

        return $binding;
    }

    public function touchLastLogin(GoogleBinding $binding): void
    {
        if (!$binding->getId()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $binding->setData(GoogleBinding::schema_fields_LAST_LOGIN_AT, $now)
            ->setData(GoogleBinding::schema_fields_UPDATED_AT, $now)
            ->save();
    }

    public function unbind(string $area, int $localUserId): bool
    {
        $binding = $this->getBinding($area, $localUserId);
        if (!$binding) {
            return false;
        }

        $binding->delete();
        return true;
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        if (!in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException((string) __('Unsupported Google auth area: %{1}', [$area]));
        }

        return $area;
    }
}
