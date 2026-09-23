# Spec: remote-translation-rest

status: **ready-for-build**（type 扩面 closed 2026-09-22）

## 背景

本机客户端调用 Admin REST 协助翻译：选站/语种、取未译、录入（冲突 skip）、触发词典收集并轮询。与本机 AI 队列无关。

扩面：同一 Path 用 `type=phrase|meta|local_model` 区分 Phrase 词典、`@meta::` Theme/部件参数、LocalModel 实体本地字段。

## 方案

Query 核 + 薄 REST；Websites / I18n 分模块。契约见 team `contracts.md`；扩面对齐见 `meetings/扩type-align.md`。

## 细节

见 `app/code/Weline/I18n/doc/开发/team/remote-translation-rest/` 与 `doc/rest-api-remote-translation.md`。
