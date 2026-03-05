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
/** 返回配置
     * @param string $key
     * @param bool $real
     * @return string
     */
    public function getConfig(string $key, string $module = '', string $name = '', bool $real = false): string
    {
        if (CLI) {
            return $this->getDefaultConfig($key);
        }
        if ($real) {
            /**@var AuthenticatedSessionInterface $userSession */
            $userSession = SessionFactory::getInstance()->createBackendSession();
            return $this->clear()->where(self::schema_fields_user_id, $userSession->getUserId())
                ->where(self::schema_fields_key, $key)
                ->find()
                ->fetchArray()['value'] ?? '';
        }
        $self_config_key = self::key($key, $module, $name);
        if (isset($this->config[$self_config_key])) {
            return $this->config[$self_config_key];
        }
        # 读取用户全部配置
        /**@var AuthenticatedSessionInterface $userSession */
        $userSession = SessionFactory::getInstance()->createBackendSession();
        $this->reset()
            ->where(self::schema_fields_user_id, $userSession->getUserId())
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
        if (CLI) {
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
        /**@var AuthenticatedSessionInterface $userSession */
        $userSession = SessionFactory::getInstance()->createBackendSession();
        return (bool)$this->clear()
            ->setData(self::schema_fields_key, $key, true)
            ->setData(self::schema_fields_value, $value)
            ->setData(self::schema_fields_user_id, $userSession->getUserId(), true)
            ->setData(self::schema_fields_module, $module, true)
            ->setData(self::schema_fields_name, $name, true)
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
            ->setData(self::schema_fields_module, $module, true)
            ->setData(self::schema_fields_name, $name, true)
            ->save();
    }
    public function save(string|array|bool|AbstractModel $data = [], string|array|null $sequence = ''): bool|int
    {
        $this->forceCheck();
        return parent::save($data, $sequence);
    }
}
