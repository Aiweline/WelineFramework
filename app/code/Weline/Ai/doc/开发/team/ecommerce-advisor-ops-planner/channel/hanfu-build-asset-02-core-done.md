# channel — WO-BUILD-ASSET-02 完成（核心 SKU 货架样张主图挂载）

日期：2026-09-23  
席位：内容运营「主图优化」（SKIP MCP）  
工单：`WO-BUILD-ASSET-02`（本波缩到核心 SKU，**不做全站**）  
验收 Host：`https://p05113ef3.test.weline.com:9555/`  
旁注首页：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`

## 总评

| 字段 | 值 |
|------|-----|
| **asset_02** | **done**（核心 12 SKU 主图已挂） |
| 挂载方式 | **已挂**：`FileAssetLibrary::replaceContent` 覆盖原 `role=main` 对象字节（asset_id 不变） |
| 样张 | 复用 ASSET-01 `shelf-0{1..4}` 1200×1200，**未再生图** |
| 范围 | 首页可见核心 12；**非**全站货架/PDP 画廊 |
| Browser 审图 | **N/A 降级**（挂载时 HTML 曾 503；父已恢复） |
| **notify_pm** | **true** |

**父跟进（2026-09-23 16:42）：** 验收 Host 首页 HTML 已恢复 **200** · `data-local=zh_Hans_CN` · `$0.00=0`；p543 主图 / shelf-mounted 副本 HTTP **200**。可 Browser 审卡面。

**@项目经理：** 核心 12 个首页可见 SKU 主图已换成长安汉服货架样张轮换。请派测试禁缓存 Browser 对首页精选卡与抽样 PDP 气质审图；全站换图另开工单。

---

## 挂载状态

| 项 | 状态 | 说明 |
|----|------|------|
| 主图 `role=main` replaceContent | **已挂** ×12 | 列表/精选卡走主图 asset |
| 命名副本 `shelf-mounted/` | **已挂** ×12 | 运营图库旁注 |
| 画廊 `role=gallery` | **未改** | 本波只换主图 |
| 全站 SKU | **不做** | 工单明示缩到核心 |

**阻塞点（非本席阻塞）：** 验收 Host 首页 HTML 本回合探活曾返回 **503**；主图媒体 URL 仍 **200**。HTML 恢复后即可 Browser 审卡面。

---

## 商品清单（12）

来源：此前首页 HTML `data-product-id`（精选/特价区可见）；含马面相关 245/241。  
挂载脚本：`app/code/Weline/Product/scripts/mount-hanfu-shelf-samples-asset-02.php`  
（`enrich-hanfu-entity-images.php` 仅品牌/供应商，本波未用。）

| product_id | 中文名（local） | 样张 | 主图 object_key（末段） |
|------------|-----------------|------|-------------------------|
| 543 | 长乐公主 | shelf-01 | `…/824286376488/01-93bea8934b55.jpg` |
| 542 | 成人汉服重工刺绣大袖衫齐 | shelf-02 | `…/824496857539/01-cc12464cfe85.jpg` |
| 540 | 日常新款一片式汉服 | shelf-03 | `…/1069729626349/01-d1cc54b8955a.jpg` |
| 538 | 原创对穿日常正品汉服 | shelf-04 | `…/982212210856/01-ec484eee1cea.jpg` |
| 261 | 离人歌魏晋大袖衫汉服 | shelf-01 | `…/1042500068281/01-b33eee4c35ee.jpg` |
| 258 | 洛水云裳唐制齐胸裙汉服 | shelf-02 | `…/1038424713943/01-9217ea47e637.jpg` |
| 257 | 仙袂赋唐制齐胸破裙汉服 | shelf-03 | `…/1036771931867/01-e295839aaa83.jpg` |
| 246 | 星河天下明制织金广袖道袍男款汉服 | shelf-04 | `…/998308035891/01-8ed8164dbf38.jpg` |
| 245 | 初冬明制立领弓袋袖短袄马面裙汉服 | shelf-01 | `…/997742393161/01-595752e21bc7.jpg` |
| 244 | 春绮唐制齐胸一片式破裙汉服 | shelf-02 | `…/997213594839/01-e3f5de3df5b3.jpg` |
| 241 | 福泽明制方领补服短袄马面裙汉服 | shelf-03 | `…/996206615159/01-0715537a96d6.jpg` |
| 237 | 马山楚墓复原魏晋制战国袍直裾汉服 | shelf-04 | `…/981174366560/01-772d154db9af.jpg` |

轮换：`i % 4` → shelf-01…04；样张 webp→JPEG(q90) 写入既有 `.jpg` object_key，dims **1200×1200**。

---

## 备份与副本

| 路径 | 内容 |
|------|------|
| `var/backup/hanfu-build-asset-02-20260923_084019/` | 12 张原主图 JPEG |
| `pub/media/catalog/hanfu/r2/shelf-mounted/product-{id}-shelf-0N-1200x1200.webp` | 命名 webp 副本 ×12 |
| 样张源（未改） | `pub/media/catalog/hanfu/r2/shelf-samples/changan-hanfu-shelf-0{1..4}-1200x1200.webp` |

目录缓存：已 `StorefrontCatalogCacheCoordinator::notifyCatalogChanged(0, hanfu_build_asset_02_shelf_mount)`。

---

## 抽检 URL

本地像素抽检：p543/p538/p245 主图文件均为 **1200×1200**，sha 与对应样张 JPEG 一致。

HTTP（验收 Host，本回合）：

| URL | HTTP | 备注 |
|-----|------|------|
| `/zh_Hans_CN/` | **503**（当时） | HTML 暂不可用；媒体可用 |
| 主图 p543 | **200** | size≈249KB |
| 样张 shelf-01 | **200** | 源样张 |

可点击抽检：

- [p543 主图](https://p05113ef3.test.weline.com:9555/pub/media/catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/01-93bea8934b55.jpg)
- [p542 主图](https://p05113ef3.test.weline.com:9555/pub/media/catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/01-cc12464cfe85.jpg)
- [p245 主图（马面）](https://p05113ef3.test.weline.com:9555/pub/media/catalog/hanfu/1688/factory-huazhaoji-cx/997742393161/01-595752e21bc7.jpg)
- [p241 主图（马面）](https://p05113ef3.test.weline.com:9555/pub/media/catalog/hanfu/1688/factory-huazhaoji-cx/996206615159/01-0715537a96d6.jpg)
- [shelf-mounted 副本 p543](https://p05113ef3.test.weline.com:9555/pub/media/catalog/hanfu/r2/shelf-mounted/product-543-shelf-01-1200x1200.webp)
- [PDP 543](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/product/543)（依赖 HTML 恢复）
- [首页](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)（依赖 HTML 恢复）

---

## 非本席 / 排队

- `$49` 包邮价签：**只读不改**
- 画廊/详情实拍图：未改；真 SKU 主图回填可从 `var/backup/hanfu-build-asset-02-*` 回滚
- 全站货架换图：另开工单
- 首页 503：运维/框架席；媒体层本波已就绪
