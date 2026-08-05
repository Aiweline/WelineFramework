# weline-module-system-config

#### 介绍
WelineFramework 系统配置

#### 软件架构
软件架构说明


#### 安装教程

1.  xxxx
2.  xxxx
3.  xxxx

#### 使用说明

1.  xxxx
2.  xxxx
3.  xxxx

#### 参与贡献

1.  Fork 本仓库
2.  新建 Feat_xxx 分支
3.  提交代码
4.  新建 Pull Request


#### 特技

1.  使用 Readme\_XXX.md 来支持不同的语言，例如 Readme\_en.md, Readme\_zh.md
2.  Gitee 官方博客 [blog.gitee.com](https://blog.gitee.com)
3.  你可以 [https://gitee.com/explore](https://gitee.com/explore) 这个地址来了解 Gitee 上的优秀开源项目
4.  [GVP](https://gitee.com/gvp) 全称是 Gitee 最有价值开源项目，是综合评定出的优秀开源项目
5.  Gitee 官方提供的使用手册 [https://gitee.com/help](https://gitee.com/help)
6.  Gitee 封面人物是一档用来展示 Gitee 会员风采的栏目 [https://gitee.com/gitee-stars/](https://gitee.com/gitee-stars/)

## MIG-P1B

短 Scope 迁移：
`php bin/w scope:migrate-p1b help|preflight|apply|verify|rollback`。

- `apply`、`verify`、`rollback` 必须显式指定登记过的
  `--database=mig_clone_*`，共享库在任何写入前拒绝；
- 裸 `default`、无效形状和已存在规范目标均进入冲突隔离，
  `verify` 只把仍可安全映射的行计入 `unfinished_mappable`；
- `apply` 在写入前持久化 checkpoint/journal，必须能由新进程通过
  `php bin/w mig:foundation journal-verify --checkpoint=...` 校验；
- 重复 `apply` 必须 `mapped=0`；`rollback` 保留规范 writer，
  永不恢复短 Scope write；
- 完成后使用 `php bin/w mig:foundation clone-destroy` 销毁克隆，并确认
  `clone-list` 数量归零。
