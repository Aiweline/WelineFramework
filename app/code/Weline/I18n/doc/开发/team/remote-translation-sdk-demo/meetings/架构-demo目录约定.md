# 架构 — Query Provider 并块挂 API Demo（远程协助翻译首例）

- 席位：`Team:架构师:`
- 日期：2026-09-22
- 状态：**pass（修订后可对齐冻结）**
- 范围：可下载 PHP/JS API Demo + 文档挂点；**禁止本文件当实现代码**
- 修订依据：并块 / 有则显 / `'demo'=>true` 默认同名；权威源在**归属模块**；下载归 **`Weline_Api`**；路径钉死为模块内 **`source/api-demo/`**（非 `view/statics`、非 `pub/source`）

---

## 修订摘要（累计）

### A. 并块挂载（仍生效）

| 作废 | 生效 |
|------|------|
| 独立「远程协助翻译 Demo」文档组 | Demo 按钮挂在 **Frontend Worker API**（Query Provider）详情页 `renderSdk` 同区 |
| `demo_id` 能力化名 | **默认同名 provider**（`'demo'=>true` → `i18n_remote_translation`） |

### B. 存放位置（本回合钉死）

| 作废 | **钉死** |
|------|----------|
| `pub/source/demo/`、`pub/source/api-demo/` | **禁止**业务 API demo 进 `pub/source/**` |
| `view/statics/api-demo/` | **作废**（不再当 API demo 权威源） |
| `examples/demo/`、`examples/api-demo/` | **禁止** |
| — | **仅** `app/code/{Vendor}/{Module}/source/api-demo/{demo_id}/{php\|js}/` |

### C. 下载入口（仍生效）

| 作废 / 不推荐 | **钉死** |
|---------------|----------|
| DeveloperWorkspace 自建 `demo-download` | **`Weline_Api`** 统一协助（解析 → 校验 → zip） |
| 文档指向 `pub/source` | `example.demos[].url` → **Api 协助端点** |

保留：不污染 BinQuery / `sdk-download`；PV=PHP；Backend REST `api.demo` 双轨。

---

## 1. 「并块」精确含义（仓内事实）

| # | 文档组 | 生成 | 记录 |
|---|--------|------|------|
| **① Query Provider 并块** | **`Frontend Worker API`** | `ApiDocService::generateFrontendWorkerApis()` | 每个 `frontend===true` 的 operation；详情 → `renderSdk` |
| ② BinQuery SDK 壳 | `BinQuery SDK` | `BinQueryApiDocContributor` | overview / php-sdk / js-sdk / protocol（**不**挂业务 Demo） |

统一规则：并块内 **有 `example.demos[]`（含 url）才显示「Demo 下载」**；无则不显。  
首例 provider：**`i18n_remote_translation`**。  
进并块：ops 须 `frontend=true`，且 **`external=false`**，`auth=backend` + 既有 `backend_acl`。

---

## 2. 钉死目录（模块内 `source/api-demo`）

### 2.1 与 `view/statics` / `pub/source` 的边界

| 树 | 用途 | API Demo？ |
|----|------|------------|
| `{Module}/view/statics/` | 模板/浏览器静态（css/js/图） | **否**（本约定不作废该树本身，但 **不作** API demo 根） |
| `pub/source/binquery-*` | 官方 BinQuery SDK + DW `sdk-download` | **否**（业务 Demo 禁止跟抄） |
| **`{Module}/source/api-demo/`** | **可下载 API Demo 包权威源** | **是（钉死）** |

### 2.2 路径（默认同名 provider）

| 层 | 路径 |
|----|------|
| **唯一权威源** | `app/code/{Vendor}/{Module}/source/api-demo/{demo_id}/{php\|js}/` |

首例：

```
app/code/Weline/I18n/source/api-demo/i18n_remote_translation/
  php/          # PV = PHP demo
  js/
  README.md
```

- Provider 级一套包（非每 op 一套）。
- zip 顶层建议：`{demo_id}-{lang}`（如 `i18n_remote_translation-php`）。
- 对外交付以 **Api 打 zip** 为准。

---

## 3. Descriptor 少配置 + 「有则显」探测

### 3.1 Schema

```php
return [
    'provider' => 'i18n_remote_translation',
    'module' => 'Weline_I18n',
    'demo' => true, // 或 'i18n_remote_translation'
    'operations' => [ /* frontend=true, external=false, auth=backend, … */ ],
];
```

| 写法 | `demo_id` |
|------|-----------|
| 省略 | 无 Demo |
| `true` | `= provider` |
| 同名字符串 | 字面 id |

### 3.2 探测（文档生成 / 下载共用）

权威探测在 **`Weline_Api` 协助服务**（如 `ApiDemoPackageService`）：

