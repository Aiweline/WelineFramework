# account.sidebar.group.security

在「安全设置」下注入子导航（设备管理、两步验证等）。

- 文件：`view/hooks/account.sidebar.group.security.phtml`
- 必填：`data-account-nav-parent="security"`、`data-section`、`data-account-nav-link="true"`
- 分组标题由 Customer 宿主的「安全设置」链接承担，实现方勿再输出 group title。
