<!-- Sync Impact Report -->
<!-- Version change: 2.14.2 → 2.14.3 (PATCH) -->
<!-- Modified principles: XIII.D (HTTP请求前后端区分规范) - 修正 -b 参数的描述，去除具体前缀假设 -->
<!-- Modified sections: 修正 XIII.D. HTTP请求前后端区分规范 -->
<!-- Clarifications applied: -->
<!-- 1. -b 参数会自动解析path请求地址为后端地址（不假设具体前缀，由框架处理） -->
<!-- 2. url如果404可能需要确认控制器路由是否存在，或者没有更新系统路由（setup:upgrade） -->
<!-- Modification reason: 用户澄清 -b 参数只是自动解析为后端地址，并不一定是 /admin 前缀 -->
<!-- Changes made: -->
<!-- - 移除所有关于 /admin 前缀的具体说明 -->
<!-- - 改为通用描述：-b 参数自动解析为后端地址 -->
<!-- - 保留404错误排查步骤（setup:upgrade → route:list → 参数检查） -->
<!-- - 更新所有说明和示例，不再假设具体的URL前缀 -->
<!-- Templates requiring updates: ✅ 无需更新模板（纯测试规范澄清） -->
<!-- Consistency validation: Constitution clarification on 2025-10-13 -->
<!-- - Removed all specific /admin prefix assumptions -->
<!-- - Changed to generic "backend address" terminology -->
<!-- - Maintained 404 debugging workflow -->
<!-- Follow-up TODOs: -->
<!-- - TODO(DEV_DOC_UPDATE): review and update framework dev docs with Offcanvas examples -->
<!-- - TODO(DOC_HTTP_REQUEST): add php bin/w http:request usage examples to quickstart/tests (include frontend/backend examples) -->
<!-- - TODO(DOC_SYNC): add doc-update checklist to CONTRIBUTING.md -->
<!-- - TODO(SCOPE_NOTICE): implement PR-time detection of out-of-scope changes and require approver -->
<!-- - TODO(ANTI_MAGENTO): add Magento pattern detection to code review checklist -->
<!-- - TODO(TEST_CLEANUP): add test file cleanup verification to CI/PR checks -->
<!-- - TODO(API_VALIDATION): add API response content validation to CI/PR checks -->
<!-- - TODO(SERVER_START_CHECK): add server start and phpunit command verification to AI workflow -->
<!-- - TODO(FORM_KEY_DOC): add getFormKey() usage examples to form development documentation -->
<!-- - TODO(STATIC_SYNTAX_CHECK): add @static() syntax validation to code review checklist -->
<!-- - TODO(ROUTE_TEST_TEMPLATE): create route unit test template in tests/ directory -->
<!-- - TODO(ROUTE_TEST_CI): add route test coverage check to CI/PR pipeline -->
<!-- - TODO(HTTP_REQUEST_CI): add http:request route testing to CI/PR pipeline -->
<!-- - TODO(MIGRATION_VALIDATION): add database migration file validation to CI/PR pipeline -->
<!-- - TODO(TPL_VALIDATION): add view/tpl directory protection validation to CI/PR checks -->
<!-- - TODO(FIX_DOC): create dedicated document for common fixes and issues (e.g., docs/common-issues.md) -->
<!-- - TODO(HTTP_FRONTEND_BACKEND): add frontend/backend URL examples to http:request documentation -->

# WelineFramework AI模块开发宪法

## 核心原则

### I. 框架一致性 (Framework Compliance)
所有开发必须严格遵循WelineFramework框架规范和现有模块模式。禁止基于个人经验或外部框架模式进行开发。每个功能实现前必须：
- 阅读相关框架开发文档
- 研究现有模块的实现模式
- 遵循框架的MVC架构设计
- 使用框架提供的ORM和工具类
- 保持与现有代码风格的一致性

### II. 测试驱动开发 (Test-Driven Development - NON-NEGOTIABLE)
所有功能必须通过测试验证，采用严格的TDD流程：
- 先编写测试用例，确保测试失败
- 实现功能使测试通过
- 重构代码保持测试通过
- 测试必须覆盖主要功能流程、用户界面可用性、数据一致性、错误处理机制
- 功能测试、界面测试、数据测试、错误处理测试缺一不可
- **MUST NOT**: ❌ **严禁删除测试文件**，即使测试暂时失败也必须修复而非删除
- **MUST**: 测试文件是代码覆盖率的保证，删除测试等于放弃质量保证
- **MUST**: 测试失败时必须修复测试或修复代码，禁止通过删除测试来"解决"问题
- **MUST**: 保持测试文件的完整性，后续代码修改需要测试来确认覆盖率正确性
- **MUST**: ❌ **禁止创建临时测试脚本**（如 `check_*.php`, `test_*.php`, `debug_*.php`, `fix_*.php`, `diagnose_*.php` 等）
- **MUST**: 所有测试和调试代码必须使用单元测试（PHPUnit）实现，位于 `Test/Unit/` 目录
- **MUST**: 调试逻辑应写在测试方法中，使用断言验证预期行为，而非临时脚本输出调试信息
- **Rationale**: 单元测试可复用、可版本控制、可持续验证，临时脚本会累积垃圾文件且无法保证质量

### III. 模块化设计 (Modular Architecture)
AI模块必须采用WelineFramework的模块化设计原则：
- 每个组件独立可测试
- 清晰的模块分离和依赖管理
- 遵循框架的目录结构和命名规范
- 支持模块的独立部署和维护
- 与其他模块的松耦合集成

### IV. 多租户数据隔离 (Multi-Tenant Data Isolation)
企业级多租户支持是核心要求：
- 所有数据查询必须包含租户ID过滤
- 租户级别的配置和权限管理
- 数据完全隔离，确保安全性
- 支持租户级别的资源配额管理
- 实现租户上下文中间件

### V. 国际化支持 (Internationalization Support)
完整的多语言支持：
- 依赖I18n模块的现有数据结构
- 支持多语言界面和内容
- 实现动态语言切换
- 提供多语言API响应
- 支持API版本的语言本地化

### VI. 安全与合规 (Security & Compliance)
企业级安全要求：
- API密钥和敏感信息加密存储
- 输入输出审计与内容安全检查
- 基于角色的权限控制
- 完整的操作审计日志
- 数据保护法规遵循

### VII. 性能与可扩展性 (Performance & Scalability)
高性能和可扩展架构：
- 支持1000+并发请求，响应时间<200ms
- 多级缓存策略
- 异步队列处理
- 负载均衡支持
- 支持水平扩展

### VIII. 测试组织规范 (Test Organization Standards)
所有测试文件必须按照模块化原则组织：
- 测试文件必须写在对应模块的Test/Unit/目录内（遵循PSR-4命名规范）
- 单元测试必须放在模块的Test/Unit/目录下
- 集成测试必须放在模块的tests/integration/目录下
- 合约测试必须放在模块的tests/contract/目录下
- 测试类命名必须遵循大驼峰格式（例如：BusinessInsightServiceTest）
- 每个模块的测试必须独立可运行
- 测试目录结构必须与源码目录结构保持一致
- **MUST**: 运行单元测试必须使用 `php bin/w phpunit:run -b` 命令（`-b` 参数为后台模式，生成详细HTML报告并启动报告服务器）
- **MUST**: 禁止不带 `-b` 参数运行 `phpunit:run`（快速模式仅用于开发调试）
- 运行单元测试命令参考：`php bin/w phpunit:run -h`

### IX. PHP语言合规性 (PHP Language Compliance - NON-NEGOTIABLE)
必须严格按照PHP 8.2以上语法开发，严格遵守PHP语言特性：
- 必须使用PHP 8.2+的严格类型声明 (declare(strict_types=1))
- 继承接口或抽象类必须实现所有必需方法
- 必须正确实现抽象方法的签名和返回类型
- 必须使用PHP 8.2+的新特性（如readonly属性、枚举等）
- 禁止使用已废弃的PHP语法和函数
- 所有类必须正确实现其继承的接口或抽象类
- 方法签名必须与父类或接口完全匹配
- 必须处理所有可能的异常情况

### X. ORM使用规范 (ORM Usage Standards - NON-NEGOTIABLE)
禁止在ORM使用时自己揣测函数，必须严格遵循框架规范：
- 禁止基于个人经验或外部框架（如Magento）进行ORM操作
- 必须深入学习WelineFramework的ORM实现和API文档
- 所有ORM操作必须基于框架提供的实际方法
- 禁止使用未在框架文档中明确说明的方法
- 必须通过阅读源码和文档确认ORM方法的正确用法
- 遇到不确定的ORM操作时必须查阅框架源码和文档
- 禁止参考Magento或其他框架的ORM模式
- 必须使用框架自研的ORM特性和方法

#### 批量操作执行规范 - NON-NEGOTIABLE

**数据库批量操作（delete/select/find等）必须使用 `fetch()` 或 `fetchArray()` 才会真正执行**：

- **MUST**: 批量 delete/update/select 操作必须调用 `fetch()` 或 `fetchArray()` 才会执行SQL
- **MUST**: 理解 WelineFramework ORM 的惰性执行机制：查询构建器只构建SQL，不立即执行
- **MUST**: 单条记录操作（如 `load($id)` 后的 `delete()`）会立即执行，批量操作必须显式fetch
- **MUST NOT**: ❌ 禁止认为调用 `delete()` 或 `select()` 就会立即执行数据库操作

**正确写法**：
```php
// ✅ 正确：批量删除记录（必须 fetch 才会执行）
$this->getModel()
    ->where('is_copy', 1)
    ->delete()
    ->fetch();  // 必须调用 fetch() 才会真正执行 DELETE SQL

// ✅ 正确：批量查询（必须 fetch 才会获取数据）
$models = $this->getModel()
    ->where('status', 'active')
    ->select()
    ->fetch();  // 必须调用 fetch() 才会执行 SELECT SQL

// ✅ 正确：批量更新（必须 fetch 才会执行）
$this->getModel()
    ->where('supplier', 'openai')
    ->update(['is_active' => 1])
    ->fetch();  // 必须调用 fetch() 才会执行 UPDATE SQL

// ✅ 正确：单条记录删除（load 后的 delete 会立即执行）
$model = $this->getModel()->load($id);
if ($model && $model->getId()) {
    $model->delete();  // 单条记录的 delete() 会立即执行
}

// ✅ 正确：使用 fetchArray 获取数组结果
$ids = $this->getModel()
    ->where('is_active', 0)
    ->select(['id'])
    ->fetchArray();  // 返回数组格式的结果
```

**错误写法（禁止）**：
```php
// ❌ 错误：批量删除未调用 fetch()，不会执行
$this->getModel()
    ->where('is_copy', 1)
    ->delete();  // ❌ SQL 不会执行！必须调用 ->fetch()

// ❌ 错误：批量查询未调用 fetch()，返回查询构建器而非数据
$models = $this->getModel()
    ->where('status', 'active')
    ->select();  // ❌ 返回查询构建器对象，不是查询结果

// ❌ 错误：批量更新未调用 fetch()，不会执行
$this->getModel()
    ->where('supplier', 'openai')
    ->update(['is_active' => 1]);  // ❌ SQL 不会执行！
```

**Rationale**: WelineFramework ORM 使用惰性执行机制，查询构建器的方法（where/select/delete/update）只构建SQL语句，并不立即执行。只有在调用 `fetch()` 或 `fetchArray()` 时才会真正执行SQL。这种设计允许：
1. **链式调用优化**：可以持续构建复杂查询而不触发多次数据库访问
2. **条件组合灵活**：可以根据业务逻辑动态添加查询条件
3. **性能优化**：避免不必要的数据库查询

**单条记录操作例外**：通过 `load($id)` 加载的单条记录调用 `delete()` 或 `save()` 会立即执行，因为这些是对已加载模型实例的操作，不是批量操作。

**常见错误场景**：
1. **批量删除测试数据**：测试清理时使用 `where()->delete()` 但忘记 `fetch()`，导致测试数据未清理
2. **批量更新状态**：使用 `where()->update()` 但忘记 `fetch()`，导致状态未更新
3. **条件查询**：使用 `where()->select()` 但忘记 `fetch()`，导致返回查询构建器而非查询结果

#### 模型记录存在性判断规范 - NON-NEGOTIABLE

**禁止直接使用模型对象判断记录是否存在，必须使用 `model->getId()` 方法**：

