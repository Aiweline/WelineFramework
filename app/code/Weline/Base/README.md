# Weline Base 模块

这是一个聚合模块，用于统一管理所有 Weline 模块的依赖关系。

## 功能

- 统一管理所有 Weline 模块的 Composer 依赖
- 简化根目录 composer.json 的配置
- 通过 path repository 自动发现本地模块

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

这样，Base 模块会自动引入所有其他模块的依赖。

