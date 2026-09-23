# 后端 closed · be-reset-shell

`notify_pm: true`  
@项目经理：本席已交付，请检查并更新 SESSION

## 交付摘要

UC-1 后端切片完成：

1. `MailShellRegionStore::clear($storageScope)` ≡ `save($scope, '', [])`（共用 `persistPayload`）
2. `Template::postReset` 在正文重置/`syncChannel` 后调用 `clear($storageScope)`
3. `MailShellHanfuDefaultsService::ensure`：空 header/footer 不再写回默认 HTML（回落 `shell.phtml`）；仅 `$force` 或坏数据（`!important` / `#ff5d05` / 硬编码验收 Host）才覆盖区域；背景图 ensure 保留

## 变更文件

- `app/code/Weline/Smtp/Service/MailShellRegionStore.php`
- `app/code/Weline/Smtp/Controller/Backend/Template.php`
- `app/code/Weline/Smtp/Service/MailShellHanfuDefaultsService.php`
- `app/code/Weline/Smtp/test/Unit/MailShellRegionStoreContractTest.php`
- `app/code/Weline/Smtp/test/Unit/MailShellHanfuDefaultsContractTest.php`
- `app/code/Weline/Smtp/test/Unit/MailTemplateUiSendContractTest.php`
- `app/code/Weline/Smtp/doc/开发/team/mail-shell-reset-default/channel/后端-closed.md`
- `app/code/Weline/Smtp/doc/开发/team/mail-shell-reset-default/meetings/后端-closed.md`

## 如何验

```bash
php vendor/bin/phpunit \
  app/code/Weline/Smtp/test/Unit/MailShellRegionStoreContractTest.php \
  app/code/Weline/Smtp/test/Unit/MailShellHanfuDefaultsContractTest.php \
  app/code/Weline/Smtp/test/Unit/MailTemplateUiSendContractTest.php
```

或项目统一入口：

```bash
php bin/w test --filter 'MailShellRegionStoreContractTest|MailShellHanfuDefaultsContractTest|MailTemplateUiSendContractTest'
```

手工：模板编辑页「重置为默认」→ 当前 scope 壳区清空 → 预览页头回落主题/模块 `shell.phtml`（无炭黑+朱砂库内覆盖）；`ensure` 后空壳不被再次灌入 defaultHeaderHtml。

## 下游

前端 confirm / 翻译文案 / 测试席可并行或在本 closed 后接 WB。
