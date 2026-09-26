# weline-module-smtp

#### 介绍
Weline Smtp 模组提供统一发信入口：传输账户（SMTP 连接）与发信渠道（业务模块 Extends 注册）分离。发件来源可以是外部 SMTP，也可以是 Weline Mail 自建邮局账号。

#### 使用说明

后台入口：`Smtp邮件服务 -> Smtp配置`。

1. 添加**传输账户**（账户 ID 由系统生成，不是业务代号，也不是发件邮箱）。
2. 业务模块通过 Extends 实现 `MailChannelProviderInterface` 注册发信渠道（如 `Weline_Order::order_paid`、`Weline_Product::product_update`）；本页将渠道绑定到传输账户。
3. 调用请用 `w_query('smtp', 'send', ['channel' => 'Module::code', ...])`；旧 `sender_code` 仍兼容直接指定传输账户。
4. 选择 `外部 SMTP` 时，需要手动配置 SMTP 主机、端口、加密方式、认证方式、用户名和密码。
5. 选择 `自建邮局账号` 时，页面会提供可搜索的 Mail 账号选择器；选中账号后自动带出 SMTP 主机、端口、加密方式和用户名。
6. **一键自建路径**：企业邮箱已有 active 账号时，配置页「用自建邮局一键配置」（或建站助手深链 `?ensure_mail=1`）由 Smtp 自动写入 `mail_account` 传输，并将**全部**发信渠道切到该传输（旧外部 SMTP 账户保留，可自行删）；`rebind=0` 时仅补未绑定。真实账号仍需填 SMTP 密码后点「测试」确认。
7. 自建 fake 邮局账号无需密码，测试发送会写入 Mail 发件箱和本地收件箱，并同步写入 SMTP 发送日志。
8. 真实自建邮局账号仍依赖 Mail 模块底层邮件服务环境，真实外部 SMTP 发送仍依赖客户提供可连接的外部 SMTP 凭据。

建站助手（Smtp）：`smtp_send_path` → `smtp_transport` → `smtp_channel_bindings` → 模板 / 多语言。

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