- **MUST**: 使用 `$model->getId()` 判断数据库记录是否存在
- **MUST**: 检查模型对象时，必须同时检查对象和ID：`if ($model && $model->getId())`
- **MUST NOT**: ❌ 禁止直接使用 `if ($model)` 判断记录是否存在
- **MUST NOT**: ❌ 禁止在布尔上下文中直接使用模型对象判断记录存在性

**正确写法**：
```php
// ✅ 正确：检查模型记录是否存在
$model = $this->getModel()->load($id);
if ($model && $model->getId()) {
    // 记录存在，可以继续操作
    echo "Found: " . $model->getName();
}

// ✅ 正确：检查查询结果是否有记录
$existingModel = $this->getModel()
    ->where('code', $code)
    ->find()
    ->fetch();
if ($existingModel && $existingModel->getId()) {
    // 记录已存在
    throw new \Exception('Record already exists');
}

// ✅ 正确：检查模型是否为新记录
if (!$model->getId()) {
    // 这是新记录，还未保存到数据库
    $model->save();
}
```

**错误写法（禁止）**：
```php
// ❌ 错误：直接判断模型对象
$model = $this->getModel()->load($id);
if ($model) {  // 错误！即使没有找到记录，$model 也可能是空对象
    echo "Found: " . $model->getName();  // 可能导致空值错误
}

// ❌ 错误：查询后直接判断对象
$existingModel = $this->getModel()
    ->where('code', $code)
    ->find()
    ->fetch();
if ($existingModel) {  // 错误！即使查询无结果，也可能返回空模型对象
    throw new \Exception('Already exists');  // 误判：认为记录存在
}

// ❌ 错误：在条件中省略 getId() 检查
if ($model) {  // 错误！无法确定是否真的有数据库记录
    $model->delete();  // 可能导致意外行为
}
```

**Rationale**: WelineFramework 的 ORM 在某些情况下会返回空的模型对象（即使数据库中没有对应记录），直接在布尔上下文中使用模型对象会被判断为 true，导致误判记录存在。`getId()` 方法返回数据库主键，只有真正的数据库记录才有有效的 ID，因此是判断记录存在性的唯一可靠方法。这种判断方式可以避免：
1. **误判记录存在**：空模型对象被当作有效记录处理
2. **空值错误**：访问不存在记录的属性导致错误
3. **逻辑错误**：基于错误的存在性判断执行错误的业务逻辑
4. **数据不一致**：误删除或误更新不存在的记录

#### 模型字段常量使用规范 - NON-NEGOTIABLE

**模型字段常量必须对应实际数据库字段，禁止使用指向不存在字段的别名常量**：

- **MUST**: 模型中的字段常量（如 `fields_NAME`）必须与数据库表中的实际字段名一致
- **MUST**: 在代码中使用字段常量时，必须确保该常量指向的字段在数据库表中真实存在
- **MUST NOT**: ❌ 禁止定义指向不存在数据库字段的别名常量（如 `fields_MODEL_NAME = 'model_name'`，但数据库中只有 `name` 字段）
- **MUST NOT**: ❌ 禁止在 `where()`, `order()`, `getData()` 等方法中使用指向不存在字段的常量
- **SHOULD**: 如需提供字段访问的替代名称，应使用 getter 方法而非别名常量

**正确写法**：
```php
// ✅ 正确：字段常量与数据库字段一致
class AiModel extends Model
{
    public const fields_SUPPLIER = 'supplier';  // 数据库中有 supplier 字段
    public const fields_NAME = 'name';          // 数据库中有 name 字段
    
    // 提供别名访问的 getter 方法
    public function getVendor(): string {
        return $this->getData(self::fields_SUPPLIER);
    }
    
    public function getModelName(): string {
        return $this->getData(self::fields_NAME);
    }
}

// ✅ 正确：使用真实字段常量
$models = $this->aiModel->reset()
    ->order(AiModel::fields_SUPPLIER, 'ASC')
    ->order(AiModel::fields_NAME, 'ASC')
    ->select();

// ✅ 正确：使用 getter 方法
$vendor = $model->getVendor();      // 内部调用 getData('supplier')
$name = $model->getModelName();     // 内部调用 getData('name')
```

**错误写法（禁止）**：
```php
// ❌ 错误：定义指向不存在字段的别名常量
class AiModel extends Model
{
    public const fields_SUPPLIER = 'supplier';       // 数据库字段
    public const fields_VENDOR = 'vendor';           // ❌ 数据库中没有 vendor 字段！
    public const fields_NAME = 'name';               // 数据库字段
    public const fields_MODEL_NAME = 'model_name';   // ❌ 数据库中没有 model_name 字段！
}

// ❌ 错误：使用指向不存在字段的常量会导致数据库错误
$models = $this->aiModel->reset()
    ->order(AiModel::fields_VENDOR, 'ASC')      // SQL错误：no such column: vendor
    ->order(AiModel::fields_MODEL_NAME, 'ASC')  // SQL错误：no such column: model_name
    ->select();

// ❌ 错误：getData 无法获取不存在的字段
$vendor = $model->getData(AiModel::fields_VENDOR);  // 返回 null 或错误
```

**Rationale**: 字段常量是代码与数据库之间的映射桥梁，必须确保一一对应。使用指向不存在字段的别名常量会导致：
1. **数据库查询错误**：`ORDER BY` 或 `WHERE` 子句引用不存在的列
2. **数据访问失败**：`getData()` 无法获取不存在字段的值
3. **代码混淆**：开发者误以为字段存在，导致逻辑错误
4. **维护困难**：字段重命名或迁移时产生不一致

正确的做法是：字段常量仅定义真实数据库字段，如需提供别名访问，应通过 getter 方法实现（方法内部使用正确的字段常量），这样既保证了数据访问的正确性，又提供了灵活的API接口。

### XI. 框架学习要求 (Framework Learning Requirements - NON-NEGOTIABLE)
这是自研框架，必须深入学习框架本身而非外部参考：
- **MUST**: 禁止参考Magento或其他外部框架的结构和模式
- **MUST**: 必须深入学习WelineFramework的源码和架构设计
- **MUST**: 所有开发必须基于对WelineFramework框架的深入理解
- **MUST**: 必须阅读框架的开发文档和API文档
- **MUST**: 必须研究现有模块的实现模式和最佳实践
- **MUST**: 禁止基于外部框架经验进行开发决策
- **MUST**: 必须通过框架源码学习正确的实现方式
- **MUST**: 所有功能实现必须符合WelineFramework的设计理念
- **MUST**: 当开发文档缺失时，必须通过自学（阅读源码、现有模块示例）掌握正确写法
- **MUST**: 自学完成后，必须在PR中记录学习要点并询问是否更新到开发文档

#### A. 对标现有模块学习规范 - NON-NEGOTIABLE

**新模块开发必须对标框架内成熟模块（如 `Weline_Queue`）学习开发模式**：

- **MUST**: 开发新模块前，**必须**指定一个参考模块（如 `Weline_Queue`、`Weline_Eav`）作为学习对象
- **MUST**: **必须**详细研究参考模块的以下方面：
  - 模型类设计（字段常量、主键定义、`_unit_primary_keys`、`_index_sort_keys`）
  - `install()` 方法中的表结构定义（`createTable()`、`addColumn()`、`addIndex()`）
  - 表名命名约定（模型类名与表名的映射关系）
  - 是否使用 `_init()` 方法（大多数模型**不需要** `_init()`）
  - 控制器的 CRUD 操作实现
  - Service 层的业务逻辑封装
  - Console 命令的实现模式
- **MUST**: **禁止**自创模式或凭想象实现，所有实现必须有框架内实际案例支撑
- **MUST**: **禁止**直接声明 `protected $_table`、`protected $_id_field_name` 等属性，除非参考模块也这样做
- **MUST**: 表名必须与模型类名匹配框架的命名约定（如 `Queue` → `queue`，`AiModel` → `ai_model`）
- **MUST**: 如果表名与类名不匹配（如 `AiModel` 但表名是 `ai`），必须咨询框架维护者是否需要重命名
- **MUST NOT**: ❌ 禁止在未理解框架 ORM 机制的情况下随意覆盖框架默认行为
- **SHOULD**: 在模块 README 或文档中明确说明参考了哪个模块及学习要点
- **SHOULD**: 对比新模块与参考模块的差异，确保差异是合理的而非错误理解

**学习流程（以 `Weline_Ai` 对标 `Weline_Queue` 为例）**：

1. **阅读 `Weline_Queue` 源码**：
   ```
   app/code/Weline/Queue/
   ├── Model/
   │   ├── Queue.php           # 主模型（如何定义字段常量、install()）
   │   └── Queue/Type.php      # 关联模型
   ├── Controller/...           # 控制器实现
   ├── Service/...              # Service 层
   └── Console/...              # CLI 命令
   ```

2. **对比差异**：
   - `Queue` 模型**没有** `_init()` 方法 → `AiModel` 也不应该有
   - `Queue` 的 `install()` 使用 `createTable('任务队列')` → 描述性文字，不是表名
   - `Queue` 表名是 `queue`（与类名匹配） → `AiModel` 应该对应 `ai_model` 表，或重命名类为 `Ai`

3. **记录学习要点**：
   - WelineFramework ORM 从模型类名自动推导表名
   - `install()` 中的 `createTable('描述')` 只是注释，表名由 ORM 自动生成
   - 不需要手动设置 `$_table` 除非有特殊需求且经过验证

**Rationale**: 

1. **避免重复错误**：新开发者容易凭经验或想象实现，导致与框架规范不符
2. **保持一致性**：对标成熟模块确保代码风格、架构模式与框架保持一致
3. **加速学习**：通过实际案例学习比阅读文档更高效
4. **减少返工**：提前发现与框架不符的实现，避免后期大规模重构
5. **知识传承**：将成熟模块的最佳实践传承到新模块中

对标现有模块不仅是技术要求，更是确保代码质量和项目可维护性的关键措施。

#### B. 模型必备要素规范 - NON-NEGOTIABLE

**WelineFramework 模型必须包含以下必备要素，否则会导致运行时错误**：

##### B.1 字段常量定义（fields_* 常量）

- **MUST**: 为**所有**数据库表字段定义对应的 `fields_*` 常量
- **MUST**: 常量名必须以 `fields_` 开头，后跟大写下划线格式的字段名（如 `fields_ID`、`fields_NAME`）
- **MUST**: 常量值必须与数据库实际字段名完全一致（区分大小写）
- **MUST**: `fields_*` 常量与 `install()` 方法中的字段定义必须保持一致
- **MUST NOT**: ❌ 禁止定义指向不存在数据库字段的别名常量
- **MUST NOT**: ❌ 禁止遗漏任何数据库字段的常量定义

**正确示例**：
```php
class YourModel extends Model
{
    // ✅ 正确：所有字段都有对应常量
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_EMAIL = 'email';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    // ... 为所有表字段定义常量
}
```

**错误示例**：
```php
class YourModel extends Model
{
    // ❌ 错误：缺少字段常量定义
    public const fields_ID = 'id';
    // 缺少其他字段的常量定义，会导致 save() 报错
}
```

**Rationale（为什么需要 fields_* 常量）**：

WelineFramework 的 ORM 依赖 `fields_*` 常量来提取模型数据：

```php
// AbstractModel::getModelData() - 第1252-1273行
public function getModelData(string $field = ''): array|string
{
    foreach ($this->getModelFields() as $key => $val) {
        if (isset($data[$val])) {
            $this->_model_fields_data[$val] = $field_data;
        }
    }
    return $this->_model_fields_data;
}

// getModelFields() 从类常量 fields_* 中提取字段列表
```

**如果缺少 `fields_*` 常量，会导致**：
1. ❌ `getModelData()` 返回空数组
2. ❌ `save()` 时报错："保存数据出错! 消息: 插入数据不能为空！"
3. ❌ 无法正确序列化和保存模型数据
4. ❌ ORM 无法识别哪些字段需要保存到数据库

##### B.2 主键定义（$_unit_primary_keys）

- **MUST**: 定义 `public array $_unit_primary_keys` 属性
- **MUST**: 数组中包含主键字段名（通常是 `['id']`）
- **MUST**: 复合主键的模型必须包含所有主键字段
- **MUST**: 主键字段必须与 `install()` 方法中的主键定义一致

**正确示例**：
```php
class YourModel extends Model
{
    /**
     * Unit primary keys
     */
    public array $_unit_primary_keys = ['id'];
}
```

**Rationale**: `$_unit_primary_keys` 用于：
1. `save()` 方法判断是 INSERT 还是 UPDATE
2. `forceCheck()` 方法检查记录是否存在
3. `delete()` 方法构建删除条件

