# weline-module-smtp

#### 介绍
Weline Smtp 模组提供统一发信入口，支持按发件人 code 配置多个发件来源。发件来源可以是外部 SMTP，也可以是 Weline Mail 自建邮局账号。

#### 使用说明

后台入口：`Smtp邮件服务 -> Smtp配置`。

1. 添加发件人并填写唯一 `code`，业务模块可通过 `w_query('smtp', 'send', ['sender_code' => 'code'])` 指定发件人。
2. 选择 `外部 SMTP` 时，需要手动配置 SMTP 主机、端口、加密方式、认证方式、用户名和密码。
3. 选择 `自建邮局账号` 时，页面会提供可搜索的 Mail 账号选择器；选中账号后自动带出 SMTP 主机、端口、加密方式和用户名。
4. 自建 fake 邮局账号无需密码，测试发送会写入 Mail 发件箱和本地收件箱，并同步写入 SMTP 发送日志。
5. 真实自建邮局账号仍依赖 Mail 模块底层邮件服务环境，真实外部 SMTP 发送仍依赖客户提供可连接的外部 SMTP 凭据。

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
