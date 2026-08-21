<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceContext;
use Weline\Framework\Session\Auth\Device\AuthenticatedDeviceRegistryInterface;
use Weline\Framework\Session\Auth\Device\AuthenticatedLoginContext;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionInterface;

/**
 * 认证 Session 实现
 *
 * 遵循 SOLID 原则：
 * - SRP: 专门处理用户认证，与 Session 数据存取分离
 * - OCP: 通过 AreaConfig 配置不同区域，无需创建子类
 * - DIP: 依赖 SessionInterface 和 AuthenticableInterface 抽象
 *
 * 通过组合 Session 实现认证功能，替代原有的继承链：
 * AdminSession -> BackendSession -> Session
 */
class AuthenticatedSession implements AuthenticatedSessionInterface
{
    /** 底层 Session 实例 */
    private SessionInterface $session;

    /** 区域配置 */
    private AreaConfig $areaConfig;

    /** 缓存的用户实例 */
    private ?AuthenticableInterface $cachedUser = null;

    /** 当前请求内设备状态只校验一次；null 表示尚未校验。 */
    private ?bool $deviceValidationResult = null;

    /**
     * 构造函数
     *
     * @param SessionInterface $session 底层 Session 实例
     * @param AreaConfig $areaConfig 区域配置
     */
    public function __construct(
        SessionInterface $session,
        AreaConfig $areaConfig,
        private ?RuntimeProviderResolver $runtimeProviders = null,
    ) {
        $this->session = $session;
        $this->areaConfig = $areaConfig;
    }

    /**
     * @inheritDoc
     *
     * 使用当前请求的 Session：先 regenerate 再写入登录态，避免旧 sess_id 切换导致 WLS/Redis 下不一致；
     * 且须在 regenerate 之后 set，否则 Session::regenerate() 会清空 dirty，登录键无法可靠落盘。
     */
    public function login(
        AuthenticableInterface $user,
        ?AuthenticatedLoginContext $context = null,
    ): void
    {
        $this->session->start();

        $registry = null;
        try {
            $registry = $this->resolveDeviceRegistry();
            $this->revokePreviousDeviceForNewLogin($registry, $context);
        } catch (\Throwable $throwable) {
            $this->clearAuthenticationState(true);
            $this->failDeviceRegistration($throwable, 'pre_regenerate');
        }

        $this->session->regenerate(true);

        $deviceContext = null;
        try {
            if ($registry !== null) {
                $deviceContext = $this->buildDeviceContext($user->getAuthIdentifier());
                if ($registry->supportsArea($deviceContext->area)) {
                    $binding = $registry->register($deviceContext, $context);
                    if (!$binding->valid) {
                        throw new \RuntimeException((string)__(
                            '认证设备登记失败。',
                        ));
                    }
                    $deviceContext = $deviceContext->withDeviceId($binding->deviceId);
                }
            }
        } catch (\Throwable $throwable) {
            // regenerate() has already persisted the previous session payload
            // under the new id on WLS/File/Redis strategies. Persist the
            // cleared auth keys as well so a failed device registration cannot
            // leave a recoverable authentication payload behind.
            $this->clearAuthenticationState(true);
            $this->failDeviceRegistration($throwable, 'register');
        }

        try {
            $this->session->set($this->areaConfig->getLoginKey(), $user->getAuthUsername());
            $this->session->set($this->areaConfig->getLoginIdKey(), $user->getAuthIdentifier());
            $this->session->set($this->areaConfig->getUserModelKey(), $user::getAuthModelClass());
            if ($deviceContext?->deviceId !== null && $deviceContext->deviceId !== '') {
                $this->session->set(
                    AuthenticatedDeviceContext::sessionKeyForArea($this->areaConfig->getArea()),
                    $deviceContext->deviceId,
                );
            }
            $this->session->save();
        } catch (\Throwable) {
            if ($registry !== null
                && $deviceContext instanceof AuthenticatedDeviceContext
                && $registry->supportsArea($deviceContext->area)) {
                try {
                    $registry->revokeCurrent($deviceContext, 'login_persist_failed');
                } catch (\Throwable) {
                }
            }
            $this->clearAuthenticationState(true);
            throw new \RuntimeException((string)__('认证登录状态保存失败。'));
        }

        $this->cachedUser = $user;
        $this->deviceValidationResult = true;
    }