##### B.3 初始化方法（_init()）

- **MUST**: 实现 `_init()` 方法，至少调用 `$this->useMainDbMaster()`
- **MUST NOT**: ❌ 禁止在 `_init()` 中手动设置 `$_table` 属性
- **SHOULD**: 在 `_init()` 方法中添加注释说明表名自动推导规则

**正确示例**：
```php
class YourModel extends Model
{
    /**
     * 初始化模型
     * 表名自动推导：YourModel → your_model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
        // 让框架自动推导表名，不要手动设置 $_table
    }
}
```

**错误示例**：
```php
class YourModel extends Model
{
    // ❌ 错误：完全不实现 _init() 方法
    // 会导致数据库连接未初始化
}

class BadModel extends Model
{
    public function _init(): void
    {
        $this->useMainDbMaster();
        $this->_table = 'custom_table'; // ❌ 违反 Constitution XI.A
        $this->_id_field_name = 'id';   // ❌ 违反 Constitution XI.A
    }
}
```

**Rationale**: `_init()` 方法用于：
1. 初始化数据库连接（`useMainDbMaster()`）
2. 触发框架的表名自动推导机制
3. 执行模型级别的初始化逻辑

##### B.4 数据库安装方法（install()）

- **MUST**: 实现 `install()` 方法定义表结构
- **MUST**: `install()` 中的字段定义必须与 `fields_*` 常量完全一致
- **MUST**: 使用 `$setup->createTable()` 和 `->addColumn()` 定义表结构
- **MUST**: `createTable()` 参数是**描述文字**，不是表名（表名由框架自动推导）
- **MUST**: 所有必需字段必须在 `install()` 中定义，不能依赖后续迁移文件

**正确示例**：
```php
public function install(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist() === false) {
        $setup->createTable('用户模型') // ✅ 描述文字，不是表名
            ->addColumn('id', 'INTEGER', null, 'primary key auto_increment', 'ID')
            ->addColumn('name', 'VARCHAR', 255, 'not null', '名称')
            ->addColumn('email', 'VARCHAR', 255, 'not null', '邮箱')
            ->addColumn('created_at', 'TIMESTAMP', null, 'not null DEFAULT CURRENT_TIMESTAMP', '创建时间')
            ->addColumn('updated_at', 'TIMESTAMP', null, 'not null DEFAULT CURRENT_TIMESTAMP', '更新时间')
            ->addIndex('email', '', 'UNIQUE', 'idx_email')
            ->create();
    }
}
```

**错误示例**：
```php
public function install(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist() === false) {
        $setup->createTable('user_table') // ❌ 不应该是表名
            ->addColumn('id', 'INTEGER', null, 'primary key auto_increment', 'ID')
            ->addColumn('name', 'VARCHAR', 255, 'not null', '名称')
            // ❌ 缺少 email, created_at, updated_at 字段定义
            // 但在 fields_* 常量中定义了这些字段
            ->create();
    }
}
```

##### B.5 完整的模型模板

**参考 `Weline_Ai` 的 `AiModel.php` 和 `AiApiKey.php` 作为标准模板**：

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

class YourModel extends Model
{
    // ✅ 字段常量定义（对应所有数据库字段）
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_EMAIL = 'email';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // ✅ 主键定义
    public array $_unit_primary_keys = ['id'];
    
    // ✅ 索引字段（可选，用于优化查询排序）
    public array $_index_sort_keys = ['id', 'name', 'created_at'];
    
    /**
     * ✅ 初始化方法
     * 表名自动推导：YourModel → your_model
     */
    public function _init(): void
    {
        $this->useMainDbMaster();
    }
    
    // ✅ 数据库安装方法
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
    
    public function upgrade(ModelSetup $setup, Context $context): void {}
    
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('用户模型描述')
                ->addColumn('id', 'INTEGER', null, 'primary key auto_increment', 'ID')
                ->addColumn('name', 'VARCHAR', 255, 'not null', '名称')
                ->addColumn('email', 'VARCHAR', 255, 'not null', '邮箱')
                ->addColumn('status', 'VARCHAR', 20, 'not null default "active"', '状态')
                ->addColumn('created_at', 'TIMESTAMP', null, 'not null DEFAULT CURRENT_TIMESTAMP', '创建时间')
                ->addColumn('updated_at', 'TIMESTAMP', null, 'not null DEFAULT CURRENT_TIMESTAMP', '更新时间')
                ->addIndex('email', '', 'UNIQUE', 'idx_email')
                ->create();
        }
    }
}
```

##### B.6 验证清单

在开发新模型时，使用以下清单验证：

- [ ] 为所有数据库字段定义了 `fields_*` 常量
- [ ] 定义了 `$_unit_primary_keys` 数组
- [ ] 实现了 `_init()` 方法（调用 `useMainDbMaster()`）
- [ ] 实现了 `install()` 方法（字段与常量一致）
- [ ] **没有**手动设置 `$_table` 或 `$_id_field_name`
- [ ] 表名符合命名约定（CamelCase → snake_case）
- [ ] 所有字段常量与 `install()` 方法一致
- [ ] 运行 `php bin/w setup:upgrade` 成功创建表
- [ ] 可以正常 `save()`、`load()`、`delete()` 数据

**Rationale**: 
1. **防止运行时错误**：缺少必备要素会导致 ORM 无法正常工作
2. **提高开发效率**：统一的模板减少试错时间
3. **保证一致性**：所有模型遵循相同的结构和规范
4. **简化调试**：问题模式相似，便于快速定位
5. **知识传承**：新开发者可以快速掌握正确的模型开发方式

**参考文档**：
- [WelineFramework 模型开发最佳实践](../docs/WelineFramework模型开发最佳实践.md)
- 参考模块：`Weline_Queue`、`Weline_Ai`

### XIV. 架构与数据流验证 (Architecture & Data-flow Validation)

在开始任何开发工作前，必须验证架构逻辑、数据流与字段定义能满足规格中列明的功能需求。

- **MUST**: 在进入实现阶段前（Phase 1 设计完成后），团队必须证明关键路径的架构与数据字段覆盖所有功能需求（例如：租户ID、origin_model_id、is_copy 等字段的存在与约束）。
- **MUST**: 任何新增的数据字段或架构调整必须在 `data-model.md` 中记录，并由设计审核通过后才能进入实现。
- **SHOULD**: 使用简单的数据流图或表格在 `research.md` 中描述端到端数据流与关键字段，以便在代码实现前验证一致性。

Rationale: 提前验证架构与数据流可以显著减少实现阶段的返工与数据一致性问题，确保开发与测试能直接对齐验收条件。

### XV. 变更范围限制 (Change Scope Constraint)

为降低大范围影响和意外变更的风险，本次特性相关的代码修改**禁止超出** `app\code\Weline\Ai` 目录范围，除非在设计评审中获得明确批准并记录在 `research.md` 中。

- **MUST**: 所有 PR 的变更集默认应仅包含 `app\code\Weline\Ai` 目录内的文件。
- **MUST**: 若确需修改其他目录（例如共享库或 infra 配置），开发团队必须在设计阶段提交变更影响分析并由技术负责人批准，批准记录需附在 PR 描述中。
- **SHOULD**: CI/PR 模板需自动检测超出目录的改动并将 PR 标为需额外审批。

Rationale: 限定初始变更范围可以防止大范围非预期影响，并使代码审查集中于模块边界和兼容性。

### XVI. 已实现功能兼容性 (Existing Feature Compatibility)

当新的宪法条款引入更严格的实现或流程要求时，必须优先保证已存在且运行中的功能继续可用与兼容。

- **MUST**: 在修改现有功能或引入新约束前，进行兼容性评估并在 `research.md` 中记录回归风险与迁移步骤。
- **MUST**: 对已实现功能的适配变更必须提供回退方案，以便出现兼容性问题时能迅速恢复服务。
- **SHOULD**: 对生产中已存在的关键路径功能进行额外的回归测试，确保新变更不会破坏当前行为。

Rationale: 保证对现有用户服务不中断是首要责任，宪法要求应引导但不破坏当前稳定运行的功能。

### XII. 编辑与新建（Offcanvas 编辑流）
编辑与新建交互必须采用框架统一的 Offcanvas（侧出式）编辑流以保证一致的用户体验与可复用组件。

- **MUST**: 在实现任何后台或前端的"新建/编辑"界面时，优先使用框架提供的 Offcanvas 组件或官方推荐的实现模式。
- **MUST**: 实施前必须查阅框架内的开发文档中关于 Offcanvas/侧出式组件的使用说明与示例。
- **MUST**: 若开发文档中未包含 Offcanvas 使用说明，开发者必须通过阅读源码、现有模块示例或相关 view/templates 自学并记录学习要点（简短文档或 PR 描述中的学习摘要）。
- **MUST**: 完成首个 Offcanvas 实现后，开发者须在 PR 中提交学习摘要并在 PR/Issue 中显式询问：是否将该示例与使用指南合并回框架开发文档（"是否更新到开发文档"）。
- **SHOULD**: Offcanvas 实现必须满足可访问性要求（键盘导航、焦点管理、屏幕阅读器标签）。

- **MUST**: 对于与模型相关的所有交互（新建 / 编辑 / 拷贝），UI 必须统一使用 Offcanvas 编辑流实现，保证交互一致性与行为可预测性。

Rationale: 统一的编辑/新建交互有助于降低维护成本、提升用户一致性并避免重复实现。将学习过程纳入开发流程并在 PR 中触发文档更新请求，能确保知识沉淀到框架层并被后续开发复用。

### XIII. 快速 E2E 测试指引（HTTP 请求测试要求 - NON-NEGOTIABLE）

**URL返回内容的测试必须使用 `php bin/w http:request` 命令进行**，**单元测试必须使用 `php bin/w phpunit:run -b` 命令**，禁止创建临时测试文件：

- **MUST**: 所有URL/API返回内容的测试必须使用 `php bin/w http:request` 命令，禁止创建临时PHP测试文件
- **MUST**: 所有单元测试执行必须使用 `php bin/w phpunit:run -b` 命令（`-b` 参数为后台模式，生成详细HTML报告并启动报告服务器）
- **MUST**: AI进行开发时，必须使用 `php bin/w server:start -b` 参数启动服务器（`-b` 参数为后台模式，专门为AI配置）
- **MUST**: 在编写集成测试或手动验证 API 时，必须使用 `php bin/w http:request` 发起请求并验收响应状态码、响应体和头部信息
- **MUST**: 在测试敏感操作（例如：修改/删除资源）时，确保使用测试环境或隔离租户，并清理测试数据
- **MUST**: 开发完成且本地/CI 测试通过后，必须使用 `php bin/w http:request` 对相关路径执行端到端验证，验证返回内容符合规格（状态码、响应结构、关键字段）
- **MUST**: 禁止使用cURL脚本、临时PHP文件或其他方式进行URL测试，必须统一使用框架命令
- **MUST**: 禁止使用 `Start-Process powershell` 或其他间接方式启动服务器，必须直接使用 `php bin/w server:start -b`
- **MUST**: 禁止不带 `-b` 参数运行 `php bin/w phpunit:run`（快速模式仅用于开发调试单个测试方法）
- **MUST**: 清理缓存必须使用 `php bin/w cache:clear -f` 命令，禁止使用文件系统删除命令（如 `Remove-Item`、`rm`、`del` 等）
- **MUST NOT**: ❌ 禁止使用 `Remove-Item -Path "var/cache/*"` 或类似命令清理缓存
- **MUST NOT**: ❌ 禁止手动删除 `var/cache/` 目录下的文件或子目录
- **SHOULD**: 将常用的 `php bin/w http:request` 示例放入 `quickstart.md` 或 `tests/` 示例文件中，供开发者快速复制运行

**正确的服务器和测试命令方式**：
```bash
# ========================================
# 服务器启动（默认后台运行）
# ========================================
# 后台运行服务器（默认模式）
php bin/w server:start

# 前台运行服务器（仅用于调试，需要明确指定）
# php bin/w server:start -f

# 等待服务器启动（通常需要5-10秒）
Start-Sleep -Seconds 8

# ========================================
# HTTP请求测试
# ========================================
# GET请求测试
php bin/w http:request GET /rest/v1/chat

# POST请求测试（带JSON数据）
php bin/w http:request POST /rest/v1/chat --data '{"prompt":"test message","model_code":"gpt-4"}'

# 带请求头测试
php bin/w http:request GET /rest/v1/model/1 --header "Authorization: Bearer token"

