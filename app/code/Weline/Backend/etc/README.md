# Backend 配置目录说明

这里的核心后台约定已经迁移到模块文档：

- `app/code/Weline/Backend/doc/README.md`
- `app/code/Weline/Backend/doc/menu-acl-and-backend-entry-conventions.md`

当前 `etc/` 目录主要放配置文件本体，例如：

- `etc/backend/menu.xml`
- `etc/event.xml`
- `etc/env.php`

开发后台菜单、ACL、页面入口时，不要再参考旧的泛化“后端服务”说明，统一以 `doc/` 下文档和实际源码为准。
