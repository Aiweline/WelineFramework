# Shipping address-catalog（须 Git 追踪）

本目录是运输地址级联的**权威静态源**（`countries.tsv.gz` + `{CC}/provinces|cities|districts|postal|streets.tsv.gz`），经 `shipping:addresscatalog:import` 灌入 DB。

## 规则

- **必须纳入版本库**，禁止 gitignore / 仅本机生成不提交。
- 变更流程：构建脚本改 TSV → 提交本目录 → 各环境 `shipping:addresscatalog:import`（可按 `--country` / `--layer` 增量）。
- 构建：`scripts/build-global-cascade-from-geonames.php`（含 `--fill-empty-cities`）、`build-global-postal-from-geonames.php` 等。

## 层说明

| 文件 | 含义 |
|------|------|
| `countries.tsv.gz` | 国家节点 |
| `{CC}/provinces.tsv.gz` | 省/州（无 ADM1 时可为 `{CC}-00` 合成） |
| `{CC}/cities.tsv.gz` | 市（无 ADM2 时可为 `{prov}-CITY` 合成） |
| `{CC}/districts.tsv.gz` | 区县（可选） |
| `{CC}/postal.tsv.gz` | 邮编反查（可选） |
| `{CC}/streets.tsv.gz` | 街道下拉（可选） |
| `MANIFEST.json` | 构建元数据 |
