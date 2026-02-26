# Block 开发技能

## 触发关键词

Block, 区块, 视图, View, 模板, template, .phtml, render, 渲染, 继承 Block, extends Block, BlockInterface, $_template, assign, getData, setData, __init

## 适用场景

- 创建新的 Block 类
- Block 与模板关联
- Block 中数据获取和传递
- Block 缓存使用
- 在模板中使用 `<block>` 标签

---

## 1. Block 类的基础结构

### 1.1 继承层次

```
DataObject (数据容器)
    └── Template (模板处理)
            └── Block (实现 BlockInterface)
                    └── 自定义 Block 类
```

### 1.2 核心文件

- 基类：`Weline\Framework\View\Block`
- 接口：`Weline\Framework\View\BlockInterface`
- 父类：`Weline\Framework\View\Template`

---

## 2. Block 类创建规范

### 2.1 基础 Block（只定义模板）

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Block;

use Weline\Framework\View\Block;

class YourBlock extends Block
{
    // 模板路径格式：模块名::相对路径.phtml
    protected string $_template = 'Weline_YourModule::your-block.phtml';
}
```

### 2.2 带依赖注入的 Block

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Block;

use Weline\Framework\View\Block;
use Weline\YourModule\Model\YourModel;

class YourBlock extends Block
{
    protected string $_template = 'Weline_YourModule::your-block.phtml';
    
    private YourModel $model;

    // $data = [] 必须放在最后！
    public function __construct(
        YourModel $model,
        array $data = []
    ) {
        $this->model = $model;
        parent::__construct($data);  // 必须调用父构造函数
    }
    
    // __init() 必须调用 parent::__init()
    public function __init()
    {
        parent::__init();
        
        // 在这里准备数据
        $this->assign('items', $this->model->getList());
    }
}
```

### 2.3 复杂 Block（含业务逻辑）

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Block;

use Weline\Framework\View\Block;

class ComplexBlock extends Block
{
    protected string $_template = 'Weline_YourModule::complex-block.phtml';
    
    private YourService $service;

    public function __construct(
        YourService $service,
        array $data = []
    ) {
        $this->service = $service;
        parent::__construct($data);
    }

    public function __init()
    {
        parent::__init();
        
        // 解析标签传递的参数
        $actionParams = $this->getParseVarsParams('action-params');
        
        // 获取传入数据
        $entityCode = $this->getData('entity_code');
        
        // 处理并分配数据
        $this->assign('processed_data', $this->processData($entityCode));
    }
    
    // 重写 render() 时必须调用 parent::render()
    public function render(): string
    {
        // 准备更多数据
        $this->assign('extra_data', $this->getExtraData());
        
        return parent::render();
    }
    
    // 公共方法可在模板中通过 $this 调用
    public function getItemUrl(int $id): string
    {
        return $this->getUrl('your/path', ['id' => $id]);
    }
}
```

---

## 3. 模板路径格式

### 3.1 格式规范

```
模块名::相对路径.phtml
```

### 3.2 文件位置对应

| 模板路径 | 实际文件位置 |
|---------|-------------|
| `Weline_Admin::backend/top-bar.phtml` | `app/code/Weline/Admin/view/blocks/backend/top-bar.phtml` |
| `Weline_Component::off-canvas.phtml` | `app/code/Weline/Component/view/blocks/off-canvas.phtml` |

### 3.3 目录结构

```
app/code/Vendor/Module/
├── Block/
│   └── YourBlock.php           # Block 类
└── view/
    └── blocks/                  # Block 模板目录
        └── your-block.phtml     # 模板文件
```

---

## 4. 数据操作方法

### 4.1 常用方法（继承自 DataObject）

| 方法 | 描述 | 示例 |
|------|------|------|
| `getData($key)` | 获取数据 | `$this->getData('entity_code')` |
| `setData($key, $value)` | 设置数据 | `$this->setData('items', $items)` |
| `assign($key, $value)` | 分配变量到模板 | `$this->assign('users', $users)` |
| `addData($array)` | 批量添加数据 | `$this->addData(['k1' => 'v1'])` |
| `hasData($key)` | 检查数据存在 | `$this->hasData('items')` |
| `unsetData($key)` | 删除数据 | `$this->unsetData('temp')` |

### 4.2 路径访问

```php
// 支持嵌套数据访问
$this->getData('user/profile/name');  // 斜杠分隔
$this->getData('user.profile.name');  // 点号分隔
```

### 4.3 URL 生成方法

| 方法 | 描述 |
|------|------|
| `getUrl($path, $params)` | 生成前端 URL |
| `getBackendUrl($path, $params)` | 生成后台 URL |
| `getFrontendUrl($path, $params)` | 生成前端 URL |
| `getBackendApi($path, $params)` | 生成后台 API URL |

---

## 5. 在模板中使用 `<block>` 标签

### 5.1 基本用法

```html
<!-- 基本调用 -->
<block class="Weline\Admin\Block\Backend\Page\Topbar"/>

