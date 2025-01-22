<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Exception;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Database\Cache\DbModelCache;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Helper\Tool;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Exception\Core;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * Class AbstractModel
 * @method AbstractModel|QueryInterface identity(string $field)
 * @method AbstractModel|QueryInterface table(string $table_name)
 * @method AbstractModel|QueryInterface fields(string $fields)
 * @method AbstractModel|QueryInterface join(string $table, string $condition, string $type = 'left')
 * @method AbstractModel|QueryInterface where(array|string $field, mixed $value = null, string $con = '=', string $logic = 'AND', string $array_where_logic_type = 'and')
 * @method AbstractModel|QueryInterface limit(int $size, int $offset = 0)
 * @method AbstractModel|QueryInterface page(int $page = 1, int $pageSize = 20)
 * @method AbstractModel|QueryInterface order(string $fields = 'main_table.create_time', string $sort = 'DESC')
 * @method AbstractModel|QueryInterface group(string $fields)
 * @method AbstractModel|QueryInterface concat(string $fields, string $alias_field): QueryInterface;
 * @method AbstractModel|QueryInterface concat_like(string $fields, string $like_word): QueryInterface;
 * @method AbstractModel|QueryInterface group_concat(string $fields, string $concat_field, string $separator = 'json'): QueryInterface
 * @method AbstractModel|QueryInterface find()
 * @method int total(string $field = '*', string $alias = 'total_count')
 * @method AbstractModel|QueryInterface select()
 * @method AbstractModel|QueryInterface insert(array $data, array|string $update_where_fields = [], string $update_fields = '', bool $ignore_primary_key = false)
 * @method AbstractModel|QueryInterface query(string $sql)
 * @method AbstractModel|QueryInterface getIndexFields()
 * @method AbstractModel|QueryInterface reindex(string $table, array $fields = [])
 * @method AbstractModel|QueryInterface additional(string $additional_sql)
 * @method AbstractModel|QueryInterface clearQuery(string $type = '')
 * @method AbstractModel|QueryInterface period(string $period, string $field = 'main_table.create_time'): static;
 *
 * @method AbstractModel|QueryInterface fetch(string $model_class = ''): mixed;
 * @method AbstractModel|QueryInterface reset()
 * @method AbstractModel|QueryInterface test(string $test_code)
 * @method AbstractModel|QueryInterface beginTransaction()
 * @method AbstractModel|QueryInterface rollBack()
 * @method AbstractModel|QueryInterface commit()
 * @method AbstractModel|QueryInterface truncate(string $table = ''): static;
 * @method AbstractModel|QueryInterface backup(string $table = ''): static;
 * @method AbstractModel|QueryInterface getSql()
 * @method AbstractModel|QueryInterface getPrepareSql()
 * @package Weline\Framework\Database
 */
abstract class AbstractModel extends DataObject
{
    # 主数据库连接使用标志
    public const framework_db_master = false;
    public const table = '';
    public const primary_key = '';
    # 索引名
    public const indexer = '';
    /**
     * 对象属性
     *
     * @var array
     */
    public const fields_ID = 'id';
    public const fields_CREATE_TIME = 'create_time';

    public const fields_UPDATE_TIME = 'update_time';

    # 模块名称
    public string $module_name = '';
    public string $table = '';
    public string $alias = '';
    public string $origin_table_name = '';
    private ?ConnectionFactory $connection = null;
    public string $_suffix = '';
    public string $_primary_key = '';
    public string $_primary_key_default = 'id';
    /*联合主键排序*/
    public array $_unit_primary_keys = [];
    /*联合唯一字段*/
    public array $_unit_unique_fields = []; # 用于save保存数据时检查是否update还是insert  示例：['username','password']
    /*索引字段排序*/
    public array $_index_sort_keys = [];
    public array $_fields = [];
    # 装载join模型时字段数据，用于字段冲突
    public array $_join_model_fields = [];
    private array $_model_fields = [];
    private array $_bind_model_fields = [];
    private array $_model_fields_data = [];

    # 强制装载内容
    public array $_force_join_models = []; // 强制联合模型

    private bool $force_check_flag = false;
    private array $force_check_fields = [];
    private bool $remove_force_check_field = false;
    private array $unique_data = [];

    private DbManager|null $dbManager = null;
    private ?QueryInterface $_bind_query = null;
    private ?QueryInterface $current_query = null;
    public ?CacheInterface $_cache = null;
    private array $_fetch_data = [];
    public array $items = [];
    private mixed $_query_data = null;
    private bool $is_delete = false;
    private bool $is_update = false;
    private bool $is_insert = false;
    public array $pagination = ['page' => 1, 'pageSize' => 20, 'totalSize' => 0, 'lastPage' => 0];

    # Flag
    private bool $use_cache = false;

    public function __sleep()
    {
        return array('table', 'module_name', '_suffix', '_primary_key', 'origin_table_name');
    }

    public function __wakeup()
    {
        $this->__init();
    }

