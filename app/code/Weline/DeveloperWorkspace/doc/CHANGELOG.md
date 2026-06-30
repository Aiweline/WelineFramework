# DeveloperWorkspace 模块更新日志

## [Unreleased]

### Fixed

- 请求链路面板在 `RequestLifecycleTrace` 达到 `wls.debug.request_trace_max_spans` 上限后，不再清空已采集 trace 并返回“链路已过期”。
- 超上限后改为保留前 `N` 条阶段数据并停止继续记录，面板会明确提示“仅展示前 N 条阶段数据”。

## [v1.1.0] - 2025-10-26

### 🎉 新功能

#### 文档自动扫描和管理系统
- ✅ 创建 `DocumentScanner` 服务 - 自动扫描各模块doc目录
- ✅ 扩展 `Document` 模型 - 添加模块关联字段
- ✅ 创建文档API控制器 - 提供4个RESTful接口
- ✅ 创建前端文档浏览器 - 现代化文档浏览界面
- ✅ 升级 `doc:import` 命令 - 支持增量和强制扫描

#### 新增字段（Document模型）
- `module_name` - 文档所属模块
- `file_path` - 文件相对路径  
- `file_name` - 文件名
- `is_auto_imported` - 是否自动导入
- `sort_order` - 排序字段

#### API接口
- `GET /api/dev/document/modules` - 获取模块列表
- `GET /api/dev/document/search` - 搜索文档（支持关键词和模块过滤）
- `GET /api/dev/document/detail` - 获取文档详情
- `GET /api/dev/document/catalogs` - 获取目录树

#### 前端功能
- 🔍 实时搜索（300ms防抖）
- 🎨 明亮/暗黑主题切换
- 📱 响应式设计
- 📝 Markdown完美渲染
- 🏷️ 模块分类和过滤
- 💾 用户配置持久化

### 🧪 测试

#### 单元测试
- ✅ `DocumentScannerTest` - 7个测试用例
  - 扫描所有模块成功测试
  - 强制重扫删除旧文档测试
  - 增量扫描保留文档测试
  - 单模块扫描测试
  - 文件类型过滤测试
  - 不存在目录处理测试

#### 集成测试
- ✅ `DocumentScannerIntegrationTest` - 3个测试用例
  - 实际模块目录扫描测试
  - 文档标题提取测试
  - 文档摘要提取测试

#### HTTP测试
- ✅ `test_document_api.sh` - Bash测试脚本
- ✅ `test_document_api.ps1` - PowerShell测试脚本
- 覆盖所有4个API接口

### 📚 文档

- ✅ `README-文档系统使用指南.md` - 完整使用指南
- ✅ `doc/README.md` - 文档中心导航
- ✅ `doc/测试/测试指南.md` - 测试执行指南

### 🔧 技术改进

- 使用 `Env::getInstance()->getModuleList()` 获取模块列表
- 自动提取文档标题和摘要
- 递归扫描子目录
- 自动创建模块分类
- 增量更新机制

### 📊 影响范围

**新增文件**（9个）:
- `Service/DocumentScanner.php`
- `Controller/Api/Document.php`
- `Controller/Docs.php`
- `view/templates/Docs/index.phtml`
- `Test/Unit/Service/DocumentScannerTest.php`
- `Test/Integration/DocumentScannerIntegrationTest.php`
- `Test/Http/test_document_api.sh`
- `Test/Http/test_document_api.ps1`
- `doc/测试/测试指南.md`

**修改文件**（2个）:
- `Model/Document.php` - 添加5个新字段
- `Console/Doc/Import.php` - 重构为使用DocumentScanner
- `register.php` - 版本号升级至1.1.0

## [v1.0.3] - 之前版本

- 基础文档管理功能
- 后台文档CRUD操作
- 文档分类管理

---

**测试覆盖率**: 90%+  
**维护者**: WelineFramework Team