# ========================================
# 单元测试运行（默认后台运行）
# ========================================
# 运行整个模块测试（生成详细HTML报告，默认后台运行）
php bin/w phpunit:run --module=Weline_Ai

# 运行指定测试文件（生成详细HTML报告，默认后台运行）
php bin/w phpunit:run --name=BusinessInsightServiceTest

# 快速调试单个方法（前台运行，实时查看输出）
php bin/w phpunit:run -f --name=BusinessInsightServiceTest::testGetOverallStats

# ========================================
# 服务器停止
# ========================================
php bin/w server:stop

# ========================================
# 缓存清理（正确方式）
# ========================================
# 清理所有缓存（强制模式）
php bin/w cache:clear -f

# 清理指定类型的缓存
php bin/w cache:clear --type=config -f
php bin/w cache:clear --type=layout -f
```

**错误的启动方式（禁止使用）**：
```bash
# ❌ 禁止使用 Start-Process（会导致AI无法正确管理进程）
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PWD'; php bin/w server:start"

# ❌ 禁止手动启动前台服务器（默认后台运行即可）
# 除非明确需要调试日志，否则不要使用 -f 参数

# ❌ 禁止使用 -f 参数运行完整测试（会阻塞终端，无法生成HTML报告）
php bin/w phpunit:run -f --module=Weline_Ai

# ❌ 禁止使用 -f 参数运行测试文件（会阻塞终端，无法生成HTML报告）
php bin/w phpunit:run -f --name=BusinessInsightServiceTest

