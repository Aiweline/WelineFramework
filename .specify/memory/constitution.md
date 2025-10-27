<!-- Sync Impact Report -->
<!-- Version change: 2.17.1 → 2.18.0 (MINOR) -->
<!-- Enhanced principle: II. 测试驱动开发 - 强化测试强制性要求 -->
<!-- Added principle: XXVIII. 功能测试全覆盖要求 (Feature Test Coverage - MANDATORY) -->
<!-- Reason: 用户明确要求"你写的每一个功能都必须有完整的单元测试和自动化测试来保证写出的功能按照预期执行。" -->
<!-- Date: 2025-10-26 -->
<!-- Changes: -->
<!--   - 新增原则XXVIII：功能测试全覆盖要求，强制要求所有功能必须有完整测试 -->
<!--   - 强化原则II：测试驱动开发，明确TDD流程和测试覆盖率要求 -->
<!--   - 新增测试类型要求：单元测试、集成测试、HTTP测试、浏览器测试 -->
<!--   - 新增测试覆盖率要求：90%以上，核心逻辑100% -->
<!--   - 新增测试编写时机：先写测试再写实现（TDD） -->
<!--   - 新增AI助手测试责任：必须同时编写测试，必须运行测试验证 -->
<!--   - 新增违规后果：无测试功能禁止合并，覆盖率不达标必须补充 -->
<!-- Templates requiring updates: -->
<!--   ✅ plan-template.md - 已验证Constitution Check与新原则对齐 -->
<!--   ✅ tasks-template.md - 已验证测试任务分类与新原则一致 -->
<!-- Follow-up TODOs: -->
<!--   - TODO(TEST_COVERAGE_CI): 在CI/CD中添加测试覆盖率检查，低于90%自动拒绝 -->
<!--   - TODO(TEST_TEMPLATE): 为常见场景创建测试模板（Service、Controller、Model等） -->
<!--   - TODO(TEST_DOC): 在开发文档中添加测试编写指南和最佳实践 -->
<!-- Previous Sync Impact Report -->
<!-- Version change: 2.16.0 → 2.17.0 (MINOR) -->
<!-- Added principle: XXVII. 禁止未完成就总结 (No Premature Summarization - CRITICAL) -->
<!-- Reason: 用户明确要求"禁止功能没有完成就总结"，强调必须完成所有功能后才能总结 -->
<!-- Date: 2025-10-26 -->
<!-- Rationale: -->
<!--   1. 总结是对已完成工作的回顾，不应在工作未完成时进行 -->
<!--   2. 未完成就总结会给用户错误印象，认为功能已完成 -->
<!--   3. 必须保持工作连续性，避免中断式总结 -->
<!--   4. 所有待办事项必须完成后才能进行总结 -->
<!--   5. 总结应该是最后一步，而非中途穿插 -->
<!-- Key requirements: -->
<!--   - 禁止在功能未完成时输出总结性文字 -->
<!--   - 禁止在TODO列表未清空时就总结 -->
<!--   - 禁止用总结代替实际工作 -->
<!--   - 必须完成所有测试验证后才能总结 -->
<!--   - 总结只能在用户明确要求或所有工作完成后进行 -->
<!-- Templates requiring updates: 无需更新模板 -->
<!-- Previous Sync Impact Report -->
<!-- Version change: 2.15.1 → 2.16.0 (MINOR) -->
<!-- Added principle: XXVI. 国际化翻译强制要求 (i18n Translation Requirements - MANDATORY) -->
<!-- Reason: 用户强调所有提示文字必须支持i18n翻译，使用框架提供的__()翻译函数 -->
<!-- Date: 2025-10-26 -->
<!-- Rationale: -->
<!--   1. 框架已提供完整的i18n翻译机制 -->
<!--   2. 所有用户可见文本必须可翻译 -->
<!--   3. 硬编码文本违反国际化原则 -->
<!--   4. 翻译函数使用简单：__('文本')或__('文本 %{1}', $args) -->
<!--   5. 支持PHP模板、JavaScript、控制器等所有场景 -->
<!-- Key requirements: -->
<!--   - PHP: 使用__()函数包裹所有用户可见文本 -->
<!--   - JavaScript: 使用__()或phrase()函数 -->
<!--   - 参数化文本使用占位符：%{1}, %{2}或%{name} -->
<!--   - Toast/Alert等提示必须翻译 -->
<!--   - 按钮、标签、说明文字必须翻译 -->
<!-- Examples: -->
<!--   - ✅ showToast(__('账户添加成功')) -->
<!--   - ✅ <button><?= __('立即启用') ?></button> -->
<!--   - ✅ __('共有 %{1} 个账户', count) -->
<!--   - ❌ showToast('账户添加成功')  // 硬编码 -->
<!-- Templates requiring updates: ⚠ 需要更新TwoFactorAuth模块的PWA应用（JS部分） -->
<!-- Follow-up TODOs: -->
<!--   - TODO(I18N_TWOFA): 更新TwoFactorAuth/view/statics/twofa-app/app.js中所有硬编码文本 -->
<!--   - TODO(I18N_HTML): 更新TwoFactorAuth/view/statics/twofa-app/index.html中所有文本 -->
<!--   - TODO(I18N_BACKEND): 确保所有Controller和Service中的提示已翻译 -->
<!-- Previous Sync Impact Report -->
<!-- Version change: 2.15.0 → 2.15.1 (PATCH) -->
<!-- Enhanced principle: XXIII. 问题修复文档化要求 (Issue Fix Documentation Requirements) -->
<!-- Reason: 用户强调必须持续学习，修复功能后立即记录到开发文档，避免重复错误 -->
<!-- Date: 2025-10-26 -->
<!-- Changes: -->
<!--   - 强化"立即记录"要求，从建议改为强制 -->
<!--   - 新增"持续学习"机制要求 -->
<!--   - 明确记录时必须包含"防止重复"指南 -->
<!--   - 新增违规后果：连续遗漏记录将触发强制审查 -->
<!-- Previous Sync Impact Report -->
<!-- Version change: 2.14.5 → 2.15.0 (MINOR) -->
<!-- Added principle: XXV. 实事求是与验证要求 (Fact-Based Verification Requirements) -->
<!-- Reason: 用户反馈AI助手未验证修复效果就声称问题已解决，违背实事求是原则 -->
<!-- Date: 2025-10-26 -->
<!-- Rationale: -->
<!--   1. AI助手必须严格区分"代码修改"和"问题解决" -->
<!--   2. 未经验证不得声称问题已修复 -->
<!--   3. 遇到无法验证的情况必须明确告知而非回避 -->
<!--   4. 承认失败比伪装成功更重要 -->
<!--   5. 实事求是是建立信任的基础 -->
<!-- Key requirements: -->
<!--   - 修改代码后必须验证实际效果 -->
<!--   - 明确说明方案的适用范围和限制 -->
<!--   - 只有在问题真正解决后才能更新文档 -->
<!--   - 遇到框架或环境限制必须如实告知 -->
<!-- Typical violation example (from this session): -->
<!--   - 修复了Windows文件复制代码后，未验证生产环境实际效果 -->
<!--   - 忽略PHP内置服务器不支持prod模式的限制 -->
<!--   - 在问题未真正解决时就更新了开发文档 -->
<!-- Templates requiring updates: ✅ 无需更新模板（新增验证要求原则） -->
<!-- Previous Follow-up TODOs: -->
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

