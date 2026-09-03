# 博客配图与文件资产硬规则（R2）

适用范围：`Weline_Blog` 的文章封面、正文插图，以及被 Blog 引用的 `FileAsset`。

## 1. 主题与图片必须一一对应

1. R2 维护 **160 个主题封面**：48 个汉服知识/购买主题 + 56 个民族服饰主题的 overview/occasion 两种构图（112 张）。
2. 每个 base slug 必须对应唯一封面 URL 和唯一二进制 SHA-256；**中英文同主题可共用**同一张封面，因为它们是同一主题的语言版本，不算跨主题重复。
3. 圆领袍、马面裙、齐胸襦裙、交领襦裙等形制必须能从领型、腰线、裙褶或袍身结构中被识别；不得以仅有“古风氛围”的图片替代。
4. 中国 56 民族内容必须以 `china-ethnic-groups.php` 的区域、服饰、材料/工艺、纹样与场合资料为边界，不得把汉服形制套用为民族服饰。
5. 禁止无关库存图、抽象占位图、文字海报、Logo、水印，以及与正文标题不一致的商品图。
6. R2 正文默认不插入 `<img>`；有确切新增图文需求时，必须先增加独立 FileAsset、语境说明和重复校验，不得从其他文章复制。

## 2. 文件管理器元数据不可省略

每张新图或替换图必须通过 `FileAssetLibraryInterface` 注册，不得只把文件复制进 `pub/media`。每个资产必须同时具备：

- `zh_Hans_CN` 与 `en_US` 两条 `FileAssetLocale`；
- 非空 `display_name`、`default_alt`、`description`、`default_caption`；
- `translation_state=reviewed`、`translation_origin=manual`；
- FileAsset metadata 中的 `source / license / purpose / relations / review`；
- 可核对的宽高、MIME、SHA-256、对象路径和关联 base slug。

缺少任一语言或任一必填说明时，资产不得进入文章发布引用。

## 3. 写入、复核与删除顺序

1. `--dry-run`：校验 320 篇发布文章、160 个中英主题对、48 个核心 profile、56 个民族 profile、160 张源图和唯一哈希，不写数据库。
2. `--apply`：先注册/补齐 FileAsset 双语元数据，再通过 `BlogPostAdminService` 重写全部文章。
3. `--verify`：复核 320 篇均有 6 个以上内容章节、无旧填充句、无正文 `<img>`、160 个唯一主题与唯一封面，并检查全库正文段落无完全重复。
4. 删除旧图前必须完成**零引用证明**：数据库无文章/资源引用，源码精确搜索无引用，并让 FileManager 的引用守卫再次确认；只删除列入清单的确切文件，禁止目录级批量删除。
5. 任一检查失败即停止，不得以降低数量、跳过语言元数据或保留错误图片的方式继续。

## 4. 当前唯一批处理入口

```bash
php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --dry-run
php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --apply
php app/code/Weline/Blog/data/remediate-hanfu-content-r2.php --verify
```

验收不再以“URL 不同、SHA256 不同”代替视觉去重：

1. 同一民族的“概览”和“场合”图不得复用同一人物、服装、背景或同一母图裁切，不得再使用重复双联图。
2. 图片必须支持标题中的服饰对象；形制、领型、上下装关系、头饰或场合无法辨认时不得上线。
3. 原创建图标为“编辑配图 / editorial illustration”，不得伪称藏品、历史照片或田野记录。
4. 每项资产保存生成/来源方式、日期、裁切说明和最终 SHA256；文件管理器同步写入 `zh_Hans_CN`、`en_US` 的名称、替代文本、标题、描述、图注、关键词、署名、来源与授权。
5. 旧文件只在数据库、正文、分类和代码引用均为零后删除；迁移失败时禁止先删旧图。
6. 正文验收覆盖 56 个民族 × 2 个主题 × 2 个语言，要求正文和导语唯一、标题结构至少 64 组、五类资料字段完整、同民族双主题不再高度相似。

旧版 `enrich-blog-r1-images.php`、`redistribute-blog-r1-photos-strict.php`、`rewrite-blog-r1-substance.php` 仅保留历史追溯，不得用于 R2 文章回写。
