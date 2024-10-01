<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Session;

use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Driver\SessionDriverHandlerInterface;

class Session implements SessionInterface
{
    public const login_KEY = 'WL_USER';
    public const login_KEY_ID = 'WL_USER_ID';
    public const login_USER_MODEL = 'WL_USER_MODEL';

    private ?AbstractModel $user = null;

    private ?SessionDriverHandlerInterface $session = null;

    public function __construct()
    {
        if (!isset($this->session)) {
            $this->__init();
        }
    }

    public function __init()
    {
        if (isset($_SERVER['REQUEST_URI']) && !isset($this->session)) {
            $type = 'frontend';
            $identity_path = '/';
            if (is_int(strpos($_SERVER['REQUEST_URI'], Env::getInstance()->getData('admin')))) {
                $identity_path .= Env::getInstance()->getConfig('admin');
                $type = 'backend';
            } elseif (is_int(strpos($_SERVER['REQUEST_URI'], Env::getInstance()->getConfig('api_admin')))) {
                $identity_path .= Env::getInstance()->getConfig('api_admin');
                $type = 'api_backend';
            }
            $this->session = SessionManager::getInstance()->create();
            $this->start();
            $this->setType($type)->setData('path', $identity_path);
        }
    }

    public function start(string $session_id = null): void
    {
        if ($session_id) {
            if (session_id() != $session_id) {
                session_id($session_id);
            }
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /**
     * @DESC          # 设置session值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 21:45
     * 参数区：
     *
     * @param string $name
     * @param string $value
     *
     * @return SessionDriverHandlerInterface
     */
    public function setData(string $name, mixed $value): SessionDriverHandlerInterface
    {
        $this->session->set($name, $value);
        return $this->session;
    }

    /**
     * @DESC          # 获取session值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 21:45
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function getData(string $name = null): mixed
    {
        if ($name) {
            return $this->session->get($name);
        }
        return $this->session->get();
    }

    /**
     * @DESC          # 获取session值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 21:45
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function addData(string $name, string $value): string
    {
        return $this->session->set($name, $this->session->get($name) . $value);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return SessionDriverHandlerInterface
     */
    public function getOriginSession(): SessionDriverHandlerInterface
    {
        return $this->session;
    }

    public function getSessionId(): string
    {
        return $this->session->getSessionId();
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return mixed
     */
    public function isLogin(): bool
    {
        return (bool)$this->session->get($this::login_KEY);
    }

    public function login(\Weline\Framework\Database\Model $user, int $user_id): static
    {
        $this->start($user->getSessionId());
        $this->session->set($this::login_KEY, $user);
        $this->session->set($this::login_KEY_ID, $user_id);
        $this->session->set($this::login_USER_MODEL, $user::class);
        return $this;
    }

    public function getLoginUser(string $model): ?AbstractModel
    {
        if ($this->user) {
            return $this->user;
        }
        $this->user = ObjectManager::getInstance($model)->load($this->session->get($this::login_KEY_ID) ?: '');
        return $this->user;
    }

    public function getLoginUsername()
    {
        return $this->session->get($this::login_KEY);
    }

    public function getLoginUserID()
    {
        return $this->session->get($this::login_KEY_ID);
    }

    public function logout(): bool
    {
        $this->session->delete($this::login_KEY);
        return $this->session->delete($this::login_KEY_ID);
    }

    public function getType(): string
    {
        return $this->session->get('type');
    }

    public function setType(string $type): static
    {
        $this->session->set('type', $type);
        return $this;
    }

    public function isBackend(): bool
    {
        return $this->getType() === 'backend';
    }

    public function isApi(): bool
    {
        return $this->getType() === 'api';
    }

    public function isApiBackend(): bool
    {
        return $this->getType() === 'api_backend';
    }

    public function isFrontend(): bool
    {
        return $this->getType() === 'frontend';
    }

    public function destroy(string $id = null): bool
    {
        return $this->session->destroy($id ?: $this->session->getSessionId());
    }

    public function delete(string $name): bool
    {
        if (isset($_SESSION[$name])) {
            unset($_SESSION[$name]);
            return true;
        }
        return false;
    }

    public function getGcMaxLifeTime(): int
    {
        return ini_get('session.gc_maxlifetime');
    }
}
