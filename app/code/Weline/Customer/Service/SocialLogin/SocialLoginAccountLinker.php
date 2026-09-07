<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Api\Auth\CustomerIdentity;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\SocialLoginBinding;
use Weline\Customer\Service\CustomerLocalCredentialService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Bind social identity to a local customer and return the identity for login.
 */
final class SocialLoginAccountLinker
{
    public function __construct(
        private readonly CustomerAccountFacadeInterface $accounts,
        private readonly SocialLoginProviderCatalog $catalog,
    ) {
    }

    /**
     * @param array{provider:string,subject:string,email?:string,display_name?:string,avatar_url?:string} $profile
     */
    public function findBoundIdentity(array $profile): ?CustomerIdentity
    {
        $provider = strtolower(trim((string) ($profile['provider'] ?? '')));
        $subject = trim((string) ($profile['subject'] ?? ''));
        if ($provider === '' || $subject === '') {
            return null;
        }

        $binding = $this->findBinding($provider, $subject);
        if ($binding === null) {
            return null;
        }

        $identity = $this->accounts->find($binding->getCustomerId());
        if ($identity === null) {
            return null;
        }

        $this->touchBinding(
            $binding,
            strtolower(trim((string) ($profile['email'] ?? ''))),
            trim((string) ($profile['display_name'] ?? '')),
            trim((string) ($profile['avatar_url'] ?? ''))
        );

        $avatarUrl = trim((string) ($profile['avatar_url'] ?? ''));
        if ($avatarUrl !== '') {
            $identity = $this->accounts->updateAvatar($identity, $avatarUrl);
        }

        return $identity;
    }

    /**
     * @param array{provider:string,subject:string,email:string,display_name:string,avatar_url:string} $profile
     */
    public function createFromProfile(array $profile): CustomerIdentity
    {
        $provider = strtolower(trim((string) ($profile['provider'] ?? '')));
        $subject = trim((string) ($profile['subject'] ?? ''));
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $displayName = trim((string) ($profile['display_name'] ?? ''));
        $avatarUrl = trim((string) ($profile['avatar_url'] ?? ''));

        if ($provider === '' || $subject === '') {
            throw new \InvalidArgumentException((string) __('社媒身份缺少提供方或主体标识'));
        }

        $existing = $this->findBoundIdentity($profile);
        if ($existing !== null) {
            return $existing;
        }

        if ($email === '' || str_ends_with($email, '@social-login.local')) {
            $email = $provider . '_' . $subject . '@social-login.local';
        }

        $byEmail = $this->accounts->findByEmail($email);
        if ($byEmail !== null && !str_ends_with($email, '@social-login.local')) {
            throw new \RuntimeException((string) __('该邮箱已有账户，请选择「绑定已有账户」'));
        }

        $identity = $this->accounts->register($email, 'So' . bin2hex(random_bytes(12)) . '9', [
            'social_provider' => $provider,
            'social_subject' => $subject,
            'first_name' => $displayName,
        ]);

        if ($avatarUrl !== '') {
            $identity = $this->accounts->updateAvatar($identity, $avatarUrl);
        }

        $this->upsertBinding($provider, $subject, $identity->getId(), $email, $displayName, $avatarUrl);

        return $identity;
    }

