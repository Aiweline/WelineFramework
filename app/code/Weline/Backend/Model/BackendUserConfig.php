<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\Backend\Model;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '后台用户配置表')]
#[Index(name: 'idx_user_key', columns: ['user_id', 'key'], type: 'UNIQUE', comment: '管理员配置唯一索引')]
#[Index(name: 'idx_module', columns: ['module'], comment: '模组索引')]
#[Index(name: 'idx_name', columns: ['name'], comment: '配置名')]
class BackendUserConfig extends Model
{
    public const schema_primary_keys = ['user_id', 'key'];
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('int', 0, nullable: false, default: 0, comment: '管理员ID')]
    public const schema_fields_user_id = 'user_id';
    #[Col('text', comment: '配置信息')]
    public const schema_fields_value = 'value';
    #[Col('varchar', 50, nullable: false, comment: '配置key')]
    public const schema_fields_key = 'key';
    #[Col('varchar', 255, nullable: false, comment: '模组')]
    public const schema_fields_module = 'module';
    #[Col('varchar', 255, nullable: false, comment: '配置名')]
    public const schema_fields_name = 'name';
    private array $config = [];
    private array $default_config = [];
    public array $_index_sort_keys = [self::schema_fields_ID, self::schema_fields_user_id, self::schema_fields_key, self::schema_fields_name, self::schema_fields_module];
    public array $_unit_primary_keys = [self::schema_fields_user_id, self::schema_fields_key];
    public function getCurrentUserId(): int
    {
        try {
            /**@var AuthenticatedSessionInterface $userSession */
            $userSession = SessionFactory::getInstance()->createBackendSession();
            $userId = (int)($userSession->getUserId() ?? 0);
            if ($userId > 0) {
                return $userId;
            }

            $loginUser = $userSession->getLoginUser();
            if (\is_object($loginUser) && \method_exists($loginUser, 'getId')) {
                $userId = (int)$loginUser->getId();
                if ($userId > 0) {
                    return $userId;
                }
            }

            $sessionId = \trim((string)$userSession->getId());
            if ($sessionId === '') {
                $sessionId = $this->resolveSessionIdFromCookie();
            }
            if ($sessionId === '') {
                return 0;
            }

            /** @var BackendUser $backendUser */
            $backendUser = ObjectManager::getInstance(BackendUser::class);
            $row = $backendUser->clear()
                ->where(BackendUser::schema_fields_sess_id, $sessionId)
                ->find()
                ->fetchArray();
            return (int)($row[BackendUser::schema_fields_ID] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveSessionIdFromCookie(): string
    {
        $cookieHeader = '';
        if (\class_exists(\Weline\Framework\Env\WelineEnv::class)) {
            $cookieHeader = (string)(
                \Weline\Framework\Env\WelineEnv::server('HTTP_COOKIE', '')
                ?: \Weline\Framework\Env\WelineEnv::get('server.http_cookie', '')
            );
        }
        if ($cookieHeader !== '' && \preg_match('/(?:^|;\s*)WELINE_SESSID=([^;]+)/', $cookieHeader, $matches)) {
            return \trim((string)\urldecode($matches[1]));
        }

        return \trim((string)($_COOKIE['WELINE_SESSID'] ?? ''));
    }

    private function isCommandLineConfigContext(): bool
    {
        if (!CLI) {
            return false;
        }

        if ($this->resolveSessionIdFromCookie() !== '') {
            return false;
        }

        $serverKeys = [
            'REQUEST_METHOD',
            'REQUEST_URI',
            'HTTP_HOST',
            'SERVER_NAME',
            'WELINE_AREA',
            'WELINE_AREA_ROUTE',
        ];

        foreach ($serverKeys as $key) {
            $serverValue = (string)($_SERVER[$key] ?? '');
            if ($serverValue !== '') {
                return false;
            }

            if (\class_exists(\Weline\Framework\Env\WelineEnv::class)) {
                $envValue = (string)\Weline\Framework\Env\WelineEnv::server($key, '');
                if ($envValue !== '') {
                    return false;
                }
            }
        }

        return true;
    }
/** 返回配置
     * @param string $key
     * @param bool $real
     * @return string
     */
    public function getConfig(string $key, string $module = '', string $name = '', bool $real = false): string
    {
        if ($this->isCommandLineConfigContext()) {
            return $this->getDefaultConfig($key);
        }
        if ($real) {
            $userId = $this->getCurrentUserId();
            return $this->clear()->where(self::schema_fields_user_id, $userId)
                ->where(self::schema_fields_key, $key)
                ->find()
                ->fetchArray()['value'] ?? '';
        }
        $self_config_key = self::key($key, $module, $name);
        if (isset($this->config[$self_config_key])) {
            return $this->config[$self_config_key];
        }
        # 读取用户全部配置
        $userId = $this->getCurrentUserId();
        $this->reset()
            ->where(self::schema_fields_user_id, $userId)
            ->where(self::schema_fields_key, $key);
        if ($module) {
            $this->where(self::schema_fields_module, $module);
        }
        if ($name) {
            $this->where(self::schema_fields_name, $name);
        }
        $config = $this
            ->find()
            ->fetchArray();
        $this->config[$self_config_key] = $config['value'] ?? '';
        return $this->config[$self_config_key];
    }
    public function getDefaultConfig(string $key): string
    {
        if (isset($this->default_config[$key])) {
            return $this->default_config[$key];
        }
        # 读取默认配置
        try {
            $config = $this->clear()
                ->where(self::schema_fields_user_id, 0)
                ->find()
                ->fetchArray();
        } catch (\Throwable $e) {
            $config = null;
        }
        $this->default_config[$key] = $config['value'] ?? '';
        return $this->default_config[$key];
    }
    /**
     * 设置用户配置
     * @param string $key
     * @param string $value
     * @param string $module
     * @param string $name
     * @param bool $check
     * @return bool
     * @throws \Exception
     */
    public function setConfig(string $key, string $value, string $module, string $name, $check = true): bool
    {
        $this->config[self::key($key, $module, $name)] = $value;
        if ($this->isCommandLineConfigContext()) {
            return $this->setDefaultConfig($key, $value, $module, $name);
        }
        if ($check) {
            # 检测模组
            $moduleInfo = Env::getInstance()->getModuleInfo($module);
            if (!$moduleInfo) {
                if (DEV) {
                    throw new \Exception('找不到模组' . $module);
                }
                return false;
            }
        }
        # 设置用户配置
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return false;
        }
        return (bool)$this->clear()
            ->setData(self::schema_fields_key, $key, true)
            ->setData(self::schema_fields_value, $value)
            ->setData(self::schema_fields_user_id, $userId, true)
            // 仅 user_id + key 是真实唯一键；module/name 只是普通更新字段，不能参与 PG 的 ON CONFLICT
            ->setData(self::schema_fields_module, $module)
            ->setData(self::schema_fields_name, $name)
            ->save(true);
    }
    private static function key(string $key, string $module = '', string $name = ''): string
    {
        return ($module ? $module . '::' : '') . ($name ? $name . '::' : '') . $key;
    }
    /**
     * 设置默认配置
     * @param string $key
     * @param string $value
     * @param string $module
     * @param string $name
     * @param bool $check
     * @return bool|int
     * @throws \Exception
     */
    public function setDefaultConfig(string $key, string $value, string $module, string $name, $check = true): bool|int
    {
        if ($check) {
            # 检测模组
            $moduleInfo = Env::getInstance()->getModuleInfo($module);
            if (!$moduleInfo) {
                if (DEV) {
                    throw new \Exception(__('找不到模组: %{1}', $module));
                }
                return false;
            }
        }
        # 设置默认配置
        return (bool)$this->clear()
            ->setData(self::schema_fields_key, $key, true)
            ->setData(self::schema_fields_value, $value)
            ->setData(self::schema_fields_user_id, 0, true)
            // 仅 user_id + key 是真实唯一键；module/name 只是普通更新字段，不能参与 PG 的 ON CONFLICT
            ->setData(self::schema_fields_module, $module)
            ->setData(self::schema_fields_name, $name)
            ->save();
    }
    public function save(string|array|bool|AbstractModel $data = [], string|array|null $sequence = ''): bool|int
    {
        $this->forceCheck();
        return parent::save($data, $sequence);
    }
}