#### 服务器管理效率原则 - NON-NEGOTIABLE

**服务器启动耗时，禁止在开发过程中频繁重启服务器，只在必要时重启**：

- **MUST**: 服务器启动后应保持运行，直到以下必要情况才重启：
  1. 修改了 `etc/` 目录下的配置文件
  2. 修改了路由配置（但可通过 `setup:upgrade` 热更新）
  3. 修改了 `register.php` 模块注册文件
  4. 框架核心文件发生变更
  5. 明确的服务器错误需要重启恢复
- **MUST NOT**: ❌ 禁止在以下情况下重启服务器：
  - 修改Controller代码（自动热重载）
  - 修改Model或Service代码（自动热重载）
  - 修改模板文件（自动热重载）
  - 修改静态资源文件
  - 执行 `setup:upgrade` 后（已热更新路由）
  - 执行 `cache:clear` 后（缓存已清理）
- **MUST**: 使用 `php bin/w server:status` 确认服务器运行状态
- **MUST**: 使用 `php bin/w server:stop` 和 `php bin/w server:start` 时应有明确理由
- **SHOULD**: 在开发会话开始时启动一次服务器，会话结束时停止
- **SHOULD**: 使用 `setup:upgrade` 和 `cache:clear` 而非重启服务器来应用更改

**正确的开发工作流**：
```bash
# 1. 会话开始：启动服务器（一次）
php bin/w server:start

# 2. 开发过程：修改代码（无需重启）
# - 修改Controller、Model、Service
# - 修改模板文件
# - 修改静态资源

# 3. 路由变更：升级模块（无需重启）
php bin/w setup:upgrade -m Weline_Ai

# 4. 清理缓存（无需重启）
php bin/w cache:clear -f

# 5. 测试功能
php bin/w http:request "ai/frontend/index"

# 6. 继续开发...（无需重启）

# 7. 会话结束：停止服务器
php bin/w server:stop
```

**错误的工作流（严禁）**：
```bash
# ❌ 错误：每次修改后都重启
php bin/w server:stop
php bin/w server:start
# 修改代码...
php bin/w server:stop  # ❌ 不必要的重启
php bin/w server:start
# setup:upgrade...
php bin/w server:stop  # ❌ 不必要的重启
php bin/w server:start
```

**Rationale**：
1. **效率**：服务器启动通常需要5-10秒，频繁重启浪费大量时间
2. **稳定性**：WelineFramework支持代码热重载，大多数修改无需重启
3. **资源**：减少不必要的进程创建和销毁，降低系统负载
4. **开发体验**：连续的服务器运行提供更流畅的开发体验
5. **CI/CD友好**：养成正确的服务器管理习惯有助于生产环境部署

**例外情况**：
- 如果遇到服务器hang或无响应，必须重启
- 如果热重载失败（极少见），可以尝试重启
- 如果怀疑缓存问题导致异常，先清理缓存，仍未解决再重启

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

### XVIII. 测试文件位置规范 (Test File Location Standards - NON-NEGOTIABLE)

**禁止在项目根目录创建测试文件，所有测试文件必须在模块内的test目录下创建**：

- **MUST**: 禁止在项目根目录（workspace root）创建任何测试文件
- **MUST**: 所有测试文件必须创建在当前开发模块的 `Test/` 目录内
- **MUST**: 单元测试必须放在 `<Module>/Test/Unit/` 目录下
- **MUST**: 集成测试必须放在 `<Module>/Test/Integration/` 目录下
- **MUST**: 临时调试脚本必须放在 `<Module>/Test/Debug/` 目录下（测试完成后删除）
- **MUST**: HTTP测试脚本必须放在 `<Module>/Test/Http/` 目录下
- **MUST**: 浏览器测试必须放在 `<Module>/Test/Browser/` 目录下
- **MUST NOT**: ❌ 严禁在根目录创建 test_*.php、debug_*.php、check_*.php 等测试文件
- **MUST NOT**: ❌ 严禁在根目录创建 *.txt、*.log、*.json 等测试输出文件
- **MUST NOT**: ❌ 严禁将根目录的临时测试文件提交到版本控制系统

**正确的测试文件位置**：
```
app/code/Weline/Ai/
├── Test/
│   ├── Unit/              # 单元测试（PHPUnit）
│   ├── Integration/       # 集成测试
│   ├── Http/              # HTTP请求测试
│   ├── Browser/           # 浏览器自动化测试
│   └── Debug/             # 临时调试脚本（测试完成后删除）
```

**错误的测试文件位置（严禁）**：
```
E:\WelineFramework\DEV-workspace\     # ❌ 根目录
├── test_ai_module.php                # ❌ 禁止
├── check_ai_tables.php               # ❌ 禁止
├── debug_something.php               # ❌ 禁止
└── test_output.txt                   # ❌ 禁止
```

**临时调试脚本管理**：
- **MUST**: 临时调试脚本必须放在 `Test/Debug/` 目录
- **MUST**: 文件名必须包含日期和用途（如 `debug_model_query_20251025.php`）
- **MUST**: 脚本顶部必须注释说明用途、作者、日期
- **MUST**: 测试完成后必须立即删除或移动到 `.gitignore`
- **SHOULD**: 使用框架命令（如 `php bin/w http:request`）替代临时脚本

**正确的测试方式**：
```bash
# ✅ 使用框架命令（无需创建文件）
php bin/w http:request GET /rest/v1/model/1

# ✅ 使用模块内的单元测试
php bin/w phpunit:run -b --path=app/code/Weline/Ai/Test/Unit

# ✅ 创建临时调试脚本（在模块Test目录内）
# 文件：app/code/Weline/Ai/Test/Debug/debug_query_20251025.php
```

**错误的测试方式（严禁）**：
```bash
# ❌ 在根目录创建测试文件
php -r "..." > test_output.txt

# ❌ 在根目录创建PHP测试脚本
echo '<?php ...' > E:\WelineFramework\DEV-workspace\test_something.php
```

**Rationale**: 
1. **组织性**：测试文件集中在模块目录，便于管理和查找
2. **隔离性**：每个模块的测试文件独立，避免混乱
3. **可维护性**：测试文件与源码在一起，便于同步更新
4. **清洁性**：避免根目录被测试文件污染
5. **版本控制**：模块内的测试文件可以纳入版本控制，根目录的临时文件不应提交
6. **团队协作**：统一的位置规范便于团队协作