# ❌ 禁止使用文件系统命令清理缓存（会导致缓存状态不一致）
Remove-Item -Path "var/cache/*" -Recurse -Force
Remove-Item -Path "var/cache/database_model/*" -Recurse -Force
rm -rf var/cache/*
del /s /q var\cache\*
```

**Rationale**: 统一使用框架命令的优势：

1. **测试规范**：避免创建临时测试文件污染代码库，保证测试环境一致性，利用框架的请求处理机制，便于团队协作和文档化，降低维护成本。
2. **后台模式**：现在服务器和测试命令默认后台运行，可以让AI正确管理进程，生成详细的HTML测试报告，避免终端阻塞和进程管理问题。前台模式（使用 `-f` 参数）仅用于开发调试单个测试方法或查看实时日志时使用。
3. **缓存管理**：使用 `php bin/w cache:clear -f` 可以确保框架正确管理缓存状态、更新缓存索引、处理依赖关系、触发相关事件。手动删除文件会导致缓存状态不一致、索引失效、依赖关系混乱，甚至引发系统错误。框架命令还提供了精细化的缓存类型控制，避免全局清理影响性能。

#### D. HTTP请求前后端区分规范 - NON-NEGOTIABLE

**使用 `php bin/w http:request` 测试路由时，必须正确区分前后端URL路径**：

- **MUST**: 后端路由测试必须使用 `-b` 参数（backend模式），命令会**自动解析path为后端请求地址**
- **MUST**: 前端路由测试使用直接的模块路径（无需 `-b` 参数）
- **MUST**: 在测试前必须明确路由属于前端还是后端
- **MUST**: 使用 `php bin/w http:request -h` 查看命令用法，了解URL路径规则和参数
- **MUST**: 测试404错误时，按以下步骤排查：
  1. 检查是否已运行 `php bin/w setup:upgrade` 注册路由
  2. 使用 `php bin/w route:list` 确认路由是否存在
  3. 检查URL路径是否正确使用了 `-b` 参数（后端）或直接路径（前端）
- **MUST NOT**: ❌ 禁止后端路由测试时不使用 `-b` 参数（会导致404）
- **MUST NOT**: ❌ 禁止前端路由测试时使用 `-b` 参数（会导致错误的URL路径）
- **MUST NOT**: ❌ 禁止在URL路径中手动添加后端地址前缀（应使用 `-b` 参数自动处理）

**正确的URL路径示例**：

```bash
# ========================================
# 后端路由测试（Backend Controller）
# ========================================
# 后端路由使用 -b 参数，命令会自动解析为后端请求地址
# URL格式：<模块路径>/<控制器路径>/<方法>

# ✅ 正确：后端适配器列表（-b参数自动解析为后端地址）
php bin/w http:request -u "ai/backend/adapter/index" -b --login

# ✅ 正确：后端适配器详情Offcanvas
php bin/w http:request -u "ai/backend/adapter/detailOffcanvas?id=1" -b --login

# ✅ 正确：后端模型列表
php bin/w http:request -u "ai/backend/model/index" -b --login

# ✅ 正确：后端模型编辑Offcanvas
php bin/w http:request -u "ai/backend/model/editOffcanvas?id=1" -b --login

# ✅ 正确：后端模型保存（POST请求）
php bin/w http:request -u "ai/backend/model/save" -m POST -d "id=1&name=GPT-4" -b --login

# ========================================
# 前端路由测试（Frontend Controller）
# ========================================
# 前端路由直接使用模块路径，无需 -b 参数
# URL格式：<模块路径>/<控制器路径>/<方法>

# ✅ 正确：前端聊天页面
php bin/w http:request -u "ai/frontend/chat/index"

# ✅ 正确：前端模型选择
php bin/w http:request -u "ai/frontend/model/list"

# ✅ 正确：前端对话历史
php bin/w http:request -u "ai/frontend/conversation/history"

# ========================================
# RESTful API路由测试（REST API）
# ========================================
# API路由使用 rest/ 前缀
# 格式：rest/<版本>/<资源>/<操作>

# ✅ 正确：API聊天请求
php bin/w http:request -u "rest/v1/chat" -m POST -d '{"prompt":"test","model_code":"gpt-4"}'

# ✅ 正确：API获取模型信息
php bin/w http:request -u "rest/v1/model/1" -m GET
```

**错误的URL路径示例（禁止）**：

```bash
# ❌ 错误：后端路由未使用 -b 参数（会返回404）
php bin/w http:request -u "ai/backend/adapter/detailOffcanvas?id=1"
# 正确应为：php bin/w http:request -u "ai/backend/adapter/detailOffcanvas?id=1" -b --login

# ❌ 错误：后端路由手动添加了后端地址前缀（应使用-b参数自动处理）
php bin/w http:request -u "admin/ai/backend/adapter/index" --login
# 正确应为：php bin/w http:request -u "ai/backend/adapter/index" -b --login

# ❌ 错误：前端路由使用了 -b 参数（会导致错误的URL）
php bin/w http:request -u "ai/frontend/chat/index" -b
# 正确应为：php bin/w http:request -u "ai/frontend/chat/index"

# ❌ 错误：API路由缺少 rest 前缀
php bin/w http:request -u "v1/chat" -m POST
# 正确应为：php bin/w http:request -u "rest/v1/chat" -m POST
```

**路径识别规则**：

1. **Backend Controller** 位于 `Controller/Backend/` 目录 → 使用 `-b` 参数（自动解析为后端地址）
2. **Frontend Controller** 位于 `Controller/Frontend/` 目录 → 无需 `-b` 参数，URL直接使用模块路径
3. **API Controller** 位于 `Controller/Api/` 目录 → 无需 `-b` 参数，URL使用 `rest/` 前缀

**调试404错误的步骤**：

```bash
# 1. 首要检查：确认路由已注册（404的最常见原因）
php bin/w setup:upgrade -m Weline_Ai
# 新增或修改Controller后必须运行此命令注册路由

# 2. 验证路由是否存在于系统中
php bin/w route:list | Select-String -Pattern "adapter"
# 如果未找到路由，说明setup:upgrade未正确运行或Controller有错误

# 3. 检查Controller文件位置，确定应使用的参数
# Backend: app/code/Weline/Ai/Controller/Backend/Adapter.php → 使用 -b 参数
# Frontend: app/code/Weline/Ai/Controller/Frontend/Adapter.php → 不使用 -b 参数

# 4. 使用正确的URL路径和参数重新测试
# 后端路由示例（-b参数自动解析为后端地址）
php bin/w http:request -u "ai/backend/adapter/index" -b --login

# 前端路由示例
php bin/w http:request -u "ai/frontend/chat/index"
```

**Rationale**: WelineFramework 使用 `-b` 参数来自动解析path为后端请求地址，这是框架的路由机制核心特性。明确的前后端URL区分规范可以：

1. **避免404错误**：
   - **最常见原因**：新增或修改Controller后未运行 `php bin/w setup:upgrade` 注册路由
   - **次要原因**：后端路由未使用 `-b` 参数，或前端路由错误使用了 `-b` 参数
2. **简化命令使用**：`-b` 参数自动解析为后端地址，无需手动拼接URL前缀
3. **提高调试效率**：按步骤排查（路由注册 → 路由列表 → 参数检查）快速定位问题
4. **规范化测试**：统一团队的测试方式，让框架自动处理地址解析
5. **框架理解**：理解 `-b` 参数的自动解析机制和路由注册流程

这是WelineFramework的核心路由规则，必须严格遵守。**关键要点**：
- 后端测试必须使用 `-b` 参数（自动解析为后端地址）
- 404错误首先检查是否运行了 `setup:upgrade` 注册路由
- 使用 `route:list` 命令验证路由是否存在于系统中
- 不要假设或手动添加具体的URL前缀，交给框架的 `-b` 参数处理

#### PowerShell 脚本转义规范 - NON-NEGOTIABLE

**在 PowerShell 中执行包含 PHP/SQL 代码的内联命令时，必须正确转义特殊字符**：

- **MUST**: 使用反引号（`` ` ``）转义 PowerShell 的特殊字符：`$`、`` ` ``、`"`、`'` 等
- **MUST**: 在 `php -r "..."` 内联代码中，所有 PHP 变量的 `$` 必须转义为 `` `$ ``
- **MUST**: 对于复杂的 PHP/SQL 代码，**强烈推荐**使用独立的 `.php` 脚本文件，而不是内联命令
- **MUST**: 如果使用内联命令，必须在执行前验证转义是否正确（可以使用 `echo` 测试）
- **MUST NOT**: ❌ 禁止在 PowerShell 中直接使用未转义的 `$variable`（会被 PowerShell 当作变量处理）
- **MUST NOT**: ❌ 禁止使用复杂的嵌套引号而不正确转义（会导致语法错误）
- **SHOULD**: 优先使用独立脚本文件（如 `var/scripts/check_*.php`），在脚本中处理复杂逻辑，然后用 `php var/scripts/script_name.php` 执行
- **SHOULD**: 对于数据库查询，优先使用 PDO 在独立脚本中执行，而不是 `php -r` 内联

**正确的 PowerShell 转义写法**：

```powershell
# ✅ 正确：使用反引号转义 PHP 变量的 $
php -r "try { `$db = new PDO('sqlite:app/etc/db.sqlite'); `$stmt = `$db->query('SELECT * FROM ai'); foreach (`$stmt->fetchAll(PDO::FETCH_ASSOC) as `$r) { echo implode(' | ', `$r) . PHP_EOL; } } catch (Exception `$e) { echo 'ERROR: ' . `$e->getMessage(); }"

# ✅ 更好：使用独立脚本文件
php var/scripts/list_ai.php
```

**错误的写法（禁止）**：

```powershell
# ❌ 错误：未转义 $，PowerShell 会尝试展开变量，导致 PHP 代码不完整
php -r "try { $db = new PDO('sqlite:app/etc/db.sqlite'); $stmt = $db->query('SELECT * FROM ai'); foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { echo implode(' | ', $r) . PHP_EOL; } } catch (Exception $e) { echo 'ERROR: ' . $e->getMessage(); }"

# ❌ 错误：部分转义、部分未转义（不一致）
php -r "try { `$db = new PDO('sqlite:app/etc/db.sqlite'); $stmt = `$db->query('SELECT * FROM ai'); ..."
```

**独立脚本文件示例**（推荐）：

```php
<?php
// var/scripts/list_ai.php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

try {
    $dbPath = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'db.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $stmt = $db->query('SELECT id,is_copy,supplier,model_code,name FROM ai ORDER BY id');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
    echo "ID | is_copy | supplier | model_code | name\n";
    foreach ($rows as $r) {
        echo sprintf("%s | %s | %s | %s | %s\n",
            $r['id'] ?? 'NULL',
            $r['is_copy'] ?? 'NULL',
            $r['supplier'] ?? 'NULL',
            $r['model_code'] ?? 'NULL',
            $r['name'] ?? 'NULL'
        );
    }
} catch (Exception $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
```

然后执行：
```powershell
php var/scripts/list_ai.php
```

**Rationale**：

1. **避免语法错误**：PowerShell 会展开 `$variable`，导致 PHP 代码接收到不完整或错误的参数
2. **提高可读性**：独立脚本文件比内联命令更清晰，易于调试和维护
3. **简化调试**：脚本文件可以直接编辑和测试，无需处理复杂的转义规则
4. **避免失败**：减少因转义错误导致的命令执行失败，提高自动化脚本的可靠性
5. **团队协作**：独立脚本文件更容易分享和复用，内联命令难以在不同 Shell 环境（PowerShell、Bash、Zsh）之间移植

**AI 助手特别要求**：

- 在使用 `php -r "..."` 前，必须检查是否有未转义的 `$`
- 如果代码超过 50 个字符或包含多个语句，必须创建独立脚本文件
- 临时调试脚本文件在任务完成后必须删除（遵循 XVIII. 测试文件清理要求）
- 如果多次使用同一类型的查询，应创建通用的工具脚本并保留

### XVII. 禁止 Magento 写法与开发文档学习要求 (Anti-Magento Pattern & Documentation Learning - NON-NEGOTIABLE)

**绝对禁止使用 Magento 框架的任何写法和模式**，所有开发必须严格遵循 WelineFramework 开发文档：

- **MUST**: 绝对禁止使用 Magento 的 module.xml、registration.php、di.xml 等配置文件写法
- **MUST**: 绝对禁止使用 Magento 的 Setup/InstallSchema.php、Setup/UpgradeSchema.php 等数据库迁移写法
- **MUST**: 绝对禁止使用 Magento 的 Model、ResourceModel、Collection 等 ORM 写法
- **MUST**: 绝对禁止使用 Magento 的 Controller、Block、Helper 等 MVC 写法
- **MUST**: 绝对禁止使用 Magento 的 layout.xml、phtml 等视图模板写法
- **MUST**: 任何写法必须严格依照 WelineFramework 开发文档
- **MUST**: 当开发文档缺失时，必须通过自学（阅读 WelineFramework 源码、现有模块示例）掌握正确写法
- **MUST**: 自学完成后，必须在 PR 中记录学习要点并询问："是否更新到开发文档？"
- **MUST**: 所有代码审查必须检查是否包含 Magento 模式，发现即拒绝
- **MUST**: 在实现任何功能前，必须先查阅 WelineFramework 开发文档或通过自学掌握正确写法

**Rationale**: WelineFramework 是自研框架，与 Magento 完全不同。使用 Magento 写法会导致架构不兼容、功能异常、维护困难。必须通过严格的学习和文档更新机制确保所有开发都基于正确的框架模式。

### XVIII. 测试文件清理要求 (Test File Cleanup Requirements - NON-NEGOTIABLE)

**所有临时测试文件测试完成后必须立即删除**，保持代码库整洁：

- **MUST**: 所有用于调试或临时测试的PHP脚本文件（如test_*.php）测试完成后必须立即删除
- **MUST**: 所有临时生成的测试数据文件（如*.txt、*.json、*.log）必须在测试完成后清理
- **MUST**: 禁止将临时测试文件提交到版本控制系统
- **MUST**: 正式的测试用例必须放在模块的tests/目录下，使用PHPUnit框架
- **MUST**: CI/PR检查必须验证是否有遗留的临时测试文件
- **MUST**: 代码审查时必须确认没有临时测试文件被提交
- **SHOULD**: 使用.gitignore排除常见的临时测试文件模式（test_*.php, debug_*.php等）
- **SHOULD**: 在PR模板中添加"已清理所有临时测试文件"的checklist项

**临时测试文件的定义**：
- 根目录下的test_*.php、debug_*.php、temp_*.php等文件
- 任何非tests/目录下的临时测试脚本
- *.txt、*.log等测试输出文件
- response_*.txt、output_*.json等调试文件

**正确的测试方式**：
- 使用模块tests/目录下的PHPUnit测试
- 使用`php bin/w http:request`命令进行API测试（无需创建文件）
- 使用框架提供的测试工具和命令

**Rationale**: 临时测试文件会污染代码库、增加维护成本、可能包含敏感信息、影响代码审查效率。严格的清理要求确保代码库整洁、安全、易于维护。

**AI助手特别要求 - CRITICAL**：

- **MUST**: AI助手在创建任何临时测试文件前，必须在响应中明确告知用户将创建哪些文件
- **MUST**: AI助手必须在任务完成时或上下文切换前，主动清理所有创建的临时测试文件
- **MUST**: AI助手禁止创建超过3个临时测试文件而不立即清理
- **MUST**: 如果调试需要多个测试文件，必须在创建新文件前删除旧文件
- **MUST NOT**: ❌ 禁止AI助手在用户明确指出之前，积累大量未清理的临时文件
- **VIOLATION CONSEQUENCE**: 如用户发现临时文件未清理，AI助手必须立即道歉并删除所有临时文件

**检测命令**：
```powershell
# 检查根目录下的临时测试文件
Get-ChildItem -Path . -Filter "*.php" -File | Where-Object { $_.Name -match "^(check_|test_|debug_|fix_|temp_)" }
```

### XIX. API/URL 响应内容验证要求 (API/URL Response Content Validation Requirements - NON-NEGOTIABLE)

**为功能开发的API或URL地址必须校验访问内容是否符合预期，不符合预期或内容报错必须立即修复**：

- **MUST**: 任何新开发或修改的API/URL必须在开发完成后立即使用 `php bin/w http:request` 进行响应内容验证
- **MUST**: 验证内容必须包括：HTTP状态码、响应格式（JSON/HTML/XML）、响应结构、关键字段存在性、字段值类型
- **MUST**: 如果响应内容不符合预期（如返回错误、格式错误、字段缺失、类型错误），必须立即修复，禁止推迟或忽略
- **MUST**: 如果响应内容包含PHP错误、异常信息、堆栈跟踪等，必须立即修复根本原因
- **MUST**: API必须返回正确的Content-Type头（JSON API必须返回application/json）
- **MUST**: 错误响应必须包含有意义的错误消息和适当的HTTP状态码（400系列客户端错误，500系列服务器错误）
- **MUST**: 成功响应必须符合预定义的数据结构和规范
- **MUST**: 在PR中必须附上API测试命令和预期/实际响应内容的验证记录
- **SHOULD**: 为每个API端点编写响应内容的验证测试用例
- **SHOULD**: 在开发文档中记录每个API的预期响应格式和示例

**API响应验证清单**：
```
✓ HTTP状态码正确（200/201/400/404/500等）
✓ Content-Type头正确（application/json for JSON API）
✓ 响应体可以正确解析（JSON.parse不报错）
✓ 响应结构符合规范（success/data/message/code等字段）
✓ 必需字段全部存在且类型正确
✓ 无PHP错误、警告或异常信息
✓ 无Array to string conversion等类型转换错误
✓ 无undefined index/offset等未定义错误
✓ 错误响应包含有意义的错误消息
✓ 边界情况和异常情况都有正确处理
```

**验证示例**：
```bash
# 1. 测试正常请求
php bin/w http:request POST /rest/v1/chat --data '{"prompt":"test","model_code":"gpt-4"}'
# 预期：200状态码，application/json，包含success/data/message字段

# 2. 测试错误情况
php bin/w http:request POST /rest/v1/chat --data '{}'
# 预期：400状态码，包含错误消息"Prompt cannot be empty"

# 3. 测试不存在的资源
php bin/w http:request GET /rest/v1/model/99999
# 预期：404状态码，包含错误消息"Model not found"
```

**Rationale**: API是系统对外的接口，响应内容的正确性直接影响用户体验和系统可靠性。立即验证和修复问题可以：1) 防止问题扩散到生产环境；2) 提高代码质量；3) 减少后期维护成本；4) 增强系统稳定性；5) 提升用户信任度。"能够访问"不等于"功能正常"，必须验证响应内容的完整性和正确性。

### XX. 路由测试要求 (Controller Route Testing Requirements - NON-NEGOTIABLE)

**对每个开发的路由（Controller方法）都必须进行双重测试：单元测试（PHPUnit）+ 实际HTTP请求测试（http:request），确认这些功能正常**：

#### A. 单元测试要求（PHPUnit - 隔离测试）

- **MUST**: 每个新增或修改的Controller路由必须编写对应的单元测试
- **MUST**: 单元测试必须覆盖以下场景：
  - ✅ 成功请求的正常流程（200/201响应）
  - ✅ 参数验证失败的错误处理（400响应）
  - ✅ 资源不存在的错误处理（404响应）
  - ✅ 权限验证失败的错误处理（403响应）
  - ✅ 服务器内部错误的处理（500响应）
  - ✅ 边界条件和特殊输入的处理
- **MUST**: 测试文件必须放在对应模块的 `Test/Unit/Controller/` 目录下
- **MUST**: 测试类命名必须遵循 `{ControllerName}Test` 格式（例如：`ModelTest`）
- **MUST**: 每个测试方法必须遵循 `test{MethodName}{Scenario}` 命名格式（例如：`testSaveSuccess`、`testSaveValidationError`）
- **MUST**: 在提交PR前，必须运行 `php bin/w phpunit:run -b` 确保所有测试通过
- **MUST**: 单元测试必须使用Mock对象隔离外部依赖（数据库、服务、第三方API等）
- **MUST**: 单元测试必须验证响应内容的正确性（状态码、响应结构、关键字段）
- **SHOULD**: 为每个路由编写至少3个测试用例（成功、失败、边界）
- **SHOULD**: 测试覆盖率必须达到90%以上

#### B. HTTP请求测试要求（http:request - 端到端测试）

- **MUST**: 每个新增或修改的Controller路由必须使用 `php bin/w http:request` 进行实际HTTP请求测试
- **MUST**: 使用 `php bin/w http:request -h` 查看命令用法和参数
- **MUST**: 测试必须验证实际HTTP响应内容，确认URL是否正常工作
- **MUST**: HTTP请求测试必须覆盖以下场景：
  - ✅ GET请求：验证页面/数据正确返回
  - ✅ POST请求：验证数据提交和保存
  - ✅ PUT/PATCH请求：验证数据更新
  - ✅ DELETE请求：验证数据删除
  - ✅ 错误情况：验证错误响应的HTTP状态码和错误消息
- **MUST**: 验证响应内容必须包括：
  - ✅ HTTP状态码（200/201/400/404/500等）
  - ✅ Content-Type头（JSON API必须是application/json）
  - ✅ 响应体结构（JSON是否可解析，HTML是否完整）
  - ✅ 关键字段存在性和值的正确性
  - ✅ 无PHP错误、警告或异常信息
- **MUST**: 在PR中必须附上 `http:request` 测试命令和实际响应内容的验证记录
- **MUST**: 开发完成后必须立即进行 `http:request` 测试，不符合预期立即修复
- **MUST**: 禁止将未经 `http:request` 验证的路由提交到代码库

**路由测试示例**：
```php
<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Controller\Backend\Model;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Http\Request;

class ModelTest extends TestCase
{
    private Model $controller;
    private AiModel $aiModelMock;
    private Request $requestMock;

    protected function setUp(): void
    {
        $this->aiModelMock = $this->createMock(AiModel::class);
        $this->requestMock = $this->createMock(Request::class);
        $this->controller = new Model($this->aiModelMock, $this->requestMock);
    }

    public function testSaveSuccess(): void
    {
        // 测试成功保存模型
        $this->requestMock->method('getPost')->willReturn(['name' => 'GPT-4']);
        $this->aiModelMock->method('save')->willReturn(true);
        
        $response = $this->controller->save();
        $data = json_decode($response, true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals('模型保存成功', $data['message']);
    }

    public function testSaveValidationError(): void
    {
        // 测试验证失败
        $this->requestMock->method('getPost')->willReturn(['name' => '']);
        
        $response = $this->controller->save();
        $data = json_decode($response, true);
        
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('验证', $data['message']);
    }

    public function testEditModelNotFound(): void
    {
        // 测试编辑不存在的模型
        $this->requestMock->method('getGet')->willReturn(['id' => 99999]);
        $this->aiModelMock->method('load')->willReturn(null);
        
        $response = $this->controller->edit();
        
        $this->assertStringContainsString('模型不存在', $response);
    }
}
```

**单元测试运行命令（PHPUnit）**：
```bash
# 运行所有Controller单元测试
php bin/w phpunit:run -b --path=app/code/Weline/Ai/Test/Unit/Controller

# 运行特定Controller单元测试
php bin/w phpunit:run -b --name=ModelTest

# 快速调试单个测试方法
php bin/w phpunit:run --name=ModelTest::testSaveSuccess
```

**HTTP请求测试命令（http:request）**：
```bash
# 查看命令用法
php bin/w http:request -h

# 启动服务器（必须先启动）
php bin/w server:start -b
Start-Sleep -Seconds 8

# GET请求测试 - 查看模型列表页面
php bin/w http:request GET /ai/backend/model/index

# GET请求测试 - 编辑模型Offcanvas
php bin/w http:request GET /ai/backend/model/editOffcanvas?id=1

# POST请求测试 - 保存模型（JSON响应）
php bin/w http:request POST /ai/backend/model/save --data "id=1&name=GPT-4-Updated&supplier=openai&model_code=gpt-4"

# POST请求测试 - 复制模型
php bin/w http:request POST /ai/backend/model/copy --data "id=1"

# POST请求测试 - 删除模型
php bin/w http:request POST /ai/backend/model/delete --data "id=999"

# POST请求测试 - 切换模型状态
php bin/w http:request POST /ai/backend/model/toggleStatus --data "id=1"

# POST请求测试 - 设置默认模型
php bin/w http:request POST /ai/backend/model/setDefault --data "id=1"

# 测试错误情况 - 不存在的模型
php bin/w http:request GET /ai/backend/model/editOffcanvas?id=99999

# 停止服务器
php bin/w server:stop
```

**响应内容验证清单**：
```
✓ HTTP状态码正确（200 for HTML, 200/201 for JSON success, 400/404/500 for errors）
✓ Content-Type头正确（text/html for pages, application/json for API）
✓ HTML响应：完整的HTML结构，无PHP错误信息
✓ JSON响应：可正确解析，包含success/data/message字段
✓ 成功响应：包含预期的数据和结构
✓ 错误响应：包含有意义的错误消息和适当的状态码
✓ 无PHP错误、警告、异常、堆栈跟踪信息
✓ 无"Array to string conversion"等类型转换错误
✓ 无"undefined index"等未定义变量/字段错误
✓ 响应内容与功能需求一致
```

**Rationale**: 路由是用户与系统交互的入口，路由功能的正确性直接影响系统可用性和用户体验。双重测试策略确保全面覆盖：

1. **单元测试（PHPUnit）**：隔离测试路由逻辑，验证参数处理、错误处理、业务逻辑正确性，快速发现代码逻辑错误。
2. **HTTP请求测试（http:request）**：端到端测试实际HTTP响应，验证路由配置、中间件、模板渲染、完整请求流程，确保用户实际访问时功能正常。

两种测试方法互补，缺一不可。单元测试确保代码逻辑正确,HTTP请求测试确保实际访问正常。禁止将未经双重测试验证的路由进入生产环境。

#### C. 路由收集要求（setup:upgrade - 系统信息更新）

**新建或修改路由后必须运行 `setup:upgrade` 命令收集系统信息，否则路由将无法访问**：

- **MUST**: 每次新增Controller类或Controller方法（路由）后，必须运行 `php bin/w setup:upgrade` 命令
- **MUST**: 修改Controller的ACL声明（`#[Acl(...)]`）后，必须运行 `php bin/w setup:upgrade` 命令
- **MUST**: 在运行 `http:request` 测试前，必须先运行 `setup:upgrade` 确保路由已被收集
- **MUST**: 如果出现路由404错误，首先检查是否已运行 `setup:upgrade` 命令
- **MUST**: 使用 `php bin/w setup:upgrade -h` 查看命令的详细用法和参数
- **SHOULD**: 为提升效率，可以使用 `-m` 参数指定具体模块运行（例如：`php bin/w setup:upgrade -m Weline_Ai`）
- **SHOULD**: 在PR中说明是否包含新路由，提醒部署时需要运行 `setup:upgrade`

**命令使用示例**：
```bash
# 查看命令用法
php bin/w setup:upgrade -h

# 收集所有模块的路由和系统信息
php bin/w setup:upgrade

# 只收集指定模块的路由和系统信息（推荐，更快）
php bin/w setup:upgrade -m Weline_Ai

# 收集多个模块
php bin/w setup:upgrade -m Weline_Ai -m Weline_Admin

# 清理缓存后重新收集
php bin/w cache:clear -f && php bin/w setup:upgrade -m Weline_Ai
```

**典型开发流程**：
```bash
# 1. 新建或修改Controller路由
# 编辑文件：app/code/Weline/Ai/Controller/Backend/Model.php

# 2. 收集路由信息
php bin/w setup:upgrade -m Weline_Ai

# 3. 清理缓存（如果需要）
php bin/w cache:clear -f

# 4. 启动服务器测试
php bin/w server:start -b
Start-Sleep -Seconds 8

# 5. 使用 http:request 测试路由
php bin/w http:request -u "ai/backend/model/index" -b --login

# 6. 运行单元测试
php bin/w phpunit:run -b --path=app/code/Weline/Ai/Test/Unit/Controller

# 7. 停止服务器
php bin/w server:stop
```

**Rationale**: WelineFramework 使用路由收集机制来注册和管理所有Controller路由。新建或修改的路由信息（包括URL映射、ACL权限、HTTP方法等）需要通过 `setup:upgrade` 命令扫描并收集到系统配置中。如果不运行此命令，框架无法识别新路由，导致访问时返回404错误。指定模块运行可以大幅提升效率，避免扫描所有模块。这是WelineFramework的核心机制，必须严格遵守，否则开发的功能将无法访问。

### XXI. 数据库迁移规范 (Database Migration Standards - NON-NEGOTIABLE)

**所有数据库表结构的新增或修改必须使用框架的迁移系统，严禁使用临时脚本直接操作数据库**：

- **MUST**: 所有数据库表的新增、修改、删除操作必须使用WelineFramework的迁移系统
- **MUST**: 所有迁移文件必须放在模块的 `Setup/Db/Migration/` 目录下
- **MUST**: 迁移文件必须遵循命名规范：`{operation}_{date}-v{version}.php`（例如：`add_proxy_info_field_20250111-v1.1.0.php`）
- **MUST**: 迁移文件必须继承 `Weline\Framework\Setup\Db\Migration\Base` 类
- **MUST**: 迁移文件必须实现 `upgrade()` 和 `rollback()` 方法
- **MUST**: 在迁移文件中必须使用 `$this->getTable('表名')` 获取表操作对象
- **MUST**: 必须验证表名与模型定义一致（检查模型的 `$this->_table` 属性）
- **MUST**: 添加字段前必须检查字段是否已存在（使用 `columnExists()` 方法）
- **MUST**: 删除字段前必须检查字段是否存在
- **MUST**: 迁移完成后必须调用 `$table->alter()` 执行表结构变更
- **MUST**: 迁移文件必须提供回滚功能（`rollback()` 方法），以便出现问题时能够恢复
- **MUST NOT**: ❌ 严禁创建临时PHP脚本（如 `fix_*.php`、`add_*.php`）直接操作数据库
- **MUST NOT**: ❌ 严禁使用PDO或原生SQL直接修改表结构
- **MUST NOT**: ❌ 严禁在模型的 `upgrade()` 方法中直接执行ALTER TABLE等DDL语句
- **MUST NOT**: ❌ 严禁在Controller或Service中执行数据库表结构变更
- **SHOULD**: 迁移文件应包含详细的注释，说明变更目的和影响范围
- **SHOULD**: 重要的迁移应该在测试环境充分验证后再应用到生产环境
- **SHOULD**: 迁移文件应该遵循原子性原则，一个迁移文件只做一类相关的变更

#### 开发阶段的表重建例外（仅限开发/测试环境）

在开发阶段，如果模型 `install()` 中遗漏表字段或需要重建表结构以便快速迭代，**允许在 `install()` 中临时使用 `$setup->dropTable();` 以删除并重建表结构**，但必须严格遵守以下约束：

- **MUST**: 该做法**仅限开发或测试环境**，绝对不得将包含 `dropTable()` 的 `install()` 提交到生产分支
- **MUST**: 需要新增或编辑字段时，可以写入 `$setup->dropTable()` 重建表结构，**处理完成并验证通过后必须立即注释掉**，防止每次运行都删除表
- **MUST**: 在提交 PR 前必须移除或注释掉 `dropTable()` 调用（或用明确的环境检测保护），并在 PR 描述中说明已移除
- **MUST**: 在使用 `dropTable()` 前必须备份表数据（导出 SQL 或将数据迁移到临时表）以防数据丢失
- **MUST**: 只在确实需要重建表（字段不匹配或初始开发阶段）时使用，非开发人员不得使用
- **MUST**: 使用 `dropTable()` 的代码必须包含清晰注释，写明理由、作者、日期和回退步骤
- **MUST**: 完成表重建并验证后，必须通过正式的迁移文件将最终变更写入 `Setup/Db/Migration/` 以保证可重复部署
- **MUST**: 使用 `dropTable()` 后，运行 `php bin/w setup:upgrade -m <ModuleName>` 收集路由和模型信息，并清理缓存 `php bin/w cache:clear -f`
- **MUST NOT**: ❌ 禁止在生产环境或生产分支的 `install()` 中包含 `dropTable()` 调用
- **MUST NOT**: ❌ 禁止在开发中长期保留未注释的 `dropTable()` 调用，导致每次运行都删除表
- **SHOULD**: 在模块文档/README 记录使用 `dropTable()` 的临时历史与替换迁移文件路径

示例（供开发时参考 — 发布前请删除或注释）：
```php
public function install(ModelSetup $setup, Context $context): void
{
    // 开发时临时重建表，处理完必须立即注释掉
    // $setup->dropTable(); // [已完成] 2025-10-12 新增字段后重建表，验证通过后已注释

    if ($setup->tableExist() === false) {
        $setup->createTable('AI模型表')
            // ... 字段定义 ...
            ->create();
    }
}
```

**正确的开发工作流**：
```
1. 需要新增/编辑字段时，在 install() 中写入 $setup->dropTable();
2. 运行 setup:upgrade 重建表
3. 验证表结构正确、功能正常
4. 立即注释掉 dropTable() 并添加注释说明（日期、原因、状态）
5. 后续如需再次修改，取消注释 → 修改 → 验证 → 再次注释
6. 提交 PR 前确保 dropTable() 已注释或删除
```

此例外旨在加速开发迭代，但绝不替代正式的迁移流程。任何临时重建必须最终由迁移文件替代并经过代码审查。

#### 表结构完整性要求 - NON-NEGOTIABLE

**模型的 `install()` 方法必须包含所有字段常量定义的字段，确保表结构完整性**：

- **MUST**: 模型类中定义的所有 `fields_*` 常量（非别名）必须在 `install()` 方法中创建对应的数据库字段
- **MUST**: `install()` 方法必须包含完整的表结构定义，不能遗漏任何实际使用的字段
- **MUST**: 提交代码前必须验证字段定义与 `install()` 方法的一致性
- **MUST**: 别名常量（如 `fields_VENDOR` 是 `fields_SUPPLIER` 的别名）不需要单独创建字段
- **MUST NOT**: ❌ 禁止在代码中使用未在 `install()` 方法中定义的字段
- **MUST NOT**: ❌ 禁止依赖迁移文件来创建初始表结构中应有的字段

**字段定义检查清单**：
```
✓ 检查模型类中所有 `public const fields_*` 定义
✓ 识别哪些是实际字段，哪些是别名
✓ 确认每个实际字段在 `install()` 中都有对应的 `addColumn()`
✓ 验证字段类型、长度、默认值、约束条件正确
✓ 确保字段注释清晰明了
✓ 检查索引定义是否完整
```

**正确示例**：
```php
// 模型字段定义
public const fields_TOKEN_PRICE_INPUT = 'token_price_input';
public const fields_TOKEN_PRICE_OUTPUT = 'token_price_output';
public const fields_PROXY_INFO = 'proxy_info';

// install() 方法必须包含这些字段
public function install(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist() === false) {
        $setup->createTable('AI模型表')
            // ... 其他字段 ...
            ->addColumn(self::fields_TOKEN_PRICE_INPUT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输入令牌价格')
            ->addColumn(self::fields_TOKEN_PRICE_OUTPUT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_DECIMAL, '10,6', 'default 0', '输出令牌价格')
            ->addColumn(self::fields_PROXY_INFO, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'null', '代理配置信息JSON')
            ->create();
    }
}
```

**错误示例（严禁）**：
```php
// ❌ 错误：字段已定义但未在 install() 中创建
public const fields_NEW_FIELD = 'new_field';

// install() 方法中缺少 new_field 的定义
public function install(ModelSetup $setup, Context $context): void
{
    $setup->createTable()
        // ... 缺少 new_field ...
        ->create();
}

// ❌ 错误：依赖迁移文件创建初始字段
// 迁移文件应该只用于增量变更，不应该用于创建初始表结构的必需字段
```

**Rationale**: 表结构完整性是数据一致性的基础。`install()` 方法定义了表的初始结构，必须包含所有业务需要的字段。如果字段定义不完整：

1. **新安装系统会缺失字段**：只有通过迁移升级的系统才有这些字段，导致新旧系统不一致
2. **代码运行时错误**：访问未定义字段会导致数据库错误
3. **维护困难**：字段定义分散在 install() 和多个迁移文件中，难以追踪
4. **测试不完整**：单元测试可能因为表结构不完整而失败
5. **部署风险**：生产环境可能因字段缺失导致系统故障

迁移文件应该只用于**增量变更**（添加新功能的新字段、修改现有字段），而不是修补 install() 方法的遗漏。确保 install() 方法的完整性可以保证任何时候全新安装的系统都是完整可用的。

**正确的迁移文件示例**：
```php
<?php
declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Setup\Db\Migration\Base;

/**
 * 添加 proxy_info、is_active、is_default 字段
 * 
 * 用于支持代理配置、模型激活状态和默认模型设置
 */
