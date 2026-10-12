# PHP 审查速查（上游精简）

来源改编：[awesome-skills/code-review-skill](https://github.com/awesome-skills/code-review-skill) `reference/php.md`（MIT）。  
完整上游指南按需 WebFetch；本文件只保留与本仓相关的速查，**不替代** Weline ORM / 扩展点 / `business_forbids_sql_dialect`。

## Must-check（对照 diff）

- [ ] 新 PHP 文件 `declare(strict_types=1);`  
- [ ] 公开 API 有参数/返回类型  
- [ ] 用户输入校验；输出按上下文转义  
- [ ] **禁止业务层 SQL 方言**（走 ORM / Adapter）；勿手写 `ON DUPLICATE` / driver 分支拼 SQL  
- [ ] 密码只用框架/标准哈希；禁 `md5`/`sha1` 存口令  
- [ ] 敏感配置走 SystemConfig 加密字段，不硬编码密钥  

## 与本仓叠加

- 跨模块：禁直调外国 Service/Model → Interface / Query / Event / Hook / Provider  
- 改 Model/Controller/注册面 → `module_upgrade_gate` 升版  
- 模块串融：见 `module-boundary-contamination.md`（优先于通用 PHP 风格 nit）