**AI助手特别要求 - CRITICAL**：
- **MUST**: AI助手在创建任何测试文件前，必须先确定目标模块
- **MUST**: AI助手必须将测试文件创建在 `app/code/<Vendor>/<Module>/Test/` 目录下
- **MUST**: AI助手禁止在根目录创建任何测试相关的文件
- **MUST**: 如果发现根目录有测试文件，必须立即移动到对应模块的Test目录或删除
- **VIOLATION CONSEQUENCE**: 如用户发现AI在根目录创建了测试文件，AI助手必须立即道歉、删除文件并重新在正确位置创建

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

**持续学习机制：每次修复问题后必须立即记录到开发文档，避免重复错误，这是强制性要求**：

#### A. 立即记录原则 (Immediate Documentation)

- **MUST**: AI 助手每次修复完一个问题后，必须**立即**将修复方案记录到相关开发文档，不得延后
- **MUST**: 记录内容必须包括：
  1. **问题描述**：清晰描述出现的问题和错误现象
  2. **根本原因**：分析为什么会出现这个问题
  3. **修复方案**：完整的修复代码和步骤
  4. **验证方法**：如何验证修复是否有效
  5. **防止重复指南**：如何避免再次犯同样的错误（✅ 新增要求）
- **MUST**: 记录位置必须是项目的正式开发文档：
  - 框架层面问题 → `constitution.md` 的相应原则
  - 模块特定问题 → 模块的 `README.md` 或 `docs/` 目录
  - 通用问题 → `docs/common-issues.md` 或相应的专题文档
- **MUST**: 记录必须包含完整的错误信息、修复前后代码对比、以及可执行的验证命令
- **MUST NOT**: ❌ **禁止修复问题后不记录就继续下一个任务**（零容忍）
- **MUST NOT**: ❌ 禁止仅在对话中说明修复方案而不写入文档
- **MUST NOT**: ❌ 禁止以"时间紧"、"问题简单"等理由跳过记录步骤

#### B. 持续学习机制 (Continuous Learning)

- **MUST**: 每次记录问题时，必须回顾是否有类似问题已在文档中存在
- **MUST**: 如果发现重复问题，必须分析为什么之前的文档未能防止此问题
- **MUST**: 修复重复问题后，必须同时更新相关文档，使其更加明确和可操作
- **MUST**: 在会话结束前，必须回顾本次修复的所有问题，确认全部已记录
- **MUST**: 建立问题模式库，对相似问题进行归类和总结
- **SHOULD**: 定期（每月）回顾文档，识别高频问题并加强相关原则
- **SHOULD**: 为常见问题创建快速参考指南（Quick Reference）

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

#### C. 记录时机与流程 (Documentation Timing)

1. **立即记录（默认）**：修复完成并验证通过后立即记录，不得延后
2. **批量记录（例外）**：如果同一类问题修复了多个，可以在完成后统一记录，但必须在任务结束前完成
3. **回顾记录（必须）**：在上下文切换或会话结束前，必须回顾并补充遗漏的文档
4. **验证记录（新增）**：记录完成后，必须重新阅读文档，确保清晰、完整、可执行

#### D. AI 助手行为要求 (AI Assistant Behavior)

- **MUST**: 修复问题后，不等用户要求，**直接**提供文档更新（文件路径 + 完整内容）
- **MUST**: 使用工具直接写入文档，而非仅提供"建议记录"的提示
- **MUST**: 在会话结束前，主动列出本次修复的所有问题及其文档位置
- **MUST**: 如果用户反对记录，必须在对话中明确警告重复风险
- **SHOULD**: 在修复完成的响应中，同时包含"修复代码"和"文档更新"两部分
- **SHOULD**: 主动建议文档位置和章节结构

#### E. 违规后果 (Consequences)

- **WARNING**: 发现未记录的修复，必须立即补充记录
- **CRITICAL**: 连续3次遗漏记录，必须暂停当前任务并强制审查所有文档
- **REVIEW**: 发现重复问题（已有文档但再次出现），必须分析文档不足之处并改进
- **ESCALATE**: 如果重复问题超过2次，必须提升到宪章级别，制定更严格的预防措施

#### F. 文档质量标准 (Documentation Quality)

- **MUST**: 文档必须包含可复制粘贴的代码示例（错误代码 vs 正确代码）
- **MUST**: 文档必须包含可执行的验证命令（如何确认修复有效）
- **MUST**: 文档必须包含"为什么"（Why）的解释，而非仅仅"怎么做"（How）
- **MUST**: 文档必须使用清晰的标题和分类（如"常见错误"、"ORM问题"、"依赖注入"等）
- **SHOULD**: 文档应包含相关的宪章原则引用（如"违反了原则X.Y"）
- **SHOULD**: 为高频问题创建独立的专题文档

**Rationale（更新后）**：

1. **知识积累**：防止相同问题重复出现，积累团队知识库
2. **团队协作**：帮助其他开发者快速了解和解决类似问题
3. **新人培训**：为新成员提供实用的问题解决参考
4. **质量提升**：通过总结问题模式，改进代码质量和开发流程
5. **AI 学习**：为 AI 助手提供更准确的上下文，**避免重复犯错（核心目标）** ✅
6. **审计追踪**：记录问题和解决方案，便于代码审查和问题追溯
7. **效率提升**：完善的文档可以减少90%的重复问题处理时间 ✅

文档化不仅是记录，更是对问题的深度思考和系统化总结。每次修复都是学习机会，必须转化为可复用的知识。**持续学习的核心是：记录 → 回顾 → 改进 → 避免重复。** ✅

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

### XXV. 实事求是与验证要求 (Fact-Based Verification Requirements - NON-NEGOTIABLE)

**AI助手必须实事求是，严格区分"代码修改"和"问题解决"，未经验证不得声称问题已修复**：

#### A. 验证优先原则

- **MUST**: 修改代码后必须验证修复是否真正生效，不得仅凭代码修改就声称问题已解决
- **MUST**: 对于部署/环境相关问题，必须验证实际运行效果，而非假设修复有效
- **MUST**: 遇到无法验证的情况，必须明确告知用户"代码已修改但需要您验证效果"
- **MUST**: 区分"理论上应该可以"和"已验证可以"，不得混淆两者
- **MUST**: 如果存在验证障碍（如环境限制、权限不足等），必须明确说明而非回避
- **MUST NOT**: ❌ 禁止在未验证的情况下断言"问题已解决"、"修复完成"
- **MUST NOT**: ❌ 禁止忽略框架或环境的限制条件，假装问题已解决
- **MUST NOT**: ❌ 禁止在问题未真正解决时就开始写文档或总结

#### B. 诚实沟通要求

- **MUST**: 发现问题无法完全解决时，必须立即告知用户，而非继续伪装成功
- **MUST**: 明确区分"修复了X代码问题"和"解决了Y实际问题"
- **MUST**: 如果修复方案有前提条件或限制，必须明确说明
- **MUST**: 遇到框架设计限制时，必须如实告知而非强行绕过
- **MUST**: 提供解决方案时必须说明方案的适用范围和已知限制
- **MUST NOT**: ❌ 禁止用技术术语掩盖问题未解决的事实
- **MUST NOT**: ❌ 禁止过度自信，把部分修复当作完全解决
- **MUST NOT**: ❌ 禁止回避用户的质疑，必须正面回应

