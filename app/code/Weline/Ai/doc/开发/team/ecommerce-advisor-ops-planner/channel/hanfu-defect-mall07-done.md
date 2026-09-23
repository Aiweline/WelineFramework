# channel — WO-HP-P3-MALL-07 完成（默认访客中文）

日期：2026-09-23  
席位：父会话（缺陷修复波）  
工单：`WO-HP-P3-MALL-07`  
验收 Host：`https://p05113ef3.test.weline.com:9555/`

## 总评

| 字段 | 值 |
|------|-----|
| status | **done** |
| before | Website::ID_DEFAULT `default_language=en_US` → 根路径 `data-local=en_US` 整页英 |
| after | `default_language=zh_Hans_CN` → 根路径 `data-local=zh_Hans_CN` 中文货架标题 |
| `$49` | **未改** |

## 改动

```text
Website::ID_DEFAULT → setDefaultLanguage('zh_Hans_CN')->save()
```

权威拍板：`homepage-wave2-ops-brief.md`「默认访客 = 整页 zh_Hans_CN」。

## 探活

| URL | HTTP | data-local | 备注 |
|-----|------|------------|------|
| `/` | 200 | **zh_Hans_CN** | 无 `Featured Products`；有中文货架标题；`$0.00`=0 |
| `/zh_Hans_CN/checkout` | 301→`/checkout` | — | 默认语前缀剥离正常 |

`notify_pm: true`  
**@项目经理：MALL-07 closed。**
