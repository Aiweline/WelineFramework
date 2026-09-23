# channel — 运营怒批坏版首页（顾问 → PM）

日期：2026-09-23  
角色：电商顾问（认账定性）+ 父会话已先热修布局源码  
用户附图：商品卡主图塌成顶条 + Hero CTA 被裁 + `Illustrative scene` + 标题被挡成 `mmended product`

## 定性（给项目经理背锅）

**P0 发布验收失职。** Wave-3 MALL-01 为「一屏见 4 卡」对 `.wpc-media`/`img` 加 `max-height: 7.5rem`，与商品卡 `padding-top:100%` 方图锁冲突 → 图只剩顶条、中部空白。Hero 整块 `overflow:hidden` 裁掉 CTA。发布前未抓视觉回归，运营看见的锅在 **PM DoD/汇审门禁**，不在运营。

## 父会话已改（热修）

- `Theme/.../layouts/homepage/default.phtml`
- `app/design/Weline/hanfu/.../layouts/homepage/default.phtml`
- 去掉媒体 max-height；Hero 只压媒体、CTA 居中；隐藏 `hanfu-hero-disclosure`；信任条取消死 max-height；店音乐头像缩小。

## 请 PM

1. 记 `pm-hotfix-homepage-broken-layout.md` 承认门禁失职  
2. 派席复核发布/清缓存后 Browser 过签  
3. 汇审补一条：禁止再对 `.wpc-media` 设 max-height

`notify_pm: true`