    private function revokePreviousDeviceForNewLogin(
        ?AuthenticatedDeviceRegistryInterface $registry,
        ?AuthenticatedLoginContext $loginContext,
    ): void {
        if ($registry === null
            || $loginContext?->source === AuthenticatedLoginContext::SOURCE_REMEMBERED) {
            return;
        }

        $principalId = $this->session->get($this->areaConfig->getLoginIdKey());
        if ($principalId === null || $principalId === '') {
            return;
        }

        $context = $this->buildDeviceContext($principalId);
        if ($registry->supportsArea($context->area)) {
            $registry->revokeCurrent($context, 'relogin');
        }
    }

    /**
     * @inheritDoc
     */
    public function logout(): void
    {
        $principalId = $this->getUserId();
        if ($principalId !== null && $principalId !== '') {
            try {
                $registry = $this->resolveDeviceRegistry();
                $context = $this->buildDeviceContext($principalId);
                if ($registry !== null && $registry->supportsArea($context->area)) {
                    $registry->revokeCurrent($context, 'logout');
                }
            } catch (\Throwable) {
                // Local logout must remain available even when the optional registry is down.
            }
        }

        $this->clearAuthenticationState(true);
        $this->deviceValidationResult = false;
    }

    /**
     * @inheritDoc
     */
    public function isLoggedIn(): bool
    {
        if (!$this->canReadExistingSession()) {
            return false;
        }

        $loginKey = $this->session->get($this->areaConfig->getLoginKey());
        $loginIdKey = $this->session->get($this->areaConfig->getLoginIdKey());
        if ($loginKey === null || $loginKey === '' || $loginIdKey === null || $loginIdKey === '') {
            $this->deviceValidationResult = false;
            return false;
        }
        if ($this->deviceValidationResult !== null) {
            return $this->deviceValidationResult;
        }

        $rejectReason = 'device_provider_error';
        try {
            $registry = $this->resolveDeviceRegistry();
            if ($registry === null) {
                return $this->deviceValidationResult = true;
            }
            $context = $this->buildDeviceContext($loginIdKey);
            if (!$registry->supportsArea($context->area)) {
                return $this->deviceValidationResult = true;
            }
            $validation = $registry->validate($context);
            if ($validation->valid) {
                if (($context->deviceId === null || $context->deviceId === '')
                    && $validation->deviceId !== null
                    && $validation->deviceId !== '') {
                    $this->session->set(
                        AuthenticatedDeviceContext::sessionKeyForArea($this->areaConfig->getArea()),
                        $validation->deviceId,
                    );
                    $this->session->save();
                }
                return $this->deviceValidationResult = true;
            }
            $rejectReason = $validation->reason !== '' ? $validation->reason : 'device_invalid';
        } catch (\Throwable) {
            // A configured device provider is fail-closed; resolveDeviceRegistry already
            // distinguishes it from the optional-not-configured legacy path.
        }

        if (\function_exists('w_auth_log')) {
            w_auth_log('auth_device_rejected', '认证设备校验失败，已清除登录态', [
                'area' => $this->areaConfig->getArea(),
                'reason' => $rejectReason,
            ]);
        }

        $this->clearAuthenticationState(true);
        return $this->deviceValidationResult = false;
    }

    /**
     * @inheritDoc
     */
    public function getUser(): ?AuthenticableInterface
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $userId = $this->getUserId();
        $modelClass = $this->session->get($this->areaConfig->getUserModelKey());

        if ($userId === null || $modelClass === null || $modelClass === '') {
            return null;
        }

        if (!\class_exists($modelClass)) {
            return null;
        }

        try {
            $model = ObjectManager::make($modelClass);
            
            if (\method_exists($model, 'load')) {
                $model->load($userId);
            }
            
            if ($model instanceof AuthenticableInterface) {
                $this->cachedUser = $model;
                return $model;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * @inheritDoc
     * 前台即当前登录 Customer（与 getUser() 同义）
     */
    public function getCustomer(): ?AuthenticableInterface
    {
        return $this->getUser();
    }

    /**
     * @inheritDoc
     */
    public function getUserId(): int|string|null
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $id = $this->session->get($this->areaConfig->getLoginIdKey());
        
        if ($id === null || $id === '') {
            return null;
        }
        
        return $id;
    }

    /**
     * @inheritDoc
     */
    public function getUsername(): ?string
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $username = $this->session->get($this->areaConfig->getLoginKey());
        
        if ($username === null || $username === '') {
            return null;
        }
        
        return (string)$username;
    }

    /**
     * @inheritDoc
     */
    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        if (!$this->canReadExistingSession()) {
            return null;
        }

