# Spec: remote-translation-rest

status: ready-for-plan → **ready-for-build**（对齐冻结 closed 2026-09-22）

## 背景

本机客户端调用线上 Admin REST 协助翻译词典：选站/语种、取未译、录入（冲突 skip）、触发收集并轮询。与本机 AI 队列无关。

## 方案

Query 核 + 薄 REST；Websites / I18n 分模块；API 席主责。详见 team `contracts.md`。

## 细节

见 `app/code/Weline/I18n/doc/开发/team/remote-translation-rest/`。