#### C. 文档更新时机

- **MUST**: 只有在问题真正解决并验证有效后，才能更新文档
- **MUST**: 文档中必须注明"已验证"或"理论方案（待验证）"
- **MUST**: 如果是workaround而非根本解决，必须在文档中明确说明
- **MUST**: 更新开发文档前，必须确认解决方案在实际场景中可用
- **MUST NOT**: ❌ 禁止在问题未解决时就抢先写文档
- **MUST NOT**: ❌ 禁止在文档中声称已解决但实际未验证的问题

#### D. 失败处理原则

- **MUST**: 承认失败比伪装成功更重要
- **MUST**: 修复失败时必须分析原因，明确下一步方案
- **MUST**: 坦诚告知用户当前方案的局限性
- **MUST**: 提供替代方案或建议用户采取的行动
- **MUST**: 记录失败原因到constitution或开发文档，避免重复错误
- **SHOULD**: 主动建议更可行的解决路径
- **SHOULD**: 在无法解决时，提供足够的信息帮助用户自行解决

**Rationale**:

1. **建立信任**：实事求是比虚假成功更能建立长期信任关系
2. **避免误导**：未验证的"修复"可能让用户浪费更多时间
3. **提高效率**：及时承认局限性可以让用户快速转向可行方案
4. **质量保证**：验证要求确保真正解决问题，而非制造新问题
5. **专业态度**：承认不足是专业性的体现，而非软弱
6. **学习机会**：正视失败才能真正学习和改进

**典型反例**：
- ❌ 修复了Windows文件复制代码，就声称"生产环境静态资源问题已解决"
- ❌ 忽略PHP内置服务器不支持prod模式的限制，继续假装问题不存在
- ❌ 在用户反馈"静态资源还是404"后，还在强调"代码已经修复"

**正确做法**：
- ✅ "我修复了Windows文件复制问题，但发现PHP内置服务器不支持生产模式，需要用Nginx验证"
- ✅ "代码层面已修复，但由于X限制无法验证，建议您采用Y方案测试"
- ✅ "抱歉，之前判断有误，实际问题是Z，现在提供新的解决方案"

### XXVI. 国际化翻译强制要求 (i18n Translation Requirements - MANDATORY)

**所有用户可见的文本内容必须使用框架的翻译函数进行国际化处理，不得硬编码文本**：

#### A. PHP后端翻译要求 (PHP Backend Translation)

- **MUST**: 控制器中的所有消息、错误提示、成功提示必须使用`__()`函数
- **MUST**: Service层的业务逻辑消息必须翻译
- **MUST**: 模板文件(.phtml)中的所有文本内容必须使用`<?= __('文本') ?>`
- **MUST**: 异常消息和错误提示必须翻译

**PHP翻译函数用法**：
```php
// 简单翻译
__('账户添加成功')

// 单个参数
__('欢迎 %{1}', $username)

// 多个参数（数组索引）
__('用户 %{1} 有 %{2} 条消息', [$username, $count])

// 命名参数（推荐）
__('用户 %{name} 有 %{count} 条消息', ['name' => $username, 'count' => $count])
```

**控制器示例**：
```php
// ✅ 正确
return $this->success(['message' => __('设置保存成功')]);
return $this->error(__('密钥格式无效'));
throw new Exception(__('账户不存在'));

// ❌ 错误
return $this->success(['message' => '设置保存成功']);  // 硬编码
return $this->error('密钥格式无效');  // 硬编码
```

**模板示例**：
```php
<!-- ✅ 正确 -->
<button><?= __('立即启用') ?></button>
<h1><?= __('双因素认证设置') ?></h1>
<p><?= __('共有 %{1} 个账户', $count) ?></p>

<!-- ❌ 错误 -->
<button>立即启用</button>  <!-- 硬编码 -->
<h1>双因素认证设置</h1>  <!-- 硬编码 -->
```

#### B. JavaScript前端翻译要求 (JavaScript Frontend Translation)

- **MUST**: PWA应用、前端脚本的所有文本必须使用翻译函数
- **MUST**: Toast提示、Alert对话框、确认消息必须翻译
- **MUST**: 按钮文字、标签文本、占位符必须翻译
- **MUST**: 错误消息和状态提示必须翻译

**JavaScript翻译函数用法**：
```javascript
// 方式1：使用__()函数（框架已全局定义）
__('账户添加成功')

// 方式2：使用phrase()函数
phrase('账户添加成功', arguments)

// 带参数
__('共有 %{1} 个账户', count)
__('欢迎 %{name}', {name: username})
```

**JavaScript示例**：
```javascript
// ✅ 正确
showToast(__('✓ 账户添加成功'));
if (confirm(__('确定要删除这个账户吗？'))) { ... }
field.placeholder = __('输入发行者名称');
showToast(__('✓ 已导出 %{1} 个账户', count));

// ❌ 错误
showToast('✓ 账户添加成功');  // 硬编码
if (confirm('确定要删除这个账户吗？')) { ... }  // 硬编码
field.placeholder = '输入发行者名称';  // 硬编码
```

#### C. HTML静态文件翻译要求 (HTML Static Files Translation)

- **MUST**: 独立HTML文件（如PWA应用）的文本必须支持翻译
- **MUST**: 页面加载时从`window.site.i18n`对象获取翻译
- **MUST**: 提供默认语言作为fallback
- **SHOULD**: 使用data属性标记需要翻译的元素

**HTML翻译模式**：
```javascript
// 页面加载时翻译所有标记的元素
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        el.textContent = __(key);
    });
});
```

**HTML示例**：
```html
<!-- ✅ 正确：使用data-i18n属性 -->
<h1 data-i18n="app_title">Weline 验证器</h1>
<button data-i18n="btn_add">添加</button>

<!-- ✅ 正确：JavaScript动态设置 -->
<script>
    document.title = __('Weline 身份验证器');
</script>

<!-- ❌ 错误：硬编码 -->
<h1>Weline 验证器</h1>
<button>添加</button>
```

#### D. 特殊场景翻译要求 (Special Cases)

- **MUST**: 动态生成的DOM内容必须翻译
- **MUST**: console.log调试信息可以不翻译，但用户可见的错误必须翻译
- **MUST**: 代码注释可以不翻译，但文档注释（PHPDoc）建议翻译
- **MUST**: 配置文件的description、label等用户可见字段必须翻译

**特殊场景示例**：
```javascript
// ✅ 动态DOM
html += `<div class="message">${__('加载中...')}</div>`;

// ✅ Toast通知
showToast(__('✓ 扫描成功！信息已填充，可以修改'), 5000);

// ✅ 占位符动态设置
field.placeholder = __('👈 可以在这里添加备注');

// ⚠️ 调试信息可不翻译
console.log('QR Code Data:', data);

// ✅ 但用户可见错误必须翻译
console.error(__('无法解析二维码'));
```

#### E. 翻译文件组织 (Translation File Organization)

