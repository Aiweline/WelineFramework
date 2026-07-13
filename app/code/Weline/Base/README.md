# Weline Base 聚合包

这是一个 Composer 聚合包，用于统一管理 Weline 默认发行模块的依赖关系；它不是运行时注册模块。

## 功能

- 统一管理默认发行集的 Weline Composer 依赖
- 简化根目录 composer.json 的配置
- 通过 path repository 自动发现本地模块

## 兼容性与包名

- Base 聚合包与其他 Weline 模块统一要求 PHP `^8.4`。
- 模块包名使用 `weline/module-{kebab-case-module}`；框架核心例外为 `weline/framework`。
- Base 只声明当前存在的权威包名，不保留旧别名。

## 使用

在根目录的 `composer.json` 中，只需要添加：

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "app/code/Weline/Base",
      "options": {
        "symlink": false
      }
    }
  ],
  "require": {
    "weline/base": "@dev"
  }
}
```

这样，Base 聚合包会引入其 `composer.json` 明确列出的默认发行模块。新模块不会因为出现在 path repository 中就自动加入聚合包。