1. provider.`demo` → `demo_id`；provider.`module` → 模块名  
2. 模块注册表 → **`base_path`**  
3. 候选：`{base_path}/source/api-demo/{demo_id}/{lang}/`（`lang ∈ {php,js}`）  
4. `is_dir` 且非空 → 「有」；写入：

```json
"demos": [
  {"lang": "php", "label": "下载 PHP Demo", "url": "/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=php"},
  {"lang": "js", "label": "下载 JS Demo", "url": "/api/api-demo/download?module=Weline_I18n&demo=i18n_remote_translation&lang=js"}
]
```

（URL 前缀以实现期路由为准；**参数钉死**：`module` + `demo` + `lang`。）  
无目录 → 不写 → 前端不显。

---

## 4. `Weline_Api` 统一下载协助（硬）

### 4.1 职责

| 组件 | 归属 | 职责 |
|------|------|------|
| 下载入口 | **`Weline_Api`** | 收参、策略、zip、attachment（响应**不得**泄露 Token） |
| 路径 + Zip + exists | **`Weline_Api` Service** | 只读已注册模块下 **`source/api-demo/…`** |
| Demo 内容 | 归属业务模块（首例 I18n） | `source/api-demo/…` + README |
| 文档投影 | `ApiDocService` 调上述 Service | `example.demos` |
| `renderSdk` | DeveloperWorkspace | 只渲染元数据 |

### 4.2 安全（realpath）

1. `module` = 已启用 `Vendor_Module`。  
2. `demo` ∈ `[a-z0-9_]+`；`lang` ∈ `php|js`。  
3. **`realpath` 前缀必须等于** `realpath({moduleBase}/source/api-demo)` + 分隔符。  
4. 禁止 `..`、符号链接逃逸；禁止打包该前缀外任何路径（含 `view/statics/**`）。  
5. 不存在 / 空 → **404**。

### 4.3 DeveloperWorkspace

- **不实现** `demo-download`；文档 **直链 Api**。  
- `sdk-download` **保留**（仅 BinQuery `pub/source/binquery-*`）。

### 4.4 路由形态（参数不变）

```
GET …/api-demo/download?module={Vendor_Module}&demo={demo_id}&lang=php|js
```

响应：`application/zip`；文件名建议 `{demo_id}-{lang}-api-demo.zip`。

---

## 5. 远程翻译首例：调什么

| 平面 | 角色 |
|------|------|
| Query 核 | `i18n_remote_translation` |
| Admin REST | 外人协助主路径（后台 Token） |
| Worker 并块 | `frontend=true`；`auth=backend` |
| BinQuery | **不走**（`external=false`） |
| 可下载 Demo | 包在 `I18n/source/api-demo/i18n_remote_translation/{php,js}/`；README 教 Admin REST |

页内 Backend REST **`api.demo`**：双轨保留。

---

## 6. 禁止清单

- `pub/source/demo`、`pub/source/api-demo`、任何 `pub/source` 业务 API Demo。  
- **`view/statics/api-demo`** 作为 API demo 权威源。  
- DW 平行下载；污染 `binquery-*` / 扩展 `sdk-download`。  
- 独立发现组；无 `demo` 段或无 `source/api-demo` 目录仍显示按钮。  
- `external=true` 承载远程翻译写；跨模块代写 Rest/Provider。  
- 下载端点打出 `{moduleBase}/source/api-demo/` 以外路径。

---

## 7. 冻结检查清单

- [ ] 权威源仅：`{Module}/source/api-demo/{demo_id}/{php,js}/`  
- [ ] 无 `pub/source/**`、无 `view/statics/api-demo` 业务 API demo  
- [ ] `'demo'=>true`；探测 Api Service + `base_path/source/api-demo`  
- [ ] 下载仅 `Weline_Api`；DW 无独立 demo-download  
- [ ] 并块有则显；`frontend=true` + `external=false`  
- [ ] 下载包调 Admin REST；Backend `api.demo` 双轨  

---

## 8. 结论

**pass**

建议项目经理唤醒：

| 席 | 切片 |
|----|------|
| **Team:API:** | Service + download；realpath 前缀 `source/api-demo`；`ApiDocService` 投影 |
| **Team:I18n:** | `source/api-demo/i18n_remote_translation/{php,js}` + descriptor `demo` + REST `api.demo` |
| **Team:测试:** | 显隐；zip 仅该树；穿越负例；不碰 binquery / view/statics |
| DeveloperWorkspace | 仅渲染 `example.demos`；不打包 |

## 9. Agent 验收入口（后补）

下载 zip 并跑三 type pending 的步骤权威：

- `app/code/Weline/I18n/doc/远程翻译API-Demo下载与验收.md`
- 技能：`app/code/Weline/I18n/doc/ai/skills/remote-translation-api-demo/SKILL.md`
- Api 指针：`app/code/Weline/Api/doc/api-demo-download.md`

notify_pm: true