        return $this->session->get($key);
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): void
    {
        $this->session->delete($key);
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return $this->session->getId();
    }

    /**
     * @inheritDoc
     */
    public function start(?string $sessionId = null): void
    {
        $this->session->start($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function destroy(): void
    {
        $this->session->destroy();
    }

    /**
     * @inheritDoc
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->session->regenerate($deleteOldSession);
    }

    /**
     * @inheritDoc
     */
    public function isStarted(): bool
    {
        return $this->session->isStarted();
    }

    /**
     * @inheritDoc
     */
    public function getArea(): string
    {
        return $this->areaConfig->getArea();
    }

    /**
     * 获取区域配置
     */
    public function getAreaConfig(): AreaConfig
    {
        return $this->areaConfig;
    }

    /**
     * 检查是否为后台区域
     */
    public function isBackend(): bool
    {
        return $this->areaConfig->isBackend();
    }

    /**
     * 检查是否为前台区域
     */
    public function isFrontend(): bool
    {
        return $this->areaConfig->isFrontend();
    }

    /**
     * 检查是否为 API 区域
     */
    public function isApi(): bool
    {
        return $this->areaConfig->isApi();
    }

    /**
     * 重置缓存（WLS 模式下请求结束时调用）
     */
    public function reset(): void
    {
        $this->cachedUser = null;
        $this->deviceValidationResult = null;
        
        if (\method_exists($this->session, 'reset')) {
            $this->session->reset();
        }
    }

    // ==================== 兼容方法（过渡期使用） ====================

    /**
     * 兼容旧的 getData 方法
     *
     * @deprecated 使用 getSession()->get() 代替
     */
    public function getData(string $name = ''): mixed
    {
        if (!$this->canReadExistingSession()) {
            return $name === '' ? [] : null;
        }

        if ($name === '') {
            return $this->session->all();
        }
        return $this->session->get($name);
    }

    private function canReadExistingSession(): bool
    {
        if ($this->session->isStarted()) {
            return true;
        }

        $cookieName = SessionCookieNameResolver::resolve();
        $cookieValue = WelineEnv::getCookie($cookieName, null);
        if (\is_string($cookieValue) && \trim($cookieValue) !== '') {
            return true;
        }

        $cookieHeader = '';
        if (\class_exists(WelineEnv::class, false)) {
            $cookieHeader = (string)(
                WelineEnv::server('HTTP_COOKIE', '')
                ?: WelineEnv::get('server.http_cookie', '')
            );
        }
        return $this->cookieHeaderContains($cookieHeader, $cookieName);
    }

    private function resolveDeviceRegistry(): ?AuthenticatedDeviceRegistryInterface
    {
        $resolver = $this->runtimeProviders ??= $this->defaultRuntimeProviderResolver();

        $resolution = $resolver->resolveDetailed(AuthenticatedDeviceRegistryInterface::class);
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return null;
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof AuthenticatedDeviceRegistryInterface) {
            throw new \RuntimeException((string)__('认证设备服务不可用。'));
        }
        return $resolution->provider;
    }

    /**
     * Fail closed on device registration, but keep the underlying cause for
     * operators (auth.log + exception previous) and surface a schema-ready hint
     * when the device table is missing after a core update.
     */
    private function failDeviceRegistration(\Throwable $cause, string $phase): never
    {
        if (\function_exists('w_auth_log')) {
            w_auth_log('auth_device_register_failed', '认证设备登记失败', [
                'area' => $this->areaConfig->getArea(),
                'phase' => $phase,
                'exception' => $cause::class,
                'message' => \mb_substr($cause->getMessage(), 0, 500),
                'code' => $cause->getCode(),
                'schema_missing' => $this->isDeviceSchemaUnavailable($cause),
            ]);
        }

        throw new \RuntimeException(
            $this->deviceRegistrationFailureMessage($cause),
            0,
            $cause,
        );
    }

    private function deviceRegistrationFailureMessage(\Throwable $cause): string
    {
        if ($this->isDeviceSchemaUnavailable($cause)) {
            return (string)__('认证设备登记失败：设备表未就绪，请先执行 setup:upgrade。');
        }

        return (string)__('认证设备登记失败。');
    }

    private function isDeviceSchemaUnavailable(\Throwable $cause): bool
    {
        for ($current = $cause; $current !== null; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            $sqlState = '';
            if ($current instanceof \PDOException) {
                $sqlState = (string)($current->errorInfo[0] ?? $current->getCode());
            }
            if ($sqlState === '42P01'
                || \str_contains($message, '42P01')
                || \str_contains($message, 'Undefined table')
                || \str_contains($message, 'Base table or view not found')
                || \str_contains($message, 'no such table')
                || (\str_contains($message, 'weline_authenticated_device')
                    && (\str_contains($message, 'does not exist')
                        || \str_contains($message, 'doesn\'t exist')
                        || \str_contains($message, '不存在')))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Direct construction must honor the same compiled provider registry as
     * SessionFactory. Otherwise a caller could accidentally bypass a configured
     * fail-closed device registry simply by omitting the optional constructor
     * argument. A genuinely absent declaration still keeps legacy behavior.
     */
    private function defaultRuntimeProviderResolver(): RuntimeProviderResolver
    {
        try {
            return ObjectManager::getInstance(RuntimeProviderResolver::class);
        } catch (\Throwable) {
            return new RuntimeProviderResolver(new ServiceProviderRegistry());
        }
    }

    private function buildDeviceContext(int|string $principalId): AuthenticatedDeviceContext
    {
        $sessionId = $this->session->getId();
        if ($sessionId === '') {
            throw new \RuntimeException((string)__('当前 Session 尚未建立。'));
        }
        $ttl = 3600;
        if (\method_exists($this->session, 'getDefaultTtl')) {
            $ttl = max(1, (int)$this->session->getDefaultTtl());
        }
        return new AuthenticatedDeviceContext(
            area: $this->areaConfig->getArea(),
            principalId: (string)$principalId,
            sessionId: $sessionId,
            sessionExpiresAt: time() + $ttl,
            deviceId: $this->readBoundDeviceId(),
        );
    }

    private function readBoundDeviceId(): ?string
    {
        $value = $this->session->get(
            AuthenticatedDeviceContext::sessionKeyForArea($this->areaConfig->getArea()),
        );
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function clearAuthenticationState(bool $save): void
    {
        $this->session->delete($this->areaConfig->getLoginKey());
        $this->session->delete($this->areaConfig->getLoginIdKey());
        $this->session->delete($this->areaConfig->getUserModelKey());
        $this->session->delete(
            AuthenticatedDeviceContext::sessionKeyForArea($this->areaConfig->getArea()),
        );
        $this->cachedUser = null;
        if ($save && $this->session->isStarted()) {
            try {
                $this->session->save();
            } catch (\Throwable) {
                // Authentication is already cleared in memory. A later request
                // must validate again and cannot use the cached result.
            }
        }
    }

    private function cookieHeaderContains(string $cookieHeader, string $cookieName): bool
    {
        foreach (\explode(';', $cookieHeader) as $pair) {
            $parts = \explode('=', \trim($pair), 2);
            if (\count($parts) !== 2 || \rawurldecode($parts[0]) !== $cookieName) {
                continue;
            }
            return \trim(\rawurldecode($parts[1])) !== '';
        }

        return false;
    }

    /**
     * 兼容旧的 setData 方法
     *
     * @deprecated 使用 getSession()->set() 代替
     */
    public function setData(string $name, mixed $value): static
    {
        $this->session->set($name, $value);
        return $this;
    }

    /**
     * 兼容旧的 isLogin 方法
     *
     * @deprecated 使用 isLoggedIn() 代替
     */
    public function isLogin(): bool
    {
        return $this->isLoggedIn();
    }

    /**
     * 兼容旧的 getLoginUser 方法
     *
     * @deprecated 使用 getUser() 代替
     */
    public function getLoginUser(string $model = ''): ?AuthenticableInterface
    {
        return $this->getUser();
    }

    /**
     * 兼容旧的 getLoginUsername 方法
     *
     * @deprecated 使用 getUsername() 代替
     */
    public function getLoginUsername(): ?string
    {
        return $this->getUsername();
    }

    /**
     * 兼容旧的 getLoginUserID 方法
     *
     * @deprecated 使用 getUserId() 代替
     */
    public function getLoginUserID(): int|string|null
    {
        return $this->getUserId();
    }

    /**
     * 兼容旧的 getLoginUserData 方法
     *
     * @deprecated 使用 getUser()->getData() 或直接访问用户模型
     * @param string $key 数据键名，为空时返回全部用户数据
     * @return mixed 用户数据
     */
    public function getLoginUserData(string $key = ''): mixed
    {
        $user = $this->getUser();
        if ($user === null) {
            return $key === '' ? [] : null;
        }

        if (\method_exists($user, 'getData')) {
            if ($key === '') {
                return $user->getData();
            }
            return $user->getData($key);
        }

        return $key === '' ? [] : null;
    }

    /**
     * 兼容旧的 getSessionId 方法
     *
     * @deprecated 使用 getSession()->getId() 代替
     */
    public function getSessionId(): string
    {
        return $this->session->getId();
    }

    /**
     * 兼容旧的 getType 方法
     *
     * @deprecated 使用 getArea() 代替
     */
    public function getType(): string
    {
        return $this->getArea();
    }
}
