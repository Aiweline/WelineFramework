# System default embargo (一期)

一期仅 **国家/领地级** ISO 码，表示多数通用电商难以履约或无常规通邮的地区。

**不含**中国国内偏远加价省（新疆/西藏等）——那些走运费加价，不是系统禁运。

| code | reason_code | note |
|------|-------------|------|
| AQ | territory | Antarctica |
| BV | territory | Bouvet Island |
| GS | territory | South Georgia |
| HM | territory | Heard Island |
| TF | territory | French Southern Territories |
| UM | territory | US Minor Outlying Islands |
| KP | no_commerce | DPRK — commonly blocked for commerce |

Seed 只 upsert，永不删除已有 system 行。后台可新增/解禁/再启用，禁止删除。