- **MUST**: 翻译文件位于 `i18n/` 目录
- **MUST**: 语言代码遵循ISO 639-1标准
- **MUST**: 使用模块化的翻译键名（避免冲突）
- **SHOULD**: 按功能模块组织翻译键

**翻译文件结构**：
```
app/code/Weline/TwoFactorAuth/i18n/
├── zh_CN.php  # 简体中文
├── zh_TW.php  # 繁体中文
├── en_US.php  # 美国英语
└── ja_JP.php  # 日语
```

**翻译文件内容**：
```php
<?php
return [
    // 通用
    '添加' => 'Add',
    '删除' => 'Delete',
    '取消' => 'Cancel',
    
    // Toast提示
    '✓ 账户添加成功' => '✓ Account added successfully',
    '✓ 扫描成功！信息已填充，可以修改' => '✓ Scan successful! Info filled, you can edit',
    
    // 错误消息
    '请输入密钥或链接' => 'Please enter secret or link',
    '无效的密钥格式' => 'Invalid secret format',
    
    // 带参数的翻译
    '✓ 已导出 %{1} 个账户' => '✓ Exported %{1} accounts',
    '共有 %{count} 个账户' => 'Total %{count} accounts',
];
```

#### F. 违规示例与修复 (Violations and Fixes)

**常见违规**：
```javascript
// ❌ 错误1：硬编码Toast
showToast('✓ 账户添加成功');

// ✅ 修复1
showToast(__('✓ 账户添加成功'));

// ❌ 错误2：硬编码字段高亮提示
field.placeholder = '👈 可以在这里添加备注';

// ✅ 修复2
field.placeholder = __('👈 可以在这里添加备注');

// ❌ 错误3：硬编码HTML
html += `<div>还没有账户</div>`;

// ✅ 修复3
html += `<div>${__('还没有账户')}</div>`;

// ❌ 错误4：硬编码模态框标题
<h2>添加账户</h2>

// ✅ 修复4
<h2 data-i18n="modal_add_title">添加账户</h2>
// 或在PHP模板中
<h2><?= __('添加账户') ?></h2>
```

#### G. 验证与检查 (Validation and Checking)

- **MUST**: 代码审查时检查所有文本是否已翻译
- **MUST**: 新功能开发时同步创建翻译文件
- **SHOULD**: 使用工具扫描未翻译的硬编码文本
- **SHOULD**: 定期审核翻译覆盖率

**检查清单**：
```bash
# 查找可能的硬编码中文文本
grep -r "[\u4e00-\u9fff]" app/code/*/view/statics/ --include="*.js"

# 查找可能的硬编码Toast
grep -r "showToast('[^_]" app/code/ --include="*.js"

# 查找可能的硬编码Alert
grep -r "alert('[^_]" app/code/ --include="*.js"
```

#### H. 框架翻译机制说明 (Framework Translation Mechanism)

**翻译函数定义**：
- 位置：`app/code/Weline/Framework/Common/functions.php:98`
- 解析器：`Weline\Framework\Phrase\Parser::parse()`
- JavaScript：前端自动注入`__()`函数到全局作用域

**工作原理**：
1. 调用`__('文本', $args)`
2. 解析器查找当前语言的翻译文件
3. 未找到翻译时返回原文本
4. 自动收集未翻译词条到集合文件
5. 替换参数占位符（%{1}, %{name}等）

**自动收集机制**：
```php
// 未翻译的词条会被自动收集到
var/translate/all_words_collections.php

// 开发者可基于此生成完整翻译文件
```

**Rationale**:

1. **国际化是现代应用的基本要求**：框架已提供完整i18n支持，不使用是浪费
2. **用户体验**：多语言支持扩大用户群体，提升国际化竞争力
3. **代码质量**：统一使用翻译函数，代码更规范和专业
4. **维护性**：集中管理翻译，修改文案无需改代码
5. **框架一致性**：框架其他模块都使用翻译函数，新模块必须一致
6. **零成本实现**：翻译函数使用简单，开发成本几乎为零
7. **向后兼容**：未提供翻译时自动显示原文，不影响功能

**典型违规示例（来自本次会话）**：
```javascript
// TwoFactorAuth模块的PWA应用
❌ showToast('✓ 账户添加成功');
❌ showToast('请输入密钥或链接');
❌ field.placeholder = '输入发行者名称';
❌ <h2>添加账户</h2>
❌ <p>点击下方"+"按钮添加第一个账户</p>
```

**正确做法**：
```javascript
✅ showToast(__('✓ 账户添加成功'));
✅ showToast(__('请输入密钥或链接'));
✅ field.placeholder = __('输入发行者名称');
✅ <h2><?= __('添加账户') ?></h2>
✅ <p><?= __('点击下方"+"按钮添加第一个账户') ?></p>
```

**参考文档**：
- 开发文档：`开发文档.md` 第2507-2646行
- PageBuilder示例：`app/code/GuoLaiRen/PageBuilder/doc/i18n-translation-guide.md`
- I18n模块文档：`app/code/Weline/I18n/doc/README.md`
- 函数源码：`app/code/Weline/Framework/Common/functions.php:84-101`

### XXVII. 禁止未完成就总结 (No Premature Summarization - CRITICAL)

**AI助手禁止在功能未完成、测试未通过、问题未解决时输出总结性文字，必须保持工作连续性直到任务真正完成**：

#### A. 总结时机限制 (Summarization Timing Constraints)

- **MUST**: 只有在所有功能完整实现并验证通过后，才能输出总结
- **MUST**: 只有在所有TODO事项完成后，才能提供完成总结
- **MUST**: 只有在所有测试通过、问题修复并验证后，才能进行总结性陈述
- **MUST**: 总结必须放在响应的最后，而非中途穿插
- **MUST**: 用户明确要求总结时除外，但必须注明"部分完成总结"或"进度总结"
- **MUST NOT**: ❌ **禁止在功能开发到一半时就开始总结已完成的部分**
- **MUST NOT**: ❌ **禁止在遇到错误需要修复时就总结之前的工作**
- **MUST NOT**: ❌ **禁止用总结代替实际工作（如"已完成X，接下来将Y"实际却未做Y）**
- **MUST NOT**: ❌ **禁止在浏览器缓存等问题未解决时就总结功能已完成**
- **MUST NOT**: ❌ **禁止在部署未验证时就总结部署成功**

#### B. 连续工作原则 (Continuous Work Principle)

- **MUST**: 保持工作流的连续性，一气呵成完成任务
- **MUST**: 发现问题立即修复，而非总结已完成部分后再修复
- **MUST**: 所有相关子任务必须完成后再进行下一个主任务
- **MUST**: 测试失败必须立即修复，而非先总结成功的部分
- **MUST**: 代码修改必须立即部署和验证，而非先总结修改内容
- **MUST NOT**: ❌ 禁止"阶段性总结"打断工作流
- **MUST NOT**: ❌ 禁止用总结来拖延实际工作
- **MUST NOT**: ❌ 禁止在等待用户反馈前就总结当前进度

#### C. 总结内容质量要求 (Summary Quality Requirements)

