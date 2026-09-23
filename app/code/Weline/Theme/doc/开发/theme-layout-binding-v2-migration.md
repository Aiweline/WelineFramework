# 主题布局绑定 v2 迁移

工作模式：`theme_module_runtime`。本迁移只调整布局固化产物与运行绑定，不改变主题、草稿、发布及预览身份规则。

## 命令

部署代码后，通过现有命令注册器增量刷新 Theme 命令，再执行一次迁移：

```bash
php bin/w command:upgrade -m Weline_Theme
php bin/w theme:layout:migrate-bindings --help
php bin/w theme:layout:migrate-bindings
```

迁移命令调用 `ThemeLayoutEntityBakeCoordinator::rebakeAfterInjectionCollect(null, [])`，从数据库的有效主题/作用域/布局身份生成绑定。它不以现有磁盘目录充当身份来源，也不删除旧产物。输出处理数量与 Coordinator 报告的无法映射历史身份及原因。出现未映射记录时，按报告核对历史版本数据；不要猜测发布身份或扫描其它 `r*` 作为正式请求回退。

新增代码本身不会执行迁移。部署后刷新反射元数据（`php bin/w reflection:compile`），常驻 Worker 通过既有重载流程加载新代码。后续结构相同的保存与发布复用已有结构模板；同值绑定不重复写文件。

仅执行迁移（空 changes）时，历史快照完整但缺少对应编辑版本的记录可以原样生成绑定，不重新合并注入计划；报告仍列出缺失的版本关联，后续安装更新不能据此猜测人工卸载。缺失草稿父基线的记录保持旧读取兼容。

## 产物与兼容

同一主题、作用域、页面 identity 下：

- `s{structureHash}/` 保存不可变 `layout.phtml`、`shell.phtml` 和 `structure.json`。
- `configs/{configHash}/` 保存不可变配置及资源清单。
- `d{revisionId}/binding.json`、`r{releaseId}/binding.json` 将草稿或发布身份绑定到上述完整产物。
- `current.json` 保留当前草稿/发布入口。正式店面依旧从 `published_release_id` 对应的 `r{id}` 解析；预览依旧服从编辑器上下文或预览 Token。
- chrome 同样分离结构、配置快照与 `tv{versionId}/chrome/binding.json`。

完整产物全部写好后才原子替换 binding。一次渲染持有已解析的绑定，因此切换期间不会将新配置与旧结构拼成半套产物。旧格式读取保留兼容，旧文件不清理；不要把旧结构中的硬编码配置路径直接当作 v2 模板复用。

## 运行前后核对

迁移前记录一个正式页面及其草稿、发布 ID，保存布局和公共壳可见结果，以及现有模板的 hash/mtime。迁移后检查命令报告，再验证：

1. 正式页面、编辑器预览及指定历史版本预览分别读取自己的身份；页头、页脚及必装部件仍存在。
2. 仅修改部件配置，页面立即读取新值，而结构 phtml 的 hash/mtime 不变；已发布与历史预览不被草稿配置污染。
3. 相同结构发布新 release，只创建配置/绑定，复用原结构模板。
4. 新增、移动或删除部件，以及插件默认注入变更，更新受影响布局结构；人工卸载记录继续生效。
5. 正常已固化请求不重新组装 `structure.json`，不查询默认注入计划。旧产物与无法映射的历史身份保留待核对。

## 定点验证

```bash
vendor/bin/phpunit --no-configuration --bootstrap app/bootstrap_phpunit.php app/code/Weline/Theme/test/Unit/LayoutEntity
```

已有前端冒烟套件可验证首页公共壳及必入部件。运行前从 `tests/e2e/framework/runtime-info.php` 确认当前地址，并设置 `WELINE_E2E_BASE_URL`，避免使用测试文件的历史默认地址：

```bash
node tests/e2e/node_modules/playwright/cli.js test --config=tests/e2e/playwright.config.js app/code/Weline/Theme/test/e2e/frontend/homepage-required-injection-smoke.spec.js --project=chromium --grep=A-e2e-layout --workers=1
```

上述冒烟仅覆盖首页展示；配置隔离、发布复用和安装触发必须完成对应真实运行验收，不能只凭单元测试宣布迁移完成。