    /**
     * @DESC         |初始化连接、缓存、表前缀 读取模型自身表名字等
     *
     * 参数区：
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function __init()
    {
        $this->reset();
        # 如果初始化有数据
        if ($this->getData()) {
            $this->fetch_after();
        }
        # 重置查询
        if (!isset($this->_cache)) {
            $this->_cache = ObjectManager::getInstance(DbModelCache::class . 'Factory');
        }
        # dbManager
        if (empty($this->dbManager)) {
            $this->dbManager = ObjectManager::getInstance(DbManager::class);
        }
        # 类属性
        if (empty($this->connection)) {
            $this->connection = $this->getConnection();
        }
        if (empty($this->_suffix)) {
            $this->_suffix = $this->getConnection()->getConfigProvider()->getPrefix() ?: '';
        }
        # 模型属性
        if (!empty($this::table)) {
            $this->table = $this::table;
        }
        if (empty($this->table)) {
            $this->table = $this->processTable();
        }
        if (empty($this->_primary_key)) {
            if (!empty($this::primary_key)) {
                $this->_primary_key = $this::primary_key;
            } elseif ($this::fields_ID !== $this->_primary_key_default) {
                $this->_primary_key = $this::fields_ID;
            } else {
                $this->_primary_key = $this->_primary_key_default;
            }
        }
        # 字段解析
        if (empty($this->_fields)) {
            $this->_fields = $this->getModelFields();
        }
        //        # 动态属性字段
        //        if ($this->getData()) {
        //            foreach ($this->getData() as $key => $data) {
        //                if (is_string($key)) {
        //                    $field_name = 'model_field_'.$key;
        //                    $this->$field_name = $data;
        //                }
        //            }
        //        }else{
        //            foreach ($this->_fields as $key => $filed) {
        //                if (is_string($filed)) {
        //                    $field_name = 'model_field_'.$filed;
        //                    $this->$field_name = '';
        //                }
        //            }
        //        }
    }

    // 定义深度克隆逻辑
    public function __clone()
    {
        # 拷贝时克隆新的查询对象
        if ($this->_bind_query instanceof QueryInterface) {
            $this->_bind_query = clone $this->_bind_query;
        }
        # 拷贝时克隆新的查询对象
        if ($this->current_query instanceof QueryInterface) {
            $this->current_query = clone $this->current_query;
        }
    }

    public function export(bool $is_download = true, string $output_file_name = '', array $columns = []): string
    {
        return Tool::export(clone $this, $is_download, $output_file_name, $columns);
    }

    public function getBindModelFields(): array
    {
        return $this->_bind_model_fields;
    }

    public function getConnection()
    {
        # 如果已经有链接直接返回
        if (!empty($this->connection)) {
            return $this->connection;
        }
        # 使用主数据库
        if ($this::framework_db_master) {
            $this->connection = ObjectManager::getInstance(DbManager::class . 'Factory');
        } else {
            # 检测app应用级别的数据库配置信息 读链接 和  写链接
            $filename = ObjectManager::getReflectionInstance($this)->getFileName();
            # 去除相对的模组路径
            if (str_starts_with($filename, APP_CODE_PATH)) {
                $filename = substr($filename, strlen(APP_CODE_PATH));
                $this->processModelDbConnection($filename);
            } elseif (str_starts_with($filename, VENDOR_PATH)) {
                $filename = substr($filename, strlen(VENDOR_PATH));
                $this->processModelDbConnection($filename);
            } else {
                $this->connection = ObjectManager::getInstance(DbManager::class . 'Factory');
            }
        }
        return $this->connection;
    }

    public function setConnection(ConnectionFactory $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    private function processModelDbConnection(string $filename)
    {
        # 读取模组名称
        $file_name_arr = explode(DS, $filename);
        # 模组目录
        $module_name_path = $file_name_arr[0] . DS . $file_name_arr[1];
        $this->module_name = str_replace(DS, '_', $module_name_path);
        # 应用数据库配置
        $db_config_file = APP_CODE_PATH . $module_name_path . DS . 'etc' . DS . 'db.php';
        if (is_file($db_config_file)) {
            $db_config = include $db_config_file;
            # 确认是否有主库配置
            if (!isset($db_config['master'])) {
                throw new DbException(__('请配置主数据库配置信息,或者主数据库配置信息设置错误') . (DEV ? '(' . $db_config_file . ')' : ''));
            }
            $this->connection = $this->dbManager->create(
                $this->module_name,
                new ConfigProvider($db_config)
            );
        } else {
            $this->connection = ObjectManager::getInstance(DbManager::class . 'Factory');
        }
    }

    public function getIdField(): string
    {
        return $this->_primary_key;
    }

    /**
     * @DESC          # 处理表名 存在表名则不处理
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 20:32
     * 参数区：
     * @return string
     */
    protected function processTable(): string
    {
        if (self::table) {
            return self::table;
        }
        if (!$this->table) {
            $class_file_name_arr = explode('Model', $this::class);
            array_shift($class_file_name_arr);
            $class_file_name = str_replace('\\', '', implode('', $class_file_name_arr));
            if (str_ends_with($class_file_name, 'Interceptor')) {
                $class_file_name = substr($class_file_name, 0, strpos($class_file_name, 'Interceptor'));
            }
            if (str_ends_with($class_file_name, 'Model')) {
                $class_file_name = substr($class_file_name, 0, strpos($class_file_name, 'Model'));
            }
            $table_name = $class_file_name;
            $db_name = $this->getConnection()->getConfigProvider()->getDatabase();
            $this->origin_table_name = $this->_suffix . strtolower(implode('_', w_split_by_capital(lcfirst($table_name))));
            $this->table = ($db_name ? "`$db_name`." : '') . "`{$this->origin_table_name}`";

        }
        if (empty($this->origin_table_name)) {
            $this->origin_table_name = $this->table;
        }
        return $this->table;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        if (empty($key)) {
            if ($data = parent::getData()) {
                return $data;
            }
            return $this->getFetchData();
        }
        return parent::getData($key, $index);
    }

    public function getOriginData(string $key = '', $index = null): mixed
    {
        if (empty($key)) {
            $dataObjectData = $this->getData();
            $data = [];
            if (is_int(array_key_first($dataObjectData)) && ($dataObjectData[0] instanceof DataObject)) {
                foreach ($dataObjectData as $datum) {
                    $data[] = $datum->getData();
                }
                return $data;
            }
            if (empty($data)) {
                if (is_int(array_key_first($this->getFetchData())) && ($this->getFetchData()[0] instanceof DataObject)) {
                    foreach ($this->getFetchData() as $datum) {
                        $data[] = $datum->getData();
                    }
                }
                return $data;
            }
        }
        return parent::getData($key, $index);
    }

    /**
     * @DESC          # 读取表名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/3 20:14
     * 参数区：
     *
     * @param string $table
     *
     * @return string
     */
    public function getTable(string $table = ''): string
    {
        if (empty($table)) {
            return $this->processTable();
        }
        return $this->getConnection()->getConfigProvider()->getPrefix() . $table;
    }