<!-- 指定模板 -->
<block class="Weline\Component\Block\OffCanvas" 
       template="Weline_Component::off-canvas.phtml"/>

<!-- 传递变量 -->
<block class="Weline\Component\Block\Form\Search" 
       id="entity-search"
       action="*/backend/entity" 
       vars="req"/>

<!-- 设置缓存（秒） -->
<block class="..." template="..." cache="300"/>
```

### 5.2 `@block()` 简写格式

```html
@block(Weline\Admin\Block\Demo|Weline_Admin::block/demo.phtml)
@block(Weline\Admin\Block\Demo|template=Weline_Admin::block/demo.phtml|cache=300)
```

### 5.3 `vars` 属性传递变量

```html
<!-- 父模板中 -->
<block class="..." vars="item|pageSize|page"/>
```

```php
// Block 中解析
public function __init()
{
    parent::__init();
    
    // 获取单个变量
    $value = $this->getParseVarsParams('value');
    
    // 获取多参数格式：{key1:var1.field,key2:var2.field}
    $params = $this->getParseVarsParams('action-params');
}
```

---

## 6. 模板中访问 Block

### 6.1 通过 `$this`

```php
<!-- $this 指向 Block 实例 -->
<?= $this->getData('connector') ?>
<?= $this->getUrl('path/to/action') ?>
<?= $this->customMethod() ?>
```

### 6.2 通过 assign 分配的变量

```php
<!-- 直接访问（通过 assign 分配的） -->
<?= $connector ?>
<?= $languages ?>
```

---

## 7. Block 缓存

### 7.1 通过标签属性

```html
<!-- 缓存 300 秒 -->
<block class="..." template="..." cache="300"/>

<!-- 不缓存 -->
<block class="..." cache="0"/>
```

### 7.2 在 Block 内部使用缓存

```php
public function getExpensiveData(): array
{
    $cacheKey = 'my_block_data_' . $this->getData('entity_id');
    
    // 尝试从缓存获取
    if ($cached = $this->_cache->get($cacheKey)) {
        return $cached;
    }
    
    // 计算数据
    $data = $this->computeExpensiveData();
    
    // 写入缓存
    $this->_cache->set($cacheKey, $data, 3600);
    
    return $data;
}
```

---

## 8. 常见错误和注意事项

### 8.1 必须调用 `parent::__init()`

```php
// ❌ 错误
public function __init()
{
    $this->assign('data', $this->processData());  // 忘记调用 parent
}

// ✅ 正确
public function __init()
{
    parent::__init();  // 必须调用！
    $this->assign('data', $this->processData());
}
```

### 8.2 模板路径格式

```php
// ❌ 错误
protected string $_template = 'block/demo.phtml';           // 缺少模块名
protected string $_template = 'Weline_Admin/block/demo.phtml';  // 格式错误

// ✅ 正确
protected string $_template = 'Weline_Admin::block/demo.phtml';  // 使用 ::
```

### 8.3 构造函数参数顺序

```php
// ❌ 错误：$data 不在最后
public function __construct(array $data = [], MyService $service)

// ✅ 正确：$data 必须在最后
public function __construct(MyService $service, array $data = [])
{
    $this->service = $service;
    parent::__construct($data);
}
```

### 8.4 使用 `clone` 避免模型污染

```php
// ❌ 错误：直接使用注入的模型
public function getEntity(): ?EavEntity
{
    $this->eavEntity->loadByCode($code);  // 会污染共享实例
    return $this->eavEntity;
}

// ✅ 正确：克隆后使用
public function getEntity(): ?EavEntity
{
    $entity = clone $this->eavEntity;
    $entity->loadByCode($code);
    return $entity;
}
```

---

## 9. 规范总结

| 项目 | 规范 |
|------|------|
| 继承 | 必须继承 `\Weline\Framework\View\Block` |
| `$_template` | 使用 `模块名::路径.phtml` 格式 |
| `__construct` | 依赖在前，`$data = []` 在最后，必须调用 `parent::__construct($data)` |
| `__init()` | 必须调用 `parent::__init()`，用于初始化逻辑 |
| 数据操作 | 使用 `getData()`/`setData()`/`assign()` |
| URL 生成 | 使用 `getUrl()`/`getBackendUrl()` |
| 文件位置 | Block 类：`Block/`；模板：`view/blocks/` |
