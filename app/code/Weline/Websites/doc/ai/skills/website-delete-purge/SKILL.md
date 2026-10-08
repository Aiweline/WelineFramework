---
name: website-delete-purge
description: >-
  删站：指定站名，位置默认本地、明示线上才上生产；按抽象流程删站图谱+孤儿配置+无交叉引用静态。
  Triggers: 删站 / 删除网站 / 清理站点 / website delete / purge website.
  Authority: this file + 网站删除与孤儿清理.md + sitesetup/删站.md.
  FORBID hardcoding any concrete shop/domain/SSH/IP/website_id as default target.
---

# website-delete-purge（删站）

## When To Use

```text
删站：{站}
删站 {站}
删站 {站} 线上
删除网站 {站}
清理站点 {站}
website delete: {site}
purge website {site} [local|online]
```

- **站名必填**（code / 显示名 / id）。无站 → 追问，禁止开删。
- **位置默认本地**；仅当用户明示 `线上` / `生产` / `online` / `production` 才连该站柜 README 的生产 Host。

非本技能：只卸媒体引用（走 `media-reference-identity`）；只部署（走站柜部署技能）。

## Load First

1. `dev/ai-command/sitesetup/删站.md`
2. `app/code/Weline/Websites/doc/网站删除与孤儿清理.md`（**流程正文**）
3. `app/code/Weline/Websites/doc/网站备份.md`
4. `app/code/Weline/Websites/doc/default-website-and-request-detection.md`（默认站禁删）
5. 位置=线上时：`websites/<cabinet>/README.md`（仅解析 SSH / 应用根；cabinet 由用户目标站映射，禁止套用其它柜）

## Hard Rules

1. **抽象**：禁止在本技能、指令或执行计划里写死某一商户站名、域名、SSH 别名、公网 IP、非 0 固定 `website_id` 当默认。目标只来自本回合用户参数 + 库解析。
2. **默认站禁删**：`website_id=0` / `code=default` → 失败关闭。
3. **默认本地**：未明示线上禁止 SSH/改生产。
4. **先备份再写**：线上强制时间戳 dump + 校验；优先官方网站备份。
5. **静态清理走交叉引用图**：禁止只因 `file_asset_reference` 为空就删 catalog/blog；保留 `font-subset/**` 与配置仍引用的站柜媒体。
6. **官方删除不足**：后台删站不清理分片表 / 孤儿配置 / 无引用静态；本技能必须补齐（见流程正文 §3.2–§3.4）。
7. Store/SalesChannel 仍引用时禁止硬删父站；先按 Model 门禁处理子级。

## Steps

1. 解析 `{站}` + 位置（默认 local）。
2. 库解析唯一 `(website_id, code, scope)`；歧义则列出候选项。
3. 只读清点（子店渠、域名、配置、分片、媒体体量）→ 向用户报库存摘要后继续（用户已下删站令则不必再问「是否删除」）。
4. 备份并校验。
5. 结构删除 → 孤儿配置 → 无交叉引用静态（顺序见流程正文）。
6. 验收存活站媒体关联与配置引用文件；回报前后计数、释放量、备份路径、位置。

## Output Template

```markdown
# 删站 · {code} (id={id}) · {local|online}
- 备份：路径 / 校验
- 结构：域名/店渠/分片/网站行
- 孤儿配置：删除行数
- 静态：删除 asset 行 / 磁盘文件 / 释放体积
- 验收：存活站 product_media 缺失=0；封面/配置媒体抽检
```

## Anti-Patterns

| 禁止 | 正确 |
|------|------|
| 技能里写死「某某站 / 某某 SSH」 | 用户给站名 → 库解析；线上读**该**站柜 README |
| 未说线上却连生产 | 默认本机 |
| 只清 `file_asset_reference` 空行 | 完整交叉引用图 |
| 跳过备份直接 DELETE | 先备份校验 |
| 把「只留默认」写成删 default | 只删非默认；default 永远禁删 |
