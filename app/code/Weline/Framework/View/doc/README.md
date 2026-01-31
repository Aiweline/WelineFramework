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
