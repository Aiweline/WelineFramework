## 2026-07-20 无法访问修复
- 根因: generated/routers 缺失 + sqlite→pgsql 丢 UNIQUE/DEFAULT
- 已补 w_acl UNIQUE(source_id)、时间戳 DEFAULT、ACL normalize 默认值
- 实例: ai-test-pgsql-access-20260720143713 @ https://p05113ef3.weline.test:9533/
