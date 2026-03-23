# Plan - pagebuilder blog publish cache

## Outcome

- 已修复 PageBuilder 博客列表模板未消费 runtime `blog_posts` 的根因，并补齐博客发布后的前台缓存/CDN 失效链路。

## Steps

- [x] Clarify scope, affected files, and risks
- [x] Implement the smallest correct change
- [x] Add or update tests / verification
- [x] Run validation commands
- [x] Update result.md and memory if needed

## Verification Targets

- [x] Unit / phpunit
- [x] Route / integration / http:req
- [x] E2E / browser flow
