# Spec: query-bin FPC path family

status: ready-for-plan  
work_kind: feature  
clarify_status: ready-for-plan

## 用户故事

作为边缘抗扫能力，BinQuery 筛搜结果应与 HTML Extra 同身份落在同目录 `index.bq.br`，并通过延续 `…/framework/query-bin/~fpc/…` 路径族识别，避免与店面 HTML Accept 混叶。

## EARS

1. WHEN 请求 path 匹配 `*/framework/query-bin/~fpc/{storefront_rel}/~q/{40-hex-sha1}` THEN Nginx SHALL try_files 同 host 下 `…/{storefront_rel}/~q/{sha}/index.bq.br`，Content-Type SHALL 为 query-bin 线体类型，SHALL NOT 读 `index.html[.br]`。  
2. WHEN 该 ~fpc GET HIT THEN SHALL NOT 进入 QueryBin `postIndex` / 握手 / 签章校验。  
3. WHEN `SIGNED_PATH` / Worker 签章 THEN SHALL 仅绑定普通 `…/framework/query-bin`（无 `~fpc` 段）。  
4. WHEN 普通 QueryBin 筛搜可盘缓存 op 成功 THEN 源站 SHALL 用服务端重算的店面 canonical URI 调用 `FpcHtmlDiskStore::publishBq`，SHALL NOT 信任客户端 ~fpc path 内 sha 指写。  
5. WHEN ~fpc MISS THEN Worker MAY 回退签章 POST 普通 query-bin；SHALL NOT 用店面 URL + Accept 取 bq。  
6. WHEN `rest_frontend` 前缀变化 THEN 识别 SHALL 用后缀 `/framework/query-bin/~fpc/`，SHALL NOT 写死 `/api`。

## UC-BQ-01 路径族 HIT

1. 已 publishBq 到 `/products?price=P` 同身份目录。  
2. `GET /{rest}/framework/query-bin/~fpc/products/~q/{sha}` → 200 + bq 体 + 非 HTML CT。

## UC-BQ-02 防投毒

1. POST 普通 query-bin 成功后只写入重算 sha 目录。  
2. 客户端声称其它 ~fpc sha SHALL NOT 写出该叶。

## 非目标

另开 `generated/binquery/**`；`filteredListingPage` `external=true`；Accept 协商混店面叶。