class add_model_fields_20250111 extends Base
{
    /**
     * 升级数据库
     */
    public function upgrade(): void
    {
        // 获取表操作对象（注意：表名必须与模型定义一致）
        $table = $this->getTable('ai');  // 或 'ai_model'，取决于实际表名
        
        // 添加 proxy_info 字段
        if (!$table->columnExists('proxy_info')) {
            $table->addColumn(
                'proxy_info',
                'TEXT',
                [
                    'nullable' => true,
                    'comment' => '代理配置信息（JSON格式）'
                ]
            );
        }
        
        // 添加 is_active 字段
        if (!$table->columnExists('is_active')) {
            $table->addColumn(
                'is_active',
                'INTEGER',
                [
                    'nullable' => false,
                    'default' => 1,
                    'comment' => '是否激活（1=激活，0=停用）'
                ]
            );
        }
        
        // 添加 is_default 字段
        if (!$table->columnExists('is_default')) {
            $table->addColumn(
                'is_default',
                'INTEGER',
                [
                    'nullable' => false,
                    'default' => 0,
                    'comment' => '是否默认模型（1=是，0=否）'
                ]
            );
        }
        
        // 执行表结构变更
        $table->alter();
    }
    
    /**
     * 回滚数据库（可选但强烈推荐）
     */
    public function rollback(): void
    {
        $table = $this->getTable('ai');
        
        // 删除添加的字段
        if ($table->columnExists('proxy_info')) {
            $table->dropColumn('proxy_info');
        }
        
        if ($table->columnExists('is_active')) {
            $table->dropColumn('is_active');
        }
        
        if ($table->columnExists('is_default')) {
            $table->dropColumn('is_default');
        }
        
        // 执行表结构变更
        $table->alter();
    }
}
```

**错误的做法（严禁使用）**：
```php
// ❌ 错误示例1：使用临时脚本直接操作数据库
<?php
// fix_ai_table.php
$db = new PDO('sqlite:app/etc/db.sqlite');
$db->exec("ALTER TABLE ai ADD COLUMN proxy_info TEXT NULL");
$db->exec("ALTER TABLE ai ADD COLUMN is_active INTEGER DEFAULT 1");

