# 字体子集（Font）

## 约定目录

模块只需把字体放入：

```text
{Module}/view/fonts/**/*.{ttf,otf,woff,woff2}
```

`setup:upgrade` 后 Theme 自动发现并按语言预热子集（已有跳过）。模板用 `<w:theme:font>`，详见 `Weline_Theme/doc/theme-font.md`。

## 事件

| 事件 | 说明 |
|---|---|
| `Weline_Theme_Font::warmup_collect` | 可选：追加 fonts / languages |
| `Weline_Framework_Setup::upgrade_after` | 内置 Observer 执行预热 |

## 公开 URL

子集落盘：`pub/media/font-subset/` → `/pub/media/font-subset/...`
