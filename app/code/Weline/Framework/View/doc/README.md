# Weline Framework View 模块

View 模块提供模板渲染和标签编译功能。

## 核心组件

- **Template** - 模板渲染引擎
- **Taglib** - 标签编译器（v2 极致性能版）
- **Block** - 视图块基类

## Taglib v2 架构

Taglib v2 是一个高性能模板标签编译器，采用以下技术：

- **PHP 8.4+ 语法特性**：Property Hooks、readonly 属性
- **多级缓存**：WeakMap + APCu + 文件缓存
- **编译管道**：常量折叠、死代码消除等优化
- **token_get_all**：精确 PHP 代码识别
- **跨模板隔离**：PHP 占位符按完整节点身份缓存；模板内重复的 `__PHP_N__` 序号不得跨模板复用表达式

详细文档请查看 [Taglib/架构设计.md](Taglib/架构设计.md)。

## 快速开始

```php
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

$taglib = new Taglib();
$template = new Template();

$compiled = $taglib->compile($template, $content, 'template.phtml');
```

## 目录结构

```
View/
├── Taglib.php              # 主入口
├── Taglib/
│   ├── Ast/                # AST 节点类
│   ├── Parser/             # 解析器
│   ├── Compiler/           # 编译器
│   ├── Generator/          # 代码生成器
│   ├── Cache/              # 多级缓存
│   ├── Registry/           # 标签注册表
│   ├── Runtime/            # 运行期渲染
│   ├── Debug/              # 调试工具
│   └── Test/               # 单元测试
├── Template.php            # 模板引擎
├── Block.php               # 视图块
└── doc/                    # 文档
```

## 生产静态资源命名空间

`TraitTemplate` 在生产模式生成模块 `view/statics` URL 和发布目录时，只能使用公开设计主题命名空间，
例如 `Weline/default`。自动安装可能把主题源码记录成 `app/code/Weline/Theme/view/theme` 的绝对路径；
该绝对路径、`Weline_Theme::view/theme` 等模块源码标识都必须回退到框架默认公开命名空间，禁止直接拼入
`/static/`。位于 `app/design` 下的绝对主题路径应转成相对 `Vendor/theme`，已有相对自定义主题路径保持不变。
模块静态目录缓存键必须同时包含归一化后的公开主题命名空间，避免部署配置或主题切换后继续复用旧目录。
