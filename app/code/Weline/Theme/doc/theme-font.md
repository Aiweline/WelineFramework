# `<w:theme:font>` 字体加载（语言子集化）

把字体文件放进模块约定目录，模板用标签加载；系统更新时自动按语言预热子集。

与 `theme:css` / `theme:js` 同属 Theme 资源标签族：标签在 Theme，算法在 `Weline\Theme\Font` / `Weline\Theme\Minify`。

## 1. 放字体（约定目录，无需手写注册）

在任意模块下创建：

```text
app/code/{Vendor}/{Module}/view/fonts/
```

支持扩展名：`.ttf` / `.otf` / `.woff` / `.woff2`（可分子目录）。

Theme 已内置（`Weline_Theme/view/fonts/`，OFL）：

- `NotoSansSC-Regular.ttf`（400）
- `NotoSansSC-Bold.ttf`（700）

前台 / 后台 Head 默认通过 `<w:theme:font>` 加载上述字体，CSS 变量使用 `"Noto Sans SC"`。

`setup:upgrade` 结束后会扫描所有**已启用**模块的 `view/fonts/**`，为内置语言字符集生成子集（已有文件则跳过）。运行时若尚未预热，标签仍会临时生成并缓存。

引用写法：`{Vendor}_{Module}::{相对 view/fonts 的路径}`

| 文件 | `src` |
|---|---|
| `.../view/fonts/NotoSansSC-Regular.ttf` | `Weline_Theme::NotoSansSC-Regular.ttf` |
| `.../view/fonts/brand/Logo.woff2` | `Weline_Theme::brand/Logo.woff2` |

## 2. 模板用法

### 按语言子集（常用）

```phtml
<w:theme:font
  src="Weline_Theme::NotoSansSC-Regular.ttf"
  family="Noto Sans SC"
  lang="zh_Hans"
  weight="400"
  display="swap"
/>
```

输出一段 `@font-face` 的 `<style>`，`src` 指向公开 URL：`/pub/media/font-subset/...`。

未写 `lang` 时，使用 `State::getLangLocal()`（与站点/请求语言一致，不要在标签侧再造一套解析）。

### 按指定字符临时子集

适合标题、品牌短句等小字符集：

```phtml
<w:theme:font
  src="Weline_Theme::Brand.ttf"
  family="Brand"
  chars="仅这些字ABC"
  weight="700"
/>
```

同字体 + 同字符内容会命中缓存；再次渲染直接复用文件。

### 属性一览

| 属性 | 必填 | 说明 |
|---|---|---|
| `src` | 是 | `Vendor_Module::相对路径`，或可读绝对路径 |
| `family` | 否 | CSS `font-family`；默认用文件名 |
| `lang` | 否 | 语言码，如 `zh_Hans` / `en` / `ja`；与 `chars` 二选一优先 `chars` |
| `chars` | 否 | 显式字符集子集（忽略语言表） |
| `weight` | 否 | 默认 `400` |
| `style` | 否 | 默认 `normal` |
| `display` | 否 | 默认 `swap` |
| `unicode-range` | 否 | 原样写入 `@font-face` |

页面里用字体：

```css
body { font-family: "Noto Sans SC", sans-serif; }
```

## 3. 系统更新预热

事件：`Weline_Framework_Setup::upgrade_after` → Theme `SetupUpgradeAfterFontWarmup`（sort 120）

同批还有生产静态发布：`SetupUpgradeAfterDeployStatic`（`!DEV`，sort 130）→ `deploy:upgrade`，经中立变换事件压缩 css/js（见 `Theme/Minify`）。

1. 自动收集各模块 `view/fonts/**`
2. 语言列表默认来自 `Theme/Font/charset/*.txt`（如 `en`、`zh_Hans`、`zh_Hant`、`ja`）
3. 对每个「字体 × 语言」调用 `ensureLangSubset`：**已有子集跳过**，没有才生成
4. 产物目录：`pub/media/font-subset/`（可被 `/pub/media/font-subset/...` 访问）

可选扩展（一般不需要）：

- `app/code/Weline/Theme/Font/etc/fonts.php`：额外绝对路径
- 事件 `Weline_Theme_Font::warmup_collect`：向 `fonts` / `languages` 追加项

## 4. PHP API（非模板场景）

```php
use Weline\Theme\Font\FontSubsetService;
use Weline\Theme\Font\FontFaceService;

$subset = new FontSubsetService();
$path = $subset->getSubsetPath('/abs/font.ttf', 'zh_Hans');     // 语言
$path = $subset->extractChars('/abs/font.ttf', '指定字符');      // 字符
$url  = $subset->pathToUrl($path);

$css = (new FontFaceService())->renderStyleTag([
    'src' => 'Weline_Theme::NotoSansSC-Regular.ttf',
    'family' => 'Noto Sans SC',
    'lang' => 'zh_Hans',
]);
```

## 5. 注意

- 源字体请确认授权可子集化与分发。
- CJK 语言字符表是「常用字」级别，不是全汉字；页面若用到表外字，可对页面正文再调 `extractChars` / `chars`。
- 子集引擎源自 php-font-lib（LGPL），见 `Theme/Font/NOTICE.md`。