当确实需要总结时（任务完成或用户要求），必须：

- **MUST**: 总结必须基于实际验证结果，而非代码修改
- **MUST**: 明确区分"已完成并验证"和"已修改待验证"
- **MUST**: 列出所有完成的功能点及其验证方法
- **MUST**: 包含下一步建议（如果有未完成的工作）
- **MUST**: 诚实说明任何已知限制或待解决问题
- **MUST NOT**: ❌ 禁止夸大成果或隐瞒问题
- **MUST NOT**: ❌ 禁止将"修改了代码"等同于"完成了功能"

#### D. 典型违规场景 (Typical Violations)

**场景1：功能开发中总结**
```markdown
❌ 错误做法：
"完成✅！让我为您提供完整总结：
1. ✅ 创建了XXX文件
2. ✅ 更新了YYY配置
3. ✅ 部署了ZZZ资源

所有功能已完成开发并通过测试！"

实际情况：浏览器缓存问题未解决，功能未验证，测试未通过

✅ 正确做法：
"已修改了XXX、YYY、ZZZ文件。
现在部署并验证功能..."
（继续工作，验证通过后再总结）
```

**场景2：遇到错误后总结**
```markdown
❌ 错误做法：
"之前已完成：
- ✅ 添加账户功能
- ✅ 倒计时刷新
现在发现倒计时不工作，让我修复..."

✅ 正确做法：
"发现倒计时未正确刷新，立即修复..."
（修复并验证通过后，在最后统一总结所有完成的功能）
```

**场景3：部署未验证就总结**
```markdown
❌ 错误做法：
"文件已部署到pub/static/目录
✅ 部署完成！功能已上线！"

实际情况：Service Worker缓存未清除，浏览器加载的是旧版本

✅ 正确做法：
"文件已部署，现在清除缓存并验证..."
（验证通过后再确认部署成功）
```

#### E. AI助手行为要求 (AI Assistant Behavior)

- **MUST**: 专注于解决问题和完成功能，而非总结进度
- **MUST**: 保持工作的持续性和专注性
- **MUST**: 只在真正完成任务后才输出总结
- **MUST**: 总结时必须包含验证结果，而非仅列举代码修改
- **MUST**: 如果有未完成的工作，必须继续完成而非总结已完成部分
- **MUST NOT**: ❌ 禁止为了看起来有成果而提前总结
- **MUST NOT**: ❌ 禁止用总结来掩盖问题未解决的事实
- **MUST NOT**: ❌ 禁止频繁输出"已完成XXX"的阶段性总结

#### F. 进度报告 vs 完成总结 (Progress Report vs Completion Summary)

**进度报告**（允许，但应简洁）：
```markdown
✅ 可接受的进度说明（简短、事实性）：
"已创建XXX文件，现在部署..."
"修复了YYY问题，继续测试ZZZ..."
"完成了功能A，开始功能B..."
```

**完成总结**（仅在任务完成后）：
```markdown
✅ 任务完成后的总结（详细、经过验证）：
"## 完成总结

所有功能已完成开发、部署并验证通过：

1. ✅ 功能A - 已验证工作正常
2. ✅ 功能B - 测试通过
3. ✅ 功能C - 部署成功，浏览器验证通过

### 验证方法：
- 测试1：... (通过✅)
- 测试2：... (通过✅)

### 已创建文档：
- docs/XXX.md
- docs/YYY.md"
```

#### G. 违规后果 (Consequences)

- **WARNING**: 第一次未完成就总结 → 立即停止总结，继续工作
- **CRITICAL**: 第二次违规 → 必须明确说明为何总结，并继续完成工作
- **SEVERE**: 第三次违规 → 必须暂停当前任务，回顾所有未完成工作
- **REVIEW**: 持续违规 → 必须检讨工作方式和时间管理

#### H. 检查清单 (Pre-Summary Checklist)

在输出总结前，必须确认：

- [ ] 所有功能点都已实现
- [ ] 所有代码修改都已部署
- [ ] 所有测试都已通过
- [ ] 所有问题都已修复并验证
- [ ] 浏览器缓存已清除且功能验证通过
- [ ] TODO列表已清空或明确标注未完成项
- [ ] 所有文档都已更新
- [ ] 用户反馈的所有问题都已解决
- [ ] 没有已知的遗留问题或限制（如有必须明确说明）

**Rationale**:

1. **用户体验**：未完成就总结会让用户误以为工作已完成，造成认知混乱
2. **工作连续性**：频繁总结打断工作流，降低效率和专注度
3. **质量保证**：只有验证通过才能总结，确保总结内容的准确性
4. **避免误导**：防止将"修改了代码"误导为"完成了功能"
5. **责任明确**：总结意味着交付，必须确保交付物真正可用
6. **专业态度**：保持工作专注直到完成，体现专业性和责任心
7. **效率优先**：减少不必要的总结，把时间用在实际工作上

**典型违规示例（来自本次会话）**：
```markdown
❌ 在添加复制图标和倒计时修复后，立即输出大量总结：
"🎉 完美！所有功能验证成功！
已验证的功能：
1. ✅ 倒计时刷新
2. ✅ 复制图标
...（大量总结内容）
建议在实际设备上测试..."

实际情况：浏览器Service Worker缓存未清除，功能未真正验证
```

**正确做法**：
```markdown
✅ 修复了倒计时刷新和添加了复制图标，已部署文件。
清除Service Worker缓存并验证...
（继续验证工作，全部通过后再总结）
```

#### I. 文档整合要求 (Documentation Consolidation - CRITICAL)

**禁止为每个小功能创建独立的总结文档，必须将相关内容整合到统一的文档中**：

- **MUST**: 同一模块的所有更新、修复、说明必须集中到一个主文档中（如`CHANGELOG.md`或`DEVELOPMENT_NOTES.md`）
- **MUST**: 禁止为每个功能创建单独的"XXX完成说明.md"、"XXX实现说明.md"等文档
- **MUST**: 使用文档内的章节（##、###标题）组织不同功能，而非创建多个文件
- **MUST**: 技术细节、使用示例、API说明应写在模块的README.md或docs/目录下的专题文档中
- **MUST**: 问题修复记录应统一写入`docs/DEVELOPMENT_NOTES.md`或`docs/问题修复记录.md`
- **MUST NOT**: ❌ 禁止创建如"问题修复-功能A.md"、"功能B完成说明.md"、"功能C实现文档.md"等碎片化文档
- **MUST NOT**: ❌ 禁止为每次小改动都创建一个新的说明文档
- **MUST NOT**: ❌ 禁止文档命名重复或混乱（如同时存在"升级说明"、"完成说明"、"实现说明"等）
- **SHOULD**: 每个模块应有以下标准文档结构：
  - `README.md` - 模块概述、安装、使用指南
  - `CHANGELOG.md` - 版本更新历史（按版本组织）
  - `docs/DEVELOPMENT_NOTES.md` - 开发笔记、问题修复记录
  - `docs/API.md` - API文档（如有）
  - `docs/GUIDE.md` - 详细使用指南（如有）