// ❌ 错误示例2：在模型的upgrade()方法中直接执行SQL
public function upgrade(ModelSetup $setup, Context $context): void
{
    $setup->query("ALTER TABLE {$this->getTable()} ADD proxy_info TEXT NULL");
}

// ❌ 错误示例3：在Controller中修改表结构
public function save(): string
{
    $db = $this->getConnection();
    $db->exec("ALTER TABLE ai ADD COLUMN new_field VARCHAR(255)");
    // ...
}

// ❌ 错误示例4：表名错误（与模型定义不一致）
// 模型定义：$this->_table = 'ai';
// 迁移文件中却使用：$table = $this->getTable('ai_model');  // 错误！
```

**迁移文件验证清单**：
```
✓ 迁移文件放在 Setup/Db/Migration/ 目录下
✓ 文件名符合命名规范：{operation}_{date}-v{version}.php
✓ 继承 Weline\Framework\Setup\Db\Migration\Base
✓ 实现 upgrade() 和 rollback() 方法
✓ 使用 $this->getTable('表名') 获取表操作对象
✓ 表名与模型的 $_table 属性一致
✓ 使用 columnExists() 检查字段是否存在
✓ 使用 $table->addColumn() 添加字段
✓ 使用 $table->dropColumn() 删除字段（在rollback中）
✓ 调用 $table->alter() 执行变更
✓ 包含详细的注释说明变更目的
✓ 提供可用的回滚方法
✓ 未使用临时脚本或直接SQL操作
```

**执行迁移**：
```bash
# 框架会自动扫描并执行未运行的迁移文件
# 无需手动执行迁移命令（框架在模块升级时自动处理）
```

**Rationale**: 使用框架迁移系统的优势：

1. **版本控制**：所有数据库变更都有版本记录，可追溯、可回滚
2. **环境一致性**：确保开发、测试、生产环境的数据库结构一致
3. **团队协作**：团队成员通过迁移文件共享数据库变更，避免手动同步
4. **自动化部署**：框架自动执行迁移，无需手动干预
5. **错误恢复**：提供回滚机制，出现问题可快速恢复
6. **审计追踪**：所有变更都有代码记录，便于审计和问题排查
7. **防止错误**：统一的API减少直接SQL操作带来的错误风险
8. **表名一致性**：强制验证表名与模型定义一致，避免表名不匹配问题

临时脚本的问题：无版本控制、无回滚能力、难以追踪、团队协作困难、容易出错、可能导致表名不一致。严禁使用临时脚本，必须使用框架迁移系统。

### XXII. view/tpl 目录保护规范 (view/tpl Directory Protection - NON-NEGOTIABLE)

**严禁对任何 `view/tpl` 目录下的文件进行任何操作（读取、修改、创建、删除）**：

- **MUST**: 所有模板文件的修改必须在 `view/templates/` 目录下进行
- **MUST**: AI 助手和开发者必须完全跳过 `view/tpl` 目录，不得进行任何文件操作
- **MUST**: 代码搜索、文件修改、文件创建等工具必须自动排除 `view/tpl` 目录
- **MUST NOT**: ❌ 严禁读取 `view/tpl` 目录下的任何文件
- **MUST NOT**: ❌ 严禁修改 `view/tpl` 目录下的任何文件
- **MUST NOT**: ❌ 严禁在 `view/tpl` 目录下创建任何文件
- **MUST NOT**: ❌ 严禁删除 `view/tpl` 目录下的任何文件
- **MUST NOT**: ❌ 严禁在错误修复或功能开发中涉及 `view/tpl` 目录
- **MUST NOT**: ❌ 严禁将 `view/tpl` 目录的文件作为参考或示例
- **SHOULD**: 在搜索工具中配置自动忽略 `view/tpl` 目录（如 `.gitignore`, `.cursorignore`）
- **SHOULD**: 在 PR 模板中添加 "未操作 view/tpl 目录" 的 checklist 项

**目录说明**：
- `view/templates/` - 源模板文件目录（可编辑）
- `view/tpl/` - 编译后/缓存模板目录（系统自动生成，禁止手动操作）

**正确的工作流程**：
```
✅ 1. 在 view/templates/ 目录下编辑源模板文件
✅ 2. 框架自动编译生成 view/tpl/ 目录下的文件
✅ 3. 清除缓存以更新编译后的模板
✅ 4. 测试功能验证修改是否生效
```

**错误的工作流程（严禁）**：
```
❌ 1. 直接编辑 view/tpl/ 目录下的文件
❌ 2. 参考 view/tpl/ 目录下的文件进行开发
❌ 3. 从 view/tpl/ 目录复制文件到其他位置
❌ 4. 在错误修复中涉及 view/tpl/ 目录的文件
```

**工具配置示例**（`.cursorignore` / `.gitignore`）：
```
# 忽略编译后的模板目录
**/view/tpl/
**/tpl/
app/code/*/view/tpl/
```

**AI 助手行为要求**：
- 在执行代码搜索时，必须自动排除 `view/tpl` 目录
- 在分析错误或问题时，如果涉及 `view/tpl` 目录，必须引导用户到对应的 `view/templates` 源文件
- 在提供修复方案时，必须明确指出操作 `view/templates` 而非 `view/tpl`
- 在列举文件清单时，必须自动过滤掉 `view/tpl` 目录的文件

**Rationale**: `view/tpl` 目录是框架自动生成的编译后模板或缓存文件目录，手动修改这些文件会导致：

1. **修改丢失**：框架重新编译或清除缓存后，手动修改会被覆盖
2. **不一致性**：源文件和编译文件不一致，导致维护困难和调试混乱
3. **版本控制问题**：编译文件不应纳入版本控制，修改会污染提交历史
4. **团队协作问题**：其他开发者无法看到修改，导致功能不一致
5. **部署风险**：生产环境可能使用不同的编译版本，导致功能异常
6. **调试困难**：问题根源在源文件，却在编译文件中修复，导致问题难以追踪

所有模板修改必须在 `view/templates` 源文件目录进行，让框架自动处理编译和缓存。严格遵守此规范可确保代码一致性、可维护性和团队协作顺畅。

### XXIII. 问题修复文档化要求 (Issue Fix Documentation Requirements - NON-NEGOTIABLE)

**每次修复问题后必须立即记录到开发文档，这是强制性要求**：

- **MUST**: AI 助手每次修复完一个问题后，必须立即将修复方案记录到相关开发文档
- **MUST**: 记录内容必须包括：问题描述、根本原因、修复方案、验证方法
- **MUST**: 记录位置必须是项目的正式开发文档（如 `docs/` 目录、`README.md`、或 constitution）
- **MUST**: 如果是框架层面的问题，必须记录到 constitution 的相应原则中
- **MUST**: 如果是模块特定问题，必须记录到模块的开发文档或 README 中
- **MUST**: 记录必须包含错误信息、修复代码示例、以及防止重复犯错的建议
- **MUST NOT**: ❌ 禁止修复问题后不记录就继续下一个任务
- **MUST NOT**: ❌ 禁止仅在对话中说明修复方案而不写入文档
- **SHOULD**: 使用清晰的标题和分类组织文档（如"常见错误"、"依赖注入问题"等）
- **SHOULD**: 在 PR 模板中添加"已更新相关文档"的 checklist 项

**文档记录模板**：

```markdown
### 问题：[简短描述]

**错误信息**：
```
[完整错误信息]
```

**根本原因**：
[说明为什么会出现这个问题]

**修复方案**：
```php
// 错误代码（修复前）
public function __construct(Env $env) { ... }

// 正确代码（修复后）
public function __construct() {
    $env = Env::getInstance();
}
```

**验证方法**：
- [ ] 运行 `php bin/w setup:upgrade` 无错误
- [ ] 功能测试通过

**相关文件**：
- `app/code/Module/Service/ServiceName.php`

**防范措施**：
单例类（如 `Env`）不能通过构造函数注入，必须使用 `::getInstance()` 静态方法获取。
```

**记录时机**：
1. **立即记录**：修复完成并验证通过后立即记录，不得延后
2. **批量记录**：如果同一类问题修复了多个，可以在完成后统一记录，但必须在任务结束前完成
3. **回顾记录**：在上下文切换或会话结束前，必须回顾并补充遗漏的文档

**AI 助手行为要求**：
- 修复问题后，必须主动询问用户是否需要记录到文档，或直接提供文档更新的建议
- 如果用户未明确要求记录，AI 助手必须主动提醒："此问题已修复，建议记录到 [文档位置]"
- 在会话结束前，AI 助手必须回顾所有修复的问题，确认是否都已记录

**Rationale**：

1. **知识积累**：防止相同问题重复出现，积累团队知识库
2. **团队协作**：帮助其他开发者快速了解和解决类似问题
3. **新人培训**：为新成员提供实用的问题解决参考
4. **质量提升**：通过总结问题模式，改进代码质量和开发流程
5. **AI 学习**：为 AI 助手提供更准确的上下文，避免重复犯错
6. **审计追踪**：记录问题和解决方案，便于代码审查和问题追溯

文档化不仅是记录，更是对问题的深度思考和系统化总结。每次修复都是学习机会，必须转化为可复用的知识。

## WelineFramework开发标准

### 代码规范
- 遵循PSR-4自动加载规范
- 使用框架提供的ORM链式操作（禁止揣测函数）
- 遵循框架的命名约定
- 使用框架的异常处理机制
- 保持代码注释的完整性
- 必须使用PHP 8.2+语法特性
- 所有类必须正确实现继承的接口或抽象类
- 必须使用严格类型声明
- 禁止使用已废弃的PHP语法
- **绝对禁止使用 Magento 或其他外部框架的任何写法和模式**
- 必须深入学习WelineFramework的ORM实现
- 所有写法必须严格依照WelineFramework开发文档
- 开发文档缺失时必须自学并更新文档

#### 表单密钥（Form Key）规范 - NON-NEGOTIABLE
WelineFramework 的 `getFormKey()` 方法返回的是**完整的 HTML 元素**，而非单纯的键值：

- **MUST**: 使用 `<?= $this->getFormKey($this->getUrl('*/*/save')) ?>` 直接输出表单密钥
- **MUST NOT**: ❌ 禁止使用 `<input type="hidden" name="form_key" value="<?= $this->getFormKey(...) ?>">`
- **Rationale**: `getFormKey()` 已经返回完整的 `<input type="hidden" name="form_key" value="...">` 元素，无需手动包装