    public function alias(string $alias): static
    {
        $this->alias = $alias;
        return $this;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * @DESC          # 读取原始表名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/3 20:14
     * 参数区：
     * @return string
     */
    public function getOriginTableName(): string
    {
        $this->processTable();
        return $this->origin_table_name;
    }

    /**
     * @DESC         |获取数据库基类
     *
     * 参数区：
     *
     * @param bool $keep_condition
     *
     * @return QueryInterface
     * @throws Exception
     * @throws \ReflectionException
     */
    public function getQuery(bool $keep_condition = true): QueryInterface
    {
        $query = null;
        # 如果绑定了查询
        if ($this->_bind_query) {
            if (!$keep_condition) {
                $this->_bind_query->clearQuery();
            }
            $query = $this->_bind_query->table($this->getOriginTableName())->identity($this->_primary_key);
        } else {
            if ($this->current_query) {
                if (!$keep_condition) {
                    $this->current_query->clearQuery();
                }
                $query = $this->current_query->table($this->getOriginTableName())->identity($this->_primary_key);
            } else {
                # 区分是否保持查询
                $this->current_query = clone $this->getConnection()->getConnector()->clearQuery()->table($this->getOriginTableName())->identity($this->_primary_key);
                $query = $this->current_query;
            }
        }
        // 联合主键索引对where条件进行排序提升查询速度
        $query->_index_sort_keys = array_unique([$this->_primary_key, ...$this->_unit_primary_keys, ...$this->_index_sort_keys]);
        return $query;
    }

    /**
     * @DESC         |获取新的查询构建器 基础表和主键默认为模型表和模型主键 可控制是否全新查询器
     *
     * 参数区：
     *
     * @param bool $really_new
     *
     * @return QueryInterface
     */
    public function newQuery(bool $really_new = true): QueryInterface
    {
        $query = $this->getConnection()->getConnector()->clearQuery();
        if ($really_new) {
            $query->table($this->getOriginTableName())->identity($this->_primary_key);
        }
        return $query;
    }

    /**
     * @DESC         |获取数据库基类
     *
     * 参数区：
     *
     * @param QueryInterface $query
     *
     * @return AbstractModel
     */
    public function setQuery(QueryInterface $query): static
    {
        # 如果绑定了查询
        if ($this->_bind_query) {
            $this->_bind_query = $query;
        } elseif ($this->current_query) {
            $this->current_query = $query;
        }
        return $this;
    }

    /**
     * @DESC         |读取当前模型的数据
     * 如果只给一个参数则当做读取主键的值
     * 如果给定一个字段，那么必填第二个参数，当做这个字段的值
     *
     * 参数区：
     *
     * @param int|string $field_or_pk_value 字段或者主键的值
     * @param null $value 字段的值，只读取主键就不填
     *
     * @return mixed
     * @throws null
     */
    public function load(int|string $field_or_pk_value, $value = null): AbstractModel
    {
        // 加载之前
        $this->load_before();
        // 清空之前的数据
        $this->clearDataObject();
        // load之前事件
        $this->getEvenManager()->dispatch($this->getOriginTableName() . '_model_load_before', ['model' => $this]);
        if (is_null($value)) {
            $data = $this->getQuery()->where($this->getQuery()->table_alias . '.' . $this->_primary_key, $field_or_pk_value)->find()->fetch();
        } else {
            $data = $this->getQuery()->where($field_or_pk_value, $value)->find()->fetch();
        }

        if (is_array($data)) {
            $this->setObjectData($data);
            $this->_model_fields_data = $data;
        }
        // load之之后事件
        $this->getEvenManager()->dispatch($this->getOriginTableName() . '_model_load_after', ['model' => $this]);
        // 加载之后
        $this->load_after();
        # 触发fetch_after
        $this->fetch_after();
        $this->clearQuery();
        return $this;
    }
    /***************便捷查询 开始 *********************/
    //    function all(): mixed
    //    {
    //        return $this->where(1)->select()->fetch();
    //    }
    /***************便捷查询 结束 *********************/
    /**
     * @DESC         |载入前
     *
     * 参数区：
     */
    public function load_before()
    {
    }

    /**
     * @DESC         |载入后
     *
     * 参数区：
     */
    public function load_after()
    {
    }

    /**
     * @DESC          # 更新
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/12/24 2:03
     * 参数区：
     *
     * @param array|string $field
     * @param string $condition_field
     *
     * @return $this
     * @throws Null
     */
    public function update(array|string $field = '', int|string $value_or_condition_field = ''): static
    {
        if (empty($value_or_condition_field)) {
            $value_or_condition_field = $this->_primary_key;
        }
        $query = $this->getQuery();
        if ($field) {
            $query->update($field, $value_or_condition_field);
            $this->setModelData($field);
        } else {
            $update = $this->getModelChangedData();
            # 没有更新则不处理
            if (empty($update)) {
                return $this;
            }
            if (empty($query->wheres) && $id = $this->getData($value_or_condition_field)) {
                $this->getQuery()->where($value_or_condition_field, $id)->update($update, $value_or_condition_field);
            } else {
                $this->getQuery()->update($update, $value_or_condition_field);
            }
        }
        return $this;
    }

    /**
     * @DESC         |保存方法
     *
     * 参数区：
     *
     * @param array|bool|AbstractModel $data
     * @param string|null $sequence
     *
     * @return bool
     */
    public function save(array|bool|AbstractModel $data = [], string|array $sequence = ''): bool|int
    {
        if (is_object($data)) {
            $this->setModelData($data->getModelData());
        } elseif (is_bool($data)) {
            $this->force_check_flag = $data;
        } elseif (is_array($data)) {
            $this->setModelData($data);
        }
        # 检测是否检查更新
        if ($sequence) {
            if (is_array($sequence)) {
                $this->force_check_fields = $sequence;
            } else {
                $this->force_check_fields = [$sequence => $sequence];
            }
        } elseif (empty($this->force_check_fields)) {
            if ($this->_primary_key) {
                $this->force_check_fields = [$this->_primary_key];
            }
            if ($this->_unit_primary_keys) {
                $this->force_check_fields = array_unique($this->_unit_primary_keys + $this->force_check_fields);
            }
        }

        # 有要检测更新的字段
        if ($this->force_check_fields) {
            foreach ($this->force_check_fields as $force_check_field) {
                if ($this->getData($force_check_field)) {
                    $this->unique_data[$force_check_field] = $this->getData($force_check_field);
                }
            }
        }

        # 如果主键有值
        if ($this->_primary_key and $this->getId()) {
            $this->unique_data[$this->_primary_key] = $this->getId();
        }

//        dd($this->unique_data);
        // 如果强制检测更新，但是没有任何条件则使用联合主键的方式进行条件装配
        if ($this->force_check_flag && empty($this->unique_data)) {
            foreach ($this->_unit_primary_keys as $unit_primary_key) {
                if ($this->getData($unit_primary_key)) {
                    $this->unique_data[$unit_primary_key] = $this->getData($unit_primary_key);
                }
            }
        }
        if ($this->_unit_unique_fields) {
            foreach ($this->_unit_unique_fields as $unit_unique_field) {
                if ($this->getData($unit_unique_field)) {
                    $this->unique_data[$unit_unique_field] = $this->getData($unit_unique_field);
                }
            }
        }
        if ($this->unique_data) {
            $this->force_check_flag = true;
        }
        // 保存前
        $this->save_before();
        // save之前事件
        $model_event_name = str_replace('\\', '_', $this::class);
        $this->getEvenManager()->dispatch($model_event_name . '_model_save_before', ['model' => $this]);
        $this->getQuery()->beginTransaction();
        try {
            if ($this->force_check_flag) {
                $save_result = $this->checkUpdateOrInsert();
            } else {
                $save_result = $this->getQuery()->clearQuery()->insert($this->getModelData())->fetch();
            }
            if (!$this->getId()) {
                $this->setData($this->_primary_key, $save_result);
            }
            $this->getQuery()->commit();
        } catch (\Exception $exception) {
            $this->getQuery()->rollBack();
            $msg = __('保存数据出错! ');
            $msg .= __('消息: %1', $exception->getMessage()) . PHP_EOL . __('预编译SQL: %1', $this->getQuery()->getPrepareSql(false)) . PHP_EOL . __('执行SQL: %1', $this->getQuery()->getSql());
            throw new Exception($msg);
        }

        // save之后事件
        $this->getEvenManager()->dispatch($model_event_name . '_model_save_after', ['model' => $this]);
        // 保存后
        $this->save_after();
        return $save_result;
    }

    public function save_before()
    {
    }

    public function save_after()
    {
    }

    /**
     * @DESC          # 【强制检测】 true:强制查询数据库，检测数据是否存在 不存在则插入记录 false:检测当前模型是否存在主键，存在则更新，不存在则插入
     *                # 【原因】 如果主键非ID自增键时，因为主键就是数据，无法检测，只能先查询后操作，遇到此类情况时使用此函数
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/14 22:49
     * 参数区：
     *
     * @param bool $force_check_flag
     * @param string|array $check_field
     *
     * @return AbstractModel
     */
    public function forceCheck(bool $force_check_flag = true, string|array $check_field = ''): AbstractModel
    {
        $this->force_check_flag = $force_check_flag;
        if ($check_field) {
            if (is_string($check_field)) {
                $this->force_check_fields[$check_field] = $check_field;
            } else {
                $this->force_check_fields = $check_field;
            }
        } else {
            if ($this->_primary_key) {
                $this->force_check_fields = [$this->_primary_key];
            }
            if ($this->_unit_primary_keys) {
                $this->force_check_fields = array_unique($this->_unit_primary_keys + $this->force_check_fields);
            }
        }
        return $this;
    }

    /**
     * @DESC          # 获取事件管理器
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/30 21:12
     * 参数区：
     * @return EventsManager
     * @throws Exception
     * @throws \ReflectionException
     */
    protected function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    public function delete_before()
    {
    }

    public function delete_after()
    {
    }

    public function clearData(bool $with_query = true): static
    {
        $this->items = [];
        $this->_fields = [];
        if ($with_query) {
            $this->_bind_query = null;
            $this->clearQuery();
        }
        $this->_model_fields_data = [];
        $this->clearDataObject();
        $this->setFetchData([]);
        return $this;
    }

    public function clear(bool $with_query = true): static
    {
        $this->items = [];
        $this->_fields = [];
        $this->setData([]);
        if ($with_query) {
            $this->_bind_query = null;
            $this->clearQuery();
        }
        $this->_model_fields_data = [];
        $this->_bind_model_fields = [];
        $this->_model_fields = [];
        $this->unique_data = [];
        $this->clearDataObject();
        $this->setFetchData([]);
        $this->getQuery()->clear();
        // 检测强制联合模型
        foreach ($this->_force_join_models as $joinData) {
            $this->joinModel(...$joinData);
        }
        return $this;
    }

    public function recovery(): static
    {
        $this->clearData();
        $this->setQuery($this->getQuery()->table($this->getOriginTableName())->identity($this->_primary_key));
        return $this;
    }

    /**
     * @DESC         |访问不存在的方法时，默认为查询
     *
     * 参数区：
     *
     * @param $method
     * @param $args
     *
     * @return array|bool|mixed|string|AbstractModel|null
     * @throws \Weline\Framework\Exception\Core
     * @throws \ReflectionException
     */
    public function __call($method, $args)
    {
        // 模型查询
        if (in_array($method, get_class_methods(QueryInterface::class))) {
            # 某些函数是不需要保持查询的
            if ($method == 'clearQuery') {
                $query = $this->getQuery(false);
            } else {
                $query = $this->getQuery();
            }
            if ($method == 'insert') {
                $this->is_insert = true;
            }
            if ($method == 'delete') {
                $this->is_delete = true;
                // load之前事件
                if ($this->getId()) {
                    $this->getQuery()->where($this->_primary_key, $this->getId())->delete();
                } elseif ($this->getQuery()->wheres) { # 存在条件，则按照条件所指删除
                    $this->getQuery()->delete();
                } elseif ($this->_unit_primary_keys) { # 处理联合化主键的情况
                    foreach ($this->_unit_primary_keys as $unit_primary_key) {
                        if (empty($this->getData($unit_primary_key))) {
                            throw new Core(__('删除条件不能为空：确保模型存在要删除的指定主键值，或者存在查询条件!'));
                        }
                        $query->where($unit_primary_key, $this->getData($unit_primary_key));
                    }
                    $query->delete();
                } else {
                    throw new Core(__('删除条件不能为空：确保模型存在要删除的指定主键值，或者存在查询条件!'));
                }
            }
            if ($method == 'update') {
                $this->is_update = true;
            }
            if ($method == 'total') {
                return $query->$method(...$args);
            }
            # 注入选择的模型字段
            if ($method == 'fields') {
                $fields = explode(',', ...$args);
                foreach ($fields as &$field) {
                    if (is_int(strpos($field, '.'))) {
                        $fields_array = explode('.', $field);
                        $field = array_pop($fields_array);
                    }
                    # 别名
                    if (is_int(strpos($field, 'as'))) {
                        $fields_array = explode('as', $field);
                        $field = array_pop($fields_array);
                    }
                }
                $this->bindModelFields($fields);
            }
            # 非链式操作的Fetch
            $is_fetch = false;
            # 拦截fetch操作 注入返回的模型
            if ('fetch' === $method) {
                if ($this->is_delete) {
                    // 加载之前
                    $this->delete_before();
                    $this->getEvenManager()->dispatch($this->processTable() . '_model_delete_before', ['model' => $this]);
                }
                # 如果是空数据更新
                if (!trim($this->getQuery()->getPrepareSql(false))) {
                    return $this;
                }
                $args[] = $this::class;
                $is_fetch = true;
            }

            $query_data = $query->$method(...$args);
            if ($query_data instanceof QueryInterface) {
                $this->setQuery($query_data);
            }
            $this->setQueryData($query_data);
            if ('fetchArray' === $method) {
                return $query_data;
            }
            # 拦截fetch返回的数据注入模型
            if ($is_fetch) {
                if ($this->is_delete) {
                    $this->clearData();
                    // load之之后事件
                    $this->getEvenManager()->dispatch($this->processTable() . '_model_delete_after', ['model' => $this]);
                    // 加载之后
                    $this->delete_after();
                }
                $this->fetch_before();
                if (is_object($query_data)) {
                    /**@var AbstractModel $query_data */
                    $this->setFetchData($query_data->getData());
                    $this->setObjectData($query_data->getData());
                } elseif (is_array($query_data)) {
                    $this->setFetchData($query_data);
                    $this->setObjectData($query_data);
                } elseif ($this->is_insert and (is_numeric($query_data) or is_string($query_data))) {
                    $this->setId($query_data);
                }
                $this->fetch_after();
                $this->clearQuery();
                $this->is_delete = false;
                $this->is_update = false;
                # 清除当前查询
                return $this;
            }
            $query_methods = [
                'getPrepareSql',
                'getSql',
            ];
            if (in_array($method, $query_methods)) {
                return $query_data;
            }
            return $this;
        }
        /**
         * 重载方法
         */
        return parent::__call($method, $args);
    }

    protected function setQueryData($query_data)
    {
        $this->_query_data = $query_data;
        return $this;
    }

    public function getQueryData()
    {
        return $this->_query_data;
    }

    public function fetch_before()
    {
    }

    public function fetch_after()
    {
    }

    /**
     * @DESC          # 设置取得的数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/22 19:32
     * 参数区：
     *
     * @param AbstractModel[] $value
     */
    public function setFetchData(array $value): self
    {
        $this->_fetch_data = $value;
        $this->setItems($value);
        return $this;
    }

    /**
     * @DESC          # 获取查询数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/22 19:32
     * 参数区：
     */
    public function getFetchData(): mixed
    {
        return $this->_fetch_data;
    }

    /**
     * @return \Weline\Framework\Database\Model[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function setItems(array $items): self
    {
        $this->items = [];
        foreach ($items as $item) {
            if ($item instanceof AbstractModel) {
                $this->addItem($item);
            } else {
                if (is_array($item)) {
                    $model = $this::class;
                    $this->addItem(new $model($item));
                }
            }
        }
        return $this;
    }

    public function addItem(AbstractModel $item): self
    {
        //        $classRef= ObjectManager::getReflectionInstance($item::class);
        //        $properties         = isset($property_scope) ? $classRef->getProperties($property_scope) : $classRef->getProperties();
        //        # 卸载模型属性
        //        foreach ($properties as $property) {
        //            // 为了兼容反射私有属性
        //            $property->setAccessible(true);
        //            // 当不想获取静态属性时
        //            if ($property->isStatic()) {
        //                continue;
        //            }
        //            if ($property->isPublic()) {
        //                $attr = $property->getName();
        //                unset($item->$attr);
        //            }
        //        }
        //        p((object)$item);
        $this->items[] = $item;
        return $this;
    }

    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if ($is_unique) {
            $this->forceCheck(true, $key);
            $this->unique_data[$key] = $value;
            $this->remove_force_check_field = true;
        }
        $this->set_data_before($key, $value);
        if (is_array($key)) {
            $this->_model_fields_data = $key;
        } else {
            $this->_model_fields_data[$key] = $value;
        }
        parent::setData($key, $value);
        $this->set_data_after($key, $value);
        return $this;
    }

    /**
     * @DESC          # 设置模型字段数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/5/31 20:27
     * 参数区：
     *
     * @param array $data
     *
     * @return $this
     */
    public function setModelFieldsData(array $data): static
    {
        $modelFields = $this->getModelFields();
        foreach ($data as $key => $value) {
            if (in_array($key, $modelFields)) {
                $this->setData($key, $value);
                $this->_model_fields_data[$key] = $value;
            }
        }
        return $this;
    }

    public function getModelFieldsData(bool $filter_origin_model_data = false): array
    {
        if (!$filter_origin_model_data) {
            return $this->_model_fields_data;
        }
        $modelFields = $this->getModelFields();
        $modelFieldsData = [];
        foreach ($this->_model_fields_data as $key => $value) {
            if (!in_array($key, $modelFields)) {
                $modelFieldsData[$key] = $this->_model_fields_data[$key];
            }
        }
        return $modelFieldsData;
    }

    public function set_data_before(mixed $key, mixed $value = null)
    {
    }

    public function set_data_after(mixed $key, mixed $value = null)
    {
    }


    /**----------参数获取---------------*/

    /**
     * @DESC          # 读取模型的主键字段值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:54
     * 参数区：
     */
    public function getId(mixed $default = 0)
    {
        if (!$this->_primary_key) {
            return $default;
        }
        return $this->getData($this->_primary_key) ?: $default;
    }

    /**
     * @DESC          # 设置模型的主键字段值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/8/26 21:54
     * 参数区：
     */
    public function setId($primary_id): AbstractModel
    {
        return $this->setData($this->_primary_key, $primary_id);
    }

    public function getCreateTime()
    {
        return $this->getData(self::fields_CREATE_TIME);
    }

    public function setCreateTime(string $create_time): static
    {
        return $this->setData(self::fields_CREATE_TIME, $create_time);
    }

    public function getUpdateTime()
    {
        return $this->getData(self::fields_UPDATE_TIME);
    }

    public function setUpdateTime(string $update_time): static
    {
        return $this->setData(self::fields_UPDATE_TIME, $update_time);
    }

    public function getModelFields(bool $remove_primary_key = false, bool $remove_force_check_fields = false): array
    {
        //        if (!$remove_force_check_fields&&$_model_fields=$this->_model_fields) {
        //            return array_unique(array_merge($_model_fields, array_values($this->force_check_fields)));
        //        }
        $module__fields_cache_key = $this::class . '_module__fields_cache_key';
        if (PROD && $_model_fields = $this->_cache->get($module__fields_cache_key)) {
            $this->_model_fields = $_model_fields;
            if (!$remove_force_check_fields) {
                return array_unique(array_merge($_model_fields, array_values($this->force_check_fields)));
            }
            return $_model_fields;
        }
        $objClass = new \ReflectionClass($this::class);
        $arrConst = $objClass->getConstants();
        //        if ($this::class === \Weline\Theme\Model\WelineTheme::class) p($arrConst,1);
        $_fields = [];
        foreach ($arrConst as $key => $val) {
            if ($val && $key !== 'fields_ID' && str_starts_with($key, 'fields_')) {
                $_fields[] = $val;
            }
        }
        if (!$remove_primary_key) {
            $_fields[] = $this->_primary_key;
        }
        $_fields = array_unique($_fields);
        # 是否移除强制检测的字段
        if ($remove_force_check_fields && $this->force_check_flag && $this->force_check_fields) {
            foreach ($_fields as $key => $field) {
                if (in_array($field, $this->force_check_fields)) {
                    unset($_fields[$key]);
                }
            }
        }
        $this->_model_fields = $_fields;
        if (PROD) {
            $this->_cache->set($module__fields_cache_key, $_fields);
        }
        if (!$remove_force_check_fields) {
            return array_unique(array_merge($_fields, array_values($this->force_check_fields)));
        }
        return $_fields;
    }

    public function bindModelFields(array $fields, string $alias = ''): static
    {
        foreach ($fields as $key => $bind_field) {
            if (in_array($bind_field, $this->_model_fields)) {
                unset($fields[$key]);
            }
        }
        $model_fields = array_merge($fields, $this->_model_fields);
        $model_fields = array_unique($model_fields);
        // 遇到as读取最后一个
        foreach ($model_fields as $key => $model_field) {
            if (str_contains($model_field, 'as')) {
                $model_field = explode('as', $model_field);
                $model_field = trim(array_pop($model_field), ' ');
                if ($alias) {
                    $model_field = ltrim($model_field, $alias . '_');
                };
                $this->_model_fields[$key] = $model_field;
            }
        }
        return $this;
    }

    /**
     * @DESC          # 返回模型数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/16 17:09
     * 参数区：
     *
     * @param string $field
     *
     * @return array|string
     */
    public function getModelData(string $field = ''): array|string
    {
        if (empty($this->_model_fields_data) && $data = $this->getData()) {
            //            $need_fill_fields = [];
            foreach ($this->getModelFields() as $key => $val) {
                if (isset($data[$val])) {
                    $field_data = $data[$val];
                    # 设置沿用主模型的数据
                    //                if ($val !== $this->_primary_key && $field_data && $datas = $this->getId()) {
                    //                    if (!isset($datas[0][$val])) {
                    //                        foreach ($datas as &$data_val) {
                    //                            $data_val[$val] = $field_data;
                    //                        }
                    //                        $this->setData($this->_primary_key, $datas);
                    //                    }
                    //                }
                    if (($val === $this::fields_CREATE_TIME || $val === $this::fields_UPDATE_TIME) && empty($field_data)) {
                        $field_data = date('Y-m-d H:i:s');
                    }
                    $this->_model_fields_data[$val] = $field_data;
                }
            }
        }
        if ($field && isset($this->_model_fields_data[$field]) && $field_data = $this->_model_fields_data[$field]) {
            return $field_data;
        }
        if ($field) {
            return '';
        }
        return $this->_model_fields_data;
    }

    /**
     * 获取模型变化的数据
     * @param string $field
     * @return array|string
     */
    public function getModelChangedData(string $field = ''): array|string
    {
        $data = $this->getModelData($field);
        if ($field) {
            if (isset($data[$field]) && $changed_data = $this->getChangedData($field)) {
                return $changed_data;
            } else {
                return '';
            }
        } else {
            $changed_data = $this->getChangedData();
            $changed_model_data = [];
            foreach ($data as $field => $datum) {
                if (isset($changed_data[$field])) {
                    $changed_model_data[$field] = $changed_data[$field];
                }
            }
            return $changed_model_data;
        }
    }

    /**
     * @DESC          # 设置模型数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/16 17:09
     * 参数区：
     *
     * @param array|string $key
     * @param mixed $value
     *
     * @return AbstractModel
     */
    public function setModelData(array|string $key, mixed $value = ''): static
    {
        if (is_array($key)) {
            # 如果Key是批量数据 将主数据对应的 键值对 添加到批量数据中
            if (isset($key[0])) {
                foreach ($key as &$fv) {
                    $common_datas = $this->getData();
                    foreach ($common_datas as $common_key => $common_data) {
                        if (!isset($common_data[$common_key])) {
                            $fv[$common_key] = $common_data;
                        }
                    }
                }
            }
            $this->setData($key);
        } else {
            $this->setData($key, $value);
        }
        $this->_model_fields_data = $this->getModelData();
        return $this;
    }

    /**
     * @DESC          # 设置模型数据
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/16 17:09
     * 参数区：
     *
     * @param array|string $key
     *
     * @return AbstractModel
     */
    public function unsetModelData(array|string $key): static
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                unset($this->_model_fields_data[$item]);
            }
        } else {
            unset($this->_model_fields_data[$key]);
        }
        return $this;
    }

    /**
     * @DESC          # 处理分页
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/7/7 22:22
     * 参数区：
     *
     * @param int $page 页码
     * @param int $pageSize 页大小
     * @param array $params 请求参数
     *
     * @return AbstractModel|$this
     * @throws Exception
     * @throws \ReflectionException
     *
     */
    public function pagination(int $page = 0, int $pageSize = 0, array $params = [], int $max_limit = 1000, int $total = 0): AbstractModel|static
    {
        if ($pageSize > $max_limit) {
            throw new Exception(__('分页超过每页限制大小！限制每页大小：%1', $max_limit));
        }
        if (empty($page)) {
            $page = ObjectManager::getInstance(Request::class)->getGet('page', 1) ?: 1;
        }
        if (empty($pageSize)) {
            $pageSize = ObjectManager::getInstance(Request::class)->getGet('pageSize', 10) ?: 10;
        }
        if (empty($params)) {
            $params = ObjectManager::getInstance(Request::class)->getGet();
        }
        $this->setQuery($this->getQuery()->pagination($page, $pageSize, $params, $max_limit, $total));
        $this->pagination = $this->getQuery()->pagination;
        $this->setData('pagination', $this->getPagination());
        return $this;
    }

    public function getPaginationData(string $url_path = '', string $pagination_style = 'pagination-rounded'): array
    {
        # 分页数据存在
        if (isset($this->pagination['lastPage']) && $this->pagination['lastPage'] < 2) {
            $this->pagination['html'] = '';
            return $this->pagination;
        }
        # 分页数据存在
        if (isset($this->pagination['html'])) {
            return $this->pagination;
        }
        $this->pagination['path'] = $url_path;
        # 上一页
        if (($this->pagination['page'] - 1) > 0) {
            $this->pagination['prePage'] = $this->pagination['page'] - 1;
        } else {
            $this->pagination['prePage'] = 0;
        }
        # 下一页
        if ($this->pagination['page'] < $this->pagination['lastPage']) {
            $this->pagination['nextPage'] = $this->pagination['page'] + 1;
        } else {
            $this->pagination['nextPage'] = $this->pagination['lastPage'];
        }
        $hasPrePage = intval($this->pagination['prePage']) !== 0;
        $this->pagination['hasPrePage'] = $hasPrePage;
        $hasNextPage = (intval($this->pagination['page']) < intval($this->pagination['lastPage']));
        $this->pagination['hasNextPage'] = $hasNextPage;
        /**@var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $this->pagination['lang'] = \Weline\Framework\Http\Cookie::getLangLocal();
        $this->pagination['uri'] = $request->getUri();

        # 页码缓存
        $cache_key = md5(json_encode($this->pagination));
        if ($data = $this->_cache->get($cache_key)) {
            $this->pagination = $data;
            return $data;
        }
        /**@var Url $url_builder */
        $url_builder = ObjectManager::getInstance(Url::class);
        $params = $this->pagination['params'];
        unset($params['page']);
        unset($params['pageSize']);
        $query_flag = $params ? '&' : '?';
        $queryUrl = $request->isBackend() ? $url_builder->getBackendUrl($url_path, $params) : $url_builder->getUrl($url_path, $params);
        $prePageName = __('上一页');
        unset($params);
        $prePageClassStatus = $hasPrePage ? '' : 'disabled';
        $params['page'] = $this->pagination['prePage'];
        $params['pageSize'] = $this->pagination['pageSize'];
        $query = http_build_query($params);
        $prePageUrl = $hasPrePage ? $queryUrl . $query_flag . $query :
            '#';

        $page_list_html = '';
        $page = intval($this->pagination['page']);
        $lastPage = intval($this->pagination['lastPage']);
        $have_after_more = false;
        $have_pre_more = false;
        for ($i = 1; $i <= $lastPage; $i++) {
            if ($i < $page - 3) {
                if (!$have_pre_more) {
                    $page_list_html .= "<li class='page-item'><a class='page-link' href='#' >...</a> </li>";
                    $have_pre_more = true;
                }
                continue;
            }
            $pageActiveStatus = ($page === $i) ? 'active' : '';
            $params['page'] = $i;
            $params['pageSize'] = $this->pagination['pageSize'];
            $query = http_build_query($params);
            $pageUrl = $queryUrl . $query_flag . $query;
            if ($i > $page + 3) {
                if (!$have_after_more) {
                    $page_list_html .= "<li class='page-item'><a class='page-link' href='#' >...</a> </li>";
                    $have_after_more = true;
                }
                continue;
            }
            $page_list_html .= <<<PAGELISTHTML
<li class='page-item {$pageActiveStatus}'><a
                    class='page-link'
                    href='{$pageUrl}'>{$i}</a>
            </li>
PAGELISTHTML;
        }

        $nextPageName = __('下一页');
        $params['page'] = 1;
        $params['pageSize'] = $this->pagination['pageSize'];
        $query = http_build_query($params);
        $firstPageUrl = $queryUrl . $query_flag . $query;
        $firstPageName = __('首页');
        $nextPageClassStatus = $hasNextPage ? '' : 'disabled';
        $params['page'] = $this->pagination['nextPage'];
        $params['pageSize'] = $this->pagination['pageSize'];
        $query = http_build_query($params);
        $nextPageUrl = $hasNextPage ? $queryUrl . $query_flag . $query : '#';
        $params['page'] = $lastPage;
        $params['pageSize'] = $this->pagination['pageSize'];
        $query = http_build_query($params);
        $lastPageUrl = $queryUrl . $query_flag . $query;
        $lastPageName = __('最后一页');
        $total_page = __('一共 %1 页', $lastPage);
        $please_input_page_number = __('请输入页码');
        $turn_to_page = __('跳转页');
        $params['page'] = '';
        $params['pageSize'] = $this->pagination['pageSize'];
        $query = http_build_query($params);
        $form_url = $queryUrl . $query_flag . $query;
        $this->pagination['html'] = <<<PAGINATION
<nav aria-label='...'>
                            <ul class='pagination {$pagination_style}'>
                                <li class='page-item'>
                                    <a class='page-link'
                                       href='{$firstPageUrl}'>{$firstPageName}</a>
                                </li>
                                <li class='page-item {$prePageClassStatus}'>
                                    <a class='page-link'
                                       href='{$prePageUrl}'
                                       tabindex='-1'>{$prePageName}</a>
                                </li>
                                {$page_list_html}
                                <li class='page-item {$nextPageClassStatus}'>
                                    <a class='page-link'
                                       href='{$nextPageUrl}'>{$nextPageName}</a>
                                </li>
                                <li class='page-item'>
                                    <a class='page-link'
                                       href='{$lastPageUrl}'>{$lastPageName}</a>
                                </li>
                                <li class='page-item disabled'>
                                    <a class='page-link'
                                       href='#'>{$total_page}</a>
                                </li>
                                <li class='page-item'>
                                      <form action="{$form_url}" method="get" class="btn-group">
                                        <input type="text" class="page-link" name="page" placeholder="{$please_input_page_number}">
                                        <button type="submit" class="btn btn-primary page-link">{$turn_to_page}</button>
                                      </form>
                                </li>
                            </ul>
                        </nav>
PAGINATION;
        # 页码缓存
        $this->_cache->set($cache_key, $this->pagination);
        return $this->pagination;
    }

    public function getPagination(string $pagination_style = 'pagination-rounded', string $url_path = ''): string
    {
        return $this->getPaginationData($url_path, $pagination_style)['html'] ?? '';
    }

    /**----------链接查询--------------*/

    public function bindQuery(QueryInterface &$query): static
    {
        $query->_index_sort_keys = array_unique([...$query->_index_sort_keys, ...$this->_unit_primary_keys, ...$this->_index_sort_keys]);
        $this->_bind_query = $query;
        return $this;
    }

    public function clearJoin()
    {
        $this->_bind_model_fields = [];
    }

    public function joinModel(AbstractModel|string $model, string $alias = '', $condition = '', $type = 'LEFT', string $fields = '*'): AbstractModel
    {
        // init方法调用的join常驻
        $trace = debug_backtrace();
        $caller = $trace[1];
        if ($caller['function'] === '__init') {
            $this->_force_join_models[is_string($model) ?: $model::class] = func_get_args();
        }
        // 查询处理
        $query = $this->getQuery();
        if (is_string($model)) {
            /**@var Model $model */
            $model = ObjectManager::getInstance($model);
            $model->bindQuery($query);
            $model->alias($alias);
        }

        # 自动设置条件
        $model_table = $model->getTable();
        if (empty($condition)) {
            $condition = "`{$model->getQuery()->table_alias}`.`{$model->getIdField()}`=`{$alias}`.`{$model->getIdField()}`";
        }
        if (empty($this->_join_model_fields)) {
            $this->_join_model_fields = $this->getModelFields();
        }

        if ($fields === '*') {
            $model_fields = '';
            foreach ($model->getModelFields() as $modelField) {
                if (in_array($modelField, $this->_join_model_fields) or str_contains($query->fields, $modelField)) {
                    $model_fields .= "`$alias`.$modelField as {$alias}_{$modelField},";
                    $this->_bind_model_fields["`$alias`" . $modelField] = "`$alias`.$modelField as {$alias}_{$modelField}";
                    $model->_bind_model_fields["`$alias`" . $modelField] = "`$alias`.$modelField as {$alias}_{$modelField}";
                } else {
                    $this->_bind_model_fields["`$alias`" . $modelField] = "`$alias`.$modelField";
                    $model->_bind_model_fields["`$alias`" . $modelField] = "`$alias`.$modelField";
                    $model_fields .= "`$alias`.$modelField,";
                }
            }
            $model_fields = rtrim($model_fields, ',');
            $this->bindModelFields(explode(',', $model_fields), $alias);

            if ($this->_bind_model_fields) {
                $model_fields .= ',' . (implode(',', $this->_bind_model_fields));
            }

            $query->fields(($query->fields !== '*') ? $query->fields . ',' . $model_fields : $model_fields);
            $query->fields($query->fields . ',' . $model_fields);
        } else {
            $this->bindModelFields(explode(',', $fields), $alias);
            //            $query->fields(($query->fields !== '*') ? $query->fields . ',' . $fields : $fields);
            $query->fields($query->fields . ',' . $fields);
        }
        $query->join($model_table . ($alias ? " `{$alias}`" : ''), $condition, $type);
        return $this->bindQuery($query);
    }


    /**缓存控制*/

    public function useCache(bool $cache = true)
    {
        $this->use_cache = $cache;
        return $this;
    }

    public function getCache(string $key)
    {
        if ($this->use_cache) {
            return $this->_cache->get($key);
        }
        return null;
    }

    public function setCache(string $key, mixed $value, $duration = 1800)
    {
        return $this->_cache->set($key, $value, $duration);
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/10/9 21:14
     * 参数区：
     * @return mixed
     * @throws Exception
     * @throws \ReflectionException
     */
    private function checkUpdateOrInsert(): mixed
    {
        if ($this->unique_data) {
            $check_result = $this->getQuery()->where($this->unique_data)->find()->fetchArray() ?? [];
        } else {
            $check_result = $this->unique_data;
        }
        # 存在更新
        if (isset($check_result[$this->_primary_key])) {
            # 新增更新依赖主键
            $this->setId($check_result[$this->_primary_key]);
            $is_addition_identity = false;
            if (empty($this->unique_data[$this->_primary_key])) {
                $this->unique_data[$this->_primary_key] = $check_result[$this->_primary_key];
                $is_addition_identity = true;
            }
            # 获取变更数据
            $data = $this->getModelChangedData();

            if (!$data) {
                return $check_result[$this->_primary_key];
            }
            # 出去条件中的唯一值
            foreach ($this->unique_data as $f => $v) {
                if (isset($data[$f])) {
                    unset($data[$f]);
                }
            }

            # 条件中有主键时，去除主键
            if ($this->force_check_fields and !in_array($this->_primary_key, $this->force_check_fields)) {
                unset($data[$this->_primary_key]);
            }
            if (empty($data)) {
                return $check_result[$this->_primary_key];
            }

            $save_result = $this->getQuery()
                ->where($this->unique_data)
                ->update($data)
                ->fetch();
            if ($is_addition_identity) {
                unset($this->unique_data[$this->_primary_key]);
                $data[$this->_primary_key] = $check_result[$this->_primary_key];
            }
            # 更新数据
            $this->setData($data);
        } else {
            $unique_fields = array_keys($this->unique_data);
            $this->_unit_primary_keys = array_unique(array_merge($this->_unit_primary_keys, $unique_fields));
            $save_result = $this->getQuery()
                ->insert($this->getModelData(), $this->unique_data ? array_keys($this->unique_data) : $this->_unit_primary_keys)
                ->fetch();
            $this->setId($save_result);
        }
        return $save_result;
    }

}