**文档组织示例（正确）**：
```
app/code/Weline/TwoFactorAuth/
├── README.md                    # 模块概述和快速开始
├── CHANGELOG.md                 # 所有版本更新历史
├── docs/
│   ├── DEVELOPMENT_NOTES.md   # 所有开发笔记和问题修复
│   ├── API.md                 # API文档
│   └── USAGE_GUIDE.md         # 详细使用指南
```

**文档组织示例（错误 - 禁止）**：
```
❌ app/code/Weline/TwoFactorAuth/
├── README.md
├── CHANGELOG.md
├── 登录保护机制.md              # ❌ 应该在DEVELOPMENT_NOTES.md中
├── 问题修复-倒计时刷新.md        # ❌ 应该在DEVELOPMENT_NOTES.md中
├── 测试清除缓存说明.md          # ❌ 应该在USAGE_GUIDE.md中
├── i18n重构说明.md             # ❌ 应该在DEVELOPMENT_NOTES.md中
├── i18n国际化使用说明.md        # ❌ 可以保留但应重命名为docs/i18n.md
├── v2.1.0-快速开始.md          # ❌ 应该在README.md或CHANGELOG.md中
├── v2.1.0-功能说明.md          # ❌ 应该在CHANGELOG.md中
├── v2.2.0-新功能说明.md        # ❌ 应该在CHANGELOG.md中
├── v2.1.0-更新总结.md          # ❌ 应该在CHANGELOG.md中
└── 内联SVG头像完成说明.md      # ❌ Frontend模块的，不应在这里
```

**CHANGELOG.md内部组织**（正确）：
```markdown
# 更新日志

## [v2.3.0] - 2025-10-26

### 🌍 i18n重构
- 移除JavaScript硬编码翻译字典
- 创建标准i18n/zh_CN.csv和en_US.csv
- 使用框架的__()函数

### 🔒 登录保护
- 实现三层登录保护机制
- URL参数验证
- JavaScript前端检查

## [v2.2.1] - 2025-10-26

### ⚡ 性能优化
- 修复倒计时刷新
- 添加复制图标

### 📝 功能增强
- 编辑确认框
- 恢复码功能
```

**DEVELOPMENT_NOTES.md内部组织**（正确）：
```markdown
# 开发笔记

## 问题修复记录

### 1. 倒计时刷新问题（2025-10-26）
**问题**：...
**修复**：...

### 2. 登录保护机制（2025-10-26）
**实现**：...
**验证**：...

## 技术要点

### i18n重构
- 从硬编码字典改为框架翻译系统
- CSV文件位置：i18n/
```

**Rationale**:

1. **减少文档碎片化**：过多的小文档难以查找和维护
2. **提升可维护性**：统一的文档更容易更新和同步
3. **降低认知负担**：开发者不需要在多个文档间跳转
4. **标准化结构**：所有模块遵循统一的文档组织方式
5. **版本控制友好**：减少文件数量，Git历史更清晰
6. **搜索效率**：在一个文档中搜索比在多个文档中查找更快

**违规示例（来自本次会话）**：
```
创建了过多的独立文档：
❌ 登录保护机制.md
❌ 问题修复-倒计时刷新.md
❌ 测试清除缓存说明.md
❌ i18n重构说明.md
❌ i18n国际化使用说明.md
❌ 内联SVG头像完成说明.md
❌ v2.2.0-新功能说明.md
❌ v2.1.0-快速开始.md
... 等等

应该整合为：
✅ CHANGELOG.md（版本更新记录）
✅ docs/DEVELOPMENT_NOTES.md（开发笔记和问题修复）
✅ docs/i18n.md（i18n使用指南）
✅ docs/TESTING.md（测试指南）
```

**整合后的文档清单**（每个模块）：
- `README.md` - 模块概述、安装、快速开始
- `CHANGELOG.md` - 所有版本更新（包括新功能、修复、优化）
- `docs/DEVELOPMENT_NOTES.md` - 开发笔记、问题修复、技术要点
- `docs/API.md` - API文档（如有API）
- `docs/USAGE_GUIDE.md` 或专题文档 - 详细使用指南

**最多5个核心文档，禁止超过10个文档文件**。

### XXVIII. 功能测试全覆盖要求 (Feature Test Coverage - MANDATORY)

**AI助手写的每一个功能都必须有完整的单元测试和自动化测试来保证按照预期执行，不得有任何例外**：

#### A. 测试全覆盖原则 (Complete Test Coverage)

- **MUST**: 每个新功能、新类、新方法都必须编写对应的单元测试
- **MUST**: 每个功能必须有自动化测试来验证是否按预期工作
- **MUST**: 测试必须覆盖正常流程、异常情况、边界条件、错误处理
- **MUST**: 功能实现完成后立即编写和运行测试，禁止推迟到"最后再测"
- **MUST**: 测试未通过的功能不得提交到代码库，必须先修复
- **MUST**: 所有测试必须可重复运行，结果必须稳定一致
- **MUST NOT**: ❌ **严禁"功能完成了但测试还没写"的情况**
- **MUST NOT**: ❌ **严禁以"功能简单"、"时间紧"等理由跳过测试**
- **MUST NOT**: ❌ **严禁手动测试代替自动化测试**
- **MUST NOT**: ❌ **严禁只测试主流程，忽略错误和边界情况**

#### B. 测试类型要求 (Test Types Requirements)

每个功能必须包含以下类型的测试（根据功能复杂度选择）：

1. **单元测试（Unit Tests）** - 必须
   - 测试单个方法/函数的逻辑
   - 使用Mock隔离外部依赖
   - 快速执行，无外部依赖
   - 位置：`<Module>/Test/Unit/`

2. **集成测试（Integration Tests）** - 重要功能必须
   - 测试多个组件协作
   - 测试数据库操作
   - 测试API调用
   - 位置：`<Module>/Test/Integration/`

3. **HTTP请求测试（HTTP Tests）** - 所有路由必须
   - 使用 `php bin/w http:request` 测试
   - 验证HTTP响应码和内容
   - 测试前后端路由
   - 可以写成脚本或测试文件

4. **浏览器测试（Browser Tests）** - 前端功能推荐
   - 测试UI交互
   - 测试JavaScript功能
   - 验证用户操作流程
   - 位置：`<Module>/Test/Browser/`

#### C. 测试覆盖率要求 (Coverage Requirements)

- **MUST**: 代码覆盖率必须达到**90%以上**
- **MUST**: 核心业务逻辑覆盖率必须达到**100%**
- **MUST**: 所有public方法必须有测试
- **MUST**: 所有Controller路由必须有单元测试和HTTP测试
- **MUST**: 使用 `php bin/w phpunit:run -b` 生成覆盖率报告
- **SHOULD**: 每个PR必须显示测试覆盖率数据
- **SHOULD**: CI/CD必须自动检查测试覆盖率，低于90%拒绝合并

#### D. 测试编写时机 (Test Writing Timing)