    /**
     * @param array{provider:string,subject:string,email?:string,display_name?:string,avatar_url?:string} $profile
     */
    public function bindProfileToCustomer(array $profile, int $customerId): CustomerIdentity
    {
        $provider = strtolower(trim((string) ($profile['provider'] ?? '')));
        $subject = trim((string) ($profile['subject'] ?? ''));
        $email = strtolower(trim((string) ($profile['email'] ?? '')));
        $displayName = trim((string) ($profile['display_name'] ?? ''));
        $avatarUrl = trim((string) ($profile['avatar_url'] ?? ''));

        if ($provider === '' || $subject === '') {
            throw new \InvalidArgumentException((string) __('社媒身份缺少提供方或主体标识'));
        }
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('无效的顾客账户'));
        }

        $identity = $this->accounts->find($customerId);
        if ($identity === null) {
            throw new \RuntimeException((string) __('顾客账户不存在'));
        }

        $binding = $this->findBinding($provider, $subject);
        if ($binding !== null && $binding->getCustomerId() !== $customerId) {
            throw new \RuntimeException((string) __('该社媒账户已绑定其他顾客'));
        }

        $owned = $this->findCustomerProviderBinding($customerId, $provider);
        if ($owned !== null && $owned->getSubject() !== $subject) {
            throw new \RuntimeException((string) __('当前账户已绑定其他 %{1} 账号，请先解绑', [ucfirst($provider)]));
        }

        if ($avatarUrl !== '') {
            $identity = $this->accounts->updateAvatar($identity, $avatarUrl);
        }

        $this->upsertBinding($provider, $subject, $customerId, $email, $displayName, $avatarUrl);

        return $identity;
    }

    public function authenticateLocal(string $usernameOrEmail, string $password): CustomerIdentity
    {
        $credentials = ObjectManager::getInstance(CustomerLocalCredentialService::class);
        $user = $credentials->authenticate($usernameOrEmail, $password);

        $identity = $this->accounts->find((int) $user->getId());
        if ($identity === null) {
            throw new \RuntimeException((string) __('顾客账户不存在'));
        }

        return $identity;
    }

    public function unbind(int $customerId, string $provider): void
    {
        $provider = strtolower(trim($provider));
        if ($customerId <= 0 || $provider === '') {
            throw new \InvalidArgumentException((string) __('无法解绑社媒账户'));
        }
        if (!$this->catalog->isKnown($provider)) {
            throw new \InvalidArgumentException((string) __('不支持的社媒登录提供方'));
        }

        $binding = $this->findCustomerProviderBinding($customerId, $provider);
        if ($binding === null) {
            throw new \RuntimeException((string) __('当前账户未绑定该社媒提供方'));
        }
        $binding->delete();
    }

    /**
     * @return list<array{
     *   code:string,label:string,bound:bool,subject:string,email:string,display_name:string,avatar_url:string,
     *   configured:bool,enabled:bool,active:bool,guide_route:string,policy_route:string,
     *   icon_svg:string,brand_class:string
     * }>
     */
    public function listProviderStatusForCustomer(int $customerId, SocialLoginConfig $config): array
    {
        $rows = [];
        foreach ($this->catalog->codes() as $code) {
            $definition = $this->catalog->definition($code);
            if ($definition === null) {
                continue;
            }
            $binding = $customerId > 0 ? $this->findCustomerProviderBinding($customerId, $code) : null;
            $configured = $config->isConfigured($code);
            $enabled = $config->isEnabled($code);
            $active = $configured && $enabled;
            // Hide inactive unbound providers; keep bound rows so customers can still unlink.
            if ($binding === null && !$active) {
                continue;
            }
            $rows[] = [
                'code' => $code,
                'label' => $definition['label'],
                'bound' => $binding !== null,
                'subject' => $binding?->getSubject() ?? '',
                'email' => $binding !== null ? (string) $binding->getData(SocialLoginBinding::schema_fields_EMAIL) : '',
                'display_name' => $binding !== null ? (string) $binding->getData(SocialLoginBinding::schema_fields_DISPLAY_NAME) : '',
                'avatar_url' => $binding !== null ? (string) $binding->getData(SocialLoginBinding::schema_fields_AVATAR_URL) : '',
                'configured' => $configured,
                'enabled' => $enabled,
                'active' => $active,
                'guide_route' => 'guide/social-login/' . $code,
                'policy_route' => 'guide/social-login/' . $code . '/policy',
                'icon_svg' => (string) ($definition['icon_svg'] ?? ''),
                'brand_class' => (string) ($definition['brand_class'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @deprecated Use findBoundIdentity / createFromProfile / bindProfileToCustomer
     * @param array{provider:string,subject:string,email:string,display_name:string,avatar_url:string} $profile
     */
    public function resolveOrCreate(array $profile): CustomerIdentity
    {
        $bound = $this->findBoundIdentity($profile);
        if ($bound !== null) {
            return $bound;
        }

        return $this->createFromProfile($profile);
    }

    private function findBinding(string $provider, string $subject): ?SocialLoginBinding
    {
        $model = $this->newBinding();
        $row = $model->reset()
            ->where(SocialLoginBinding::schema_fields_PROVIDER_CODE, $provider)
            ->where(SocialLoginBinding::schema_fields_SUBJECT, $subject)
            ->find()
            ->fetch();

        return $row->getId() ? $row : null;
    }

    private function findCustomerProviderBinding(int $customerId, string $provider): ?SocialLoginBinding
    {
        $model = $this->newBinding();
        $row = $model->reset()
            ->where(SocialLoginBinding::schema_fields_CUSTOMER_ID, $customerId)
            ->where(SocialLoginBinding::schema_fields_PROVIDER_CODE, $provider)
            ->find()
            ->fetch();

        return $row->getId() ? $row : null;
    }

    private function touchBinding(
        SocialLoginBinding $binding,
        string $email,
        string $displayName,
        string $avatarUrl
    ): void {
        $now = date('Y-m-d H:i:s');
        if ($email !== '') {
            $binding->setData(SocialLoginBinding::schema_fields_EMAIL, $email);
        }
        if ($displayName !== '') {
            $binding->setData(SocialLoginBinding::schema_fields_DISPLAY_NAME, $displayName);
        }
        if ($avatarUrl !== '') {
            $binding->setData(SocialLoginBinding::schema_fields_AVATAR_URL, $avatarUrl);
        }
        $binding->setData(SocialLoginBinding::schema_fields_UPDATED_AT, $now)->save();
    }

    private function upsertBinding(
        string $provider,
        string $subject,
        int $customerId,
        string $email,
        string $displayName,
        string $avatarUrl
    ): void {
        $existing = $this->findBinding($provider, $subject);
        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            $existing
                ->setData(SocialLoginBinding::schema_fields_CUSTOMER_ID, $customerId)
                ->setData(SocialLoginBinding::schema_fields_EMAIL, $email)
                ->setData(SocialLoginBinding::schema_fields_DISPLAY_NAME, $displayName)
                ->setData(SocialLoginBinding::schema_fields_AVATAR_URL, $avatarUrl)
                ->setData(SocialLoginBinding::schema_fields_UPDATED_AT, $now)
                ->save();

            return;
        }

        $this->newBinding()
            ->clearData()
            ->setData(SocialLoginBinding::schema_fields_PROVIDER_CODE, $provider)
            ->setData(SocialLoginBinding::schema_fields_SUBJECT, $subject)
            ->setData(SocialLoginBinding::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(SocialLoginBinding::schema_fields_EMAIL, $email)
            ->setData(SocialLoginBinding::schema_fields_DISPLAY_NAME, $displayName)
            ->setData(SocialLoginBinding::schema_fields_AVATAR_URL, $avatarUrl)
            ->setData(SocialLoginBinding::schema_fields_CREATED_AT, $now)
            ->setData(SocialLoginBinding::schema_fields_UPDATED_AT, $now)
            ->save();
    }

    private function newBinding(): SocialLoginBinding
    {
        return ObjectManager::getInstance(SocialLoginBinding::class, [], false);
    }
}
