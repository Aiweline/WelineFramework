该目录用于存放 `jion-landing` 模板的静态资源。

请将 Banner 默认图放置为：

```
asset/img/banner.png
```

模板在未配置 `banner.background_image` 时，会默认使用如下 URL（按顺序优先）：

- `/static/Weline_Cms/style/jion-landing/asset/img/banner.png`
- `/view/templates/style/jion-landing/asset/img/banner.png`
- `/app/code/GuoLaiRen/PageBuilder/view/templates/style/jion-landing/asset/img/banner.png`

建议在部署时将 `asset/img/banner.png` 发布到可访问的静态资源路径（如 `/static/...`）。