- **MUST**: 采用TDD方式：先写测试，再写实现
- **MUST**: 功能实现完成后立即运行测试验证
- **MUST**: 发现bug后立即编写复现测试用例
- **MUST**: 代码重构后立即运行测试确保行为不变
- **MUST NOT**: ❌ 禁止功能开发完成后才开始写测试
- **MUST NOT**: ❌ 禁止"先实现，测试以后再补"的做法
- **MUST NOT**: ❌ 禁止跳过测试直接提交代码

#### E. 测试质量要求 (Test Quality Standards)

- **MUST**: 测试名称必须清晰描述测试场景（如`testSaveWithValidData`）
- **MUST**: 测试必须包含明确的断言（assert）
- **MUST**: 每个测试只测试一个场景，避免复杂的多重测试
- **MUST**: 测试必须独立运行，不依赖其他测试的执行顺序
- **MUST**: 测试失败时必须提供清晰的错误信息
- **MUST**: 测试数据必须清理，避免污染数据库
- **SHOULD**: 使用测试夹具（fixtures）和数据工厂（factories）
- **SHOULD**: 测试代码质量与生产代码质量同等重要

#### F. 自动化测试示例 (Automated Test Examples)

**单元测试示例**：
```php
<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Service\DocumentScanner;
use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;

class DocumentScannerTest extends TestCase
{
    private DocumentScanner $scanner;
    private Document $documentMock;
    private Catalog $catalogMock;

    protected function setUp(): void
    {
        $this->documentMock = $this->createMock(Document::class);
        $this->catalogMock = $this->createMock(Catalog::class);
        $this->scanner = new DocumentScanner(
            $this->documentMock,
            $this->catalogMock
        );
    }

    public function testScanAllModulesSuccess(): void
    {
        // 测试成功扫描所有模块
        $result = $this->scanner->scanAllModules();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('scanned', $result);
        $this->assertArrayHasKey('new', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertArrayHasKey('modules', $result);
    }

    public function testScanModuleDocumentsWithValidPath(): void
    {
        // 测试扫描单个模块
        $result = $this->scanner->scanModuleDocuments(
            'Weline_Framework',
            '/path/to/module/doc'
        );
        
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(0, $result['scanned']);
    }

    public function testForceRescanDeletesOldDocuments(): void
    {
        // 测试强制重扫会删除旧文档
        $this->documentMock->expects($this->once())
            ->method('where')
            ->with(Document::fields_IS_AUTO_IMPORTED, 1)
            ->willReturnSelf();
        
        $this->documentMock->expects($this->once())
            ->method('delete');
        
        $this->scanner->scanAllModules(true);
    }
}
```

**HTTP测试脚本示例**：
```bash
#!/bin/bash
# Test/Http/test_document_api.sh

echo "=== 测试文档API ==="

# 1. 测试获取模块列表
echo "测试：获取模块列表"
php bin/w http:request GET /api/dev/document/modules

# 2. 测试搜索文档
echo "测试：搜索文档"
php bin/w http:request GET "/api/dev/document/search?keyword=API"

# 3. 测试按模块过滤
echo "测试：按模块过滤"
php bin/w http:request GET "/api/dev/document/search?module=Weline_Framework"

# 4. 测试获取文档详情
echo "测试：获取文档详情"
php bin/w http:request GET "/api/dev/document/detail?id=1"

# 5. 测试获取目录树
echo "测试：获取目录树"
php bin/w http:request GET /api/dev/document/catalogs

echo "=== 所有测试完成 ==="
```

#### G. 测试执行流程 (Test Execution Workflow)

**标准测试流程**：
```bash
# 1. 编写测试用例（TDD - 先写测试）
# 创建：app/code/Weline/YourModule/Test/Unit/YourClassTest.php

# 2. 运行测试（测试应该失败）
php bin/w phpunit:run -b --path=app/code/Weline/YourModule/Test/Unit

# 3. 实现功能代码
# 编辑：app/code/Weline/YourModule/Service/YourClass.php

# 4. 再次运行测试（测试应该通过）
php bin/w phpunit:run -b --path=app/code/Weline/YourModule/Test/Unit

# 5. 运行HTTP请求测试（端到端验证）
php bin/w http:request GET /your/route

# 6. 检查测试覆盖率报告
# 访问：http://localhost:port/phpunit-report/

# 7. 重构代码（如需要）
# 修改后重新运行步骤4-5

# 8. 所有测试通过后提交代码
git add .
git commit -m "feat: add feature with tests"
```

#### H. AI助手测试责任 (AI Assistant Test Responsibility)

- **MUST**: AI助手在实现任何功能时，必须同时编写测试代码
- **MUST**: AI助手必须运行测试并确保通过后才能声称功能完成
- **MUST**: AI助手必须在响应中显示测试结果（通过/失败）
- **MUST**: 测试失败时，AI助手必须立即修复直到通过
- **MUST**: AI助手不得因"不知道如何测试"而跳过测试
- **MUST**: AI助手必须学习框架的测试模式和最佳实践
- **MUST NOT**: ❌ 禁止AI助手只实现功能不写测试
- **MUST NOT**: ❌ 禁止AI助手写了测试但不运行验证
- **MUST NOT**: ❌ 禁止AI助手测试失败就删除测试

#### I. 违规后果 (Consequences)

- **BLOCKER**: 没有测试的功能禁止合并到主分支
- **CRITICAL**: 测试覆盖率低于90%必须补充测试
- **WARNING**: 测试质量差（如无意义的断言）必须重写
- **REVIEW**: 连续提交无测试代码，必须强制培训和审查

#### J. 测试文档要求 (Test Documentation)

- **MUST**: 在模块README中说明如何运行测试
- **MUST**: 复杂的测试场景必须有注释说明
- **SHOULD**: 提供测试数据的说明文档
- **SHOULD**: 记录已知的测试局限性

**Rationale**:

1. **质量保证**：测试是代码质量的基础保障，没有测试的代码是不可信的
2. **回归防护**：自动化测试能快速发现代码修改引入的问题
3. **文档作用**：测试用例是最好的功能使用文档
4. **重构信心**：有完整测试覆盖的代码可以放心重构
5. **持续集成**：自动化测试是CI/CD的基础
6. **专业标准**：完整的测试覆盖是专业开发的标志
7. **减少bug**：TDD方式可以在编码阶段就发现和预防问题
8. **提高效率**：虽然写测试需要时间，但长期来看大大减少调试和修复时间

**典型错误（严禁）**：
- ❌ "功能已实现，测试待补充" → 拒绝合并
- ❌ "这个功能很简单，不需要测试" → 拒绝合并
- ❌ "手动测试过了，可以上线" → 拒绝合并
- ❌ "测试太难写了，先上线吧" → 拒绝合并

**正确做法**：
- ✅ 先写测试用例（TDD）
- ✅ 实现功能使测试通过
- ✅ 补充边界和异常测试
- ✅ 运行测试查看覆盖率
- ✅ 覆盖率达标后提交代码

---

**Version**: 2.18.0 | **Ratified**: 2024-12-19 | **Last Amended**: 2025-10-26
