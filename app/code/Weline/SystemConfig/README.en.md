# weline-module-system-config

#### Description
WelineFramework 系统配置

#### Software Architecture
Software architecture description

#### Installation

1.  xxxx
2.  xxxx
3.  xxxx

#### Instructions

1.  xxxx
2.  xxxx
3.  xxxx

#### Contribution

1.  Fork the repository
2.  Create Feat_xxx branch
3.  Commit your code
4.  Create Pull Request


#### Gitee Feature

1.  You can use Readme\_XXX.md to support different languages, such as Readme\_en.md, Readme\_zh.md
2.  Gitee blog [blog.gitee.com](https://blog.gitee.com)
3.  Explore open source project [https://gitee.com/explore](https://gitee.com/explore)
4.  The most valuable open source project [GVP](https://gitee.com/gvp)
5.  The manual of Gitee [https://gitee.com/help](https://gitee.com/help)
6.  The most popular members  [https://gitee.com/gitee-stars/](https://gitee.com/gitee-stars/)

## MIG-P1B

Run the short-Scope migration with
`php bin/w scope:migrate-p1b help|preflight|apply|verify|rollback`.

- `apply`, `verify`, and `rollback` require an explicitly registered
  `--database=mig_clone_*`; shared databases are rejected before writes.
- Bare `default`, invalid shapes, and rows whose canonical target already
  exists are isolated as conflicts. Only safely actionable rows count toward
  `unfinished_mappable`.
- `apply` persists its checkpoint and journal before business writes. A fresh
  process must verify it with
  `php bin/w mig:foundation journal-verify --checkpoint=...`.
- A repeated `apply` must report `mapped=0`. `rollback` keeps the canonical
  writer enabled and never restores short-Scope writes.
- Destroy the clone with `php bin/w mig:foundation clone-destroy`, then confirm
  that `clone-list` returns zero.
