# Weline_Seo::protocol::robots_contribute

`RobotsTxtRenderer` 在写出 `robots.txt` 前派发。观察者向 DataObject 追加单行指令：

- `global_lines`：放在第一个 `User-agent` 之前（例如 RSL `License:`）
- `star_lines`：放进 `User-agent: *` 组（例如 `Content-Signal:`）
- `base_url`：当前站点公开 origin
- `website_id`：当前网站 ID
- `robots_enabled`：SEO 是否允许抓取

`Weline_Geo` 用它发布 RSL 与 Content-Signal。robots 正文仍由 `Weline_Seo` 拥有。