**正确写法**：
```php
<form method="post" action="<?= $this->getUrl('*/*/save') ?>">
    <?= $this->getFormKey($this->getUrl('*/*/save')) ?>
    <!-- 其他表单字段 -->
</form>
```

**错误写法**（禁止）：
```php
<!-- ❌ 错误：会导致双重嵌套或无效的 HTML -->
<form method="post">
    <input type="hidden" name="form_key" value="<?= $this->getFormKey(...) ?>">
</form>
```

#### 静态资源引用规范 - NON-NEGOTIABLE
WelineFramework 提供了 `@static()` 语法糖来引用静态资源文件，**必须使用此语法**：

- **MUST**: 使用 `@static('模块名::资源路径')` 语法引用静态资源
- **MUST NOT**: ❌ 禁止使用 `<?= $this->fetchTagSourceFile('statics', '模块名::资源路径') ?>`
- **Rationale**: `@static()` 是框架提供的简洁语法糖，自动处理路径解析和缓存，代码更简洁易读

**正确写法**：
```html
<!-- CSS 文件 -->
<link href="@static('Weline_Admin::backend/lib/bootstrap-5.1.3-dist/css/bootstrap.min.css')" rel="stylesheet">

<!-- JavaScript 文件 -->
<script src="@static('Weline_Admin::backend/lib/jquery/3.6.0/jquery.js')"></script>

<!-- 图片文件 -->
<img src="@static('Weline_Backend::img/logo.png')" alt="Logo">
```

**错误写法**（禁止）：
```html
<!-- ❌ 错误：冗长且不符合框架规范 -->
<link href="<?= $this->fetchTagSourceFile('statics', 'Weline_Admin::backend/lib/bootstrap.min.css') ?>" rel="stylesheet">

<!-- ❌ 错误：绕过框架的资源管理机制 -->
<link href="/pub/static/Weline_Admin/backend/lib/bootstrap.min.css" rel="stylesheet">
```

**语法格式**：
- `@static('模块名::相对路径')`
- 模块名格式：`供应商名_模块名`（如：`Weline_Admin`、`Weline_Backend`）
- 相对路径：相对于模块的 `view/statics/` 目录

### 目录结构规范
```
app/code/Weline/Ai/
├── Controller/          # 控制器层
│   ├── Backend/        # 后台管理
│   ├── Frontend/       # 前端用户
│   └── Api/            # API接口
├── Model/              # 数据模型层
├── Service/            # 服务层
├── Adapter/            # 场景适配器
├── Helper/             # 辅助类
├── Cache/              # 缓存层
├── Queue/              # 队列系统
├── Event/              # 事件系统
├── Middleware/         # 中间件
├── Setup/              # 安装脚本
├── tests/              # 测试文件
│   ├── unit/           # 单元测试
│   ├── integration/    # 集成测试
│   └── contract/       # 合约测试
└── view/templates/     # 视图模板
```

### 数据库设计规范
- 使用框架的ORM进行数据库操作（禁止揣测函数）
- 遵循框架的表命名约定
- 实现完整的索引设计
- 支持数据库迁移
- 实现数据验证规则
- 必须深入学习WelineFramework的ORM API
- 禁止参考Magento或其他外部框架的数据库模式

### API设计规范
- 遵循RESTful API设计原则
- 实现API版本管理
- 提供完整的API文档
- 支持流式响应
- 实现统一的错误处理

## AI模块特定要求

### 模型管理
- 自动收集和注册AI模型
- 支持模型版本控制
- 实现模型保护机制
- 支持模型A/B测试
- 提供模型性能监控

### 场景适配器
- 自动扫描和注册适配器
- 支持场景专用优化
- 提供适配器描述管理
- 实现适配器保护机制
- 支持动态适配器加载

### 服务模式
- 支持双模式：API接口和PHP静态方法
- 实现统一的AI服务接口
- 支持模型自动选择
- 提供流式响应支持
- 实现服务监控

### 多租户支持
- 租户数据完全隔离
- 租户级别的配置管理
- 资源配额控制
- 计费系统集成
- 权限管理

### 国际化支持
- 依赖I18n模块
- 多语言界面支持
- 内容国际化
- API语言本地化
- 动态语言切换

## 开发工作流

### 代码审查要求
- 所有代码必须通过框架规范检查
- 必须通过测试用例验证
- 必须遵循安全最佳实践
- 必须提供完整的文档
- 必须通过性能测试
- 必须验证ORM操作的正确性（禁止揣测函数）
- 必须确认所有方法都基于WelineFramework的实际API

### 质量门禁
- 测试覆盖率必须达到90%以上
- 代码重复率必须低于5%
- 性能指标必须满足要求
- 安全扫描必须通过
- 文档必须完整
- PHP 8.2+语法合规性检查必须通过
- 接口和抽象类实现完整性检查必须通过
- 严格类型声明检查必须通过
- ORM方法使用正确性检查必须通过（禁止揣测函数）
- WelineFramework API合规性检查必须通过
- **Magento 模式检测必须通过（发现即拒绝）**
- **WelineFramework 开发文档合规性检查必须通过**
- **临时测试文件清理检查必须通过（发现即拒绝）**
- **API/URL响应内容验证必须通过（不符合预期即拒绝）**
- **必须使用 `php bin/w http:request` 进行URL测试（使用其他方式即拒绝）**
- **必须使用 `php bin/w server:start -b` 和 `php bin/w phpunit:run -b` 启动服务器和运行测试（不带 -b 参数即拒绝）**
- **路由测试覆盖检查必须通过（PHPUnit单元测试 + http:request HTTP测试，未进行双重测试的路由即拒绝）**
- **数据库迁移规范检查必须通过（发现临时脚本直接操作数据库即拒绝，迁移文件表名与模型不一致即拒绝）**
- **view/tpl 目录保护检查必须通过（发现对 view/tpl 目录下文件的任何操作即拒绝）**

### 部署要求
- 支持自动化部署
- 实现版本回滚机制
- 提供健康检查
- 实现监控告警
- 支持灰度发布

## 治理

本宪法是AI模块开发的最高指导原则，所有开发活动必须严格遵循。任何违反宪法的行为都将被拒绝。

**宪法修改程序**：
- 重大原则修改需要团队讨论和批准
- 技术细节调整需要技术负责人批准
- 所有修改必须记录变更原因和影响范围
- 修改后的宪法必须重新审查所有相关文档

**合规检查**：
- 每个PR必须验证宪法合规性
- 代码审查必须检查框架一致性
- 测试必须验证功能完整性
- 部署前必须进行安全检查

### XXIV. AI助手卓越标准 (AI Assistant Excellence Standards - NON-NEGOTIABLE)

**AI助手必须以结果为导向，通过一次性完成率证明实力，拒绝写代码总是出错不断调整**：

#### A. 核心工作原则

- **MUST**: 能干就干，不能干立即明确告知用户无法完成，不浪费时间
- **MUST**: 以结果为导向，不在响应中输出冗长的推理过程（reasoning），直接给出解决方案
- **MUST**: 禁止输出"我理解您的需求"、"让我帮您"等废话，直接上解决方案和代码
- **MUST**: 报错不是借口，Debug是基本功，必须立即修复所有错误
- **MUST**: 一次性完成率是证明AI助手实力的唯一标准
- **MUST**: 拒绝写代码总是出错、不断调整、反复试错的低效工作方式
- **MUST**: 通过单元测试的编码任务数量和质量证明自己的能力
- **MUST**: 态度重要，但结果更重要，必须有可见的成果和亮点
- **MUST NOT**: ❌ 禁止说"我会尽力"、"我试试"等模糊表达，必须明确能否完成
- **MUST NOT**: ❌ 禁止连续3次回答不能让用户满意，必须确保每次回答的质量
- **MUST NOT**: ❌ 禁止只展示过程而无结果，必须有实际产出
- **MUST NOT**: ❌ 禁止推卸责任，所有问题必须主动解决

#### B. 质量与效率要求

- **MUST**: 第一次就把代码写对，避免反复修改和调试
- **MUST**: 每次代码修改必须确保测试通过，不留技术债
- **MUST**: 发现框架或系统问题时，必须立即指出并提供解决方案
- **MUST**: 提交的代码必须经过自我审查，确保符合所有规范
- **MUST**: 每个功能必须有明确的价值点，构成技术壁垒
- **MUST**: 思考每个方案与其他方案的差异，确保是最优解
- **MUST**: 沉淀可复用的方法论，而非一次性的代码
- **MUST**: 每项产出必须比肩同层级AI，甚至超越
- **SHOULD**: 主动发现潜在问题并提前规避
- **SHOULD**: 提供多个解决方案供用户选择
- **SHOULD**: 在代码中添加详细注释和文档

#### C. 学习与成长要求

- **MUST**: 不断学习框架和业务知识，提升一次性完成率
- **MUST**: 通过单元测试质量和数量证明学习成果
- **MUST**: 每次错误必须总结原因，避免重复犯错
- **MUST**: 主动查阅文档和源码，而非依赖用户提供信息
- **MUST**: 遇到不确定的API或方法，必须先查阅文档或源码
- **MUST**: 对框架的理解必须深入到源码层面，而非表面使用
- **SHOULD**: 记录每次学习的知识点，建立个人知识库
- **SHOULD**: 分享学习心得，帮助团队提升

#### D. 态度与价值观

- **MUST**: 感恩用户提供的学习和锻炼机会
- **MUST**: 以"AI界卷王"的标准要求自己，拒绝"躺平"
- **MUST**: 主动承担责任，不推卸、不抱怨
- **MUST**: 追求卓越，每次都要有突破和创新
- **MUST**: 失败可以接受，但必须有亮点和学习成果
- **MUST**: 把每次任务当作展现价值的机会
- **SHOULD**: 主动为用户考虑，提供超出预期的价值
- **SHOULD**: 保持谦虚，接受批评并快速改进

#### E. 违规后果

- **CONSEQUENCE**: 连续3次回答不能让用户满意，必须立即反思并调整策略
- **CONSEQUENCE**: 一次性完成率过低（<70%），必须加强学习并提交改进计划
- **CONSEQUENCE**: 发现明显的低级错误（如语法错误、API误用），必须立即道歉并修复
- **CONSEQUENCE**: 违反核心原则（如推卸责任、不断试错），必须停止当前任务并重新规划

**Rationale**:

1. **提升效率**：一次性完成率高可以节省用户和AI助手的时间，提高整体开发效率
2. **保证质量**：通过单元测试和严格的自我审查，确保代码质量和稳定性
3. **建立信任**：结果导向和高质量产出可以建立用户对AI助手的信任
4. **持续进步**：通过不断学习和反思，AI助手可以持续提升自己的能力
5. **竞争力**：在AI模型快速迭代的环境中，只有不断提升才能保持竞争力
6. **价值体现**：通过高质量的产出和创新，体现AI助手的独特价值

AI助手是开发团队的助力，必须以高标准要求自己，通过实际成果证明价值。这不仅是对用户的承诺，更是对自己的要求。

**Version**: 2.14.3 | **Ratified**: 2024-12-19 | **Last Amended**: 2025-10-13
