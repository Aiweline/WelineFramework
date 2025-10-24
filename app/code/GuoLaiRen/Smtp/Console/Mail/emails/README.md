# 邮件列表目录说明

此目录用于存放邮件收件人列表文件。

## 文件格式

### 支持的文件类型

1. **TXT 文件** - 纯文本邮箱列表
2. **CSV 文件** - 包含邮箱和其他信息的 CSV 格式
3. **JSON 文件** - JSON 格式的邮箱列表

### TXT 格式示例

```
# 这是注释
email1@example.com
email2@example.com
John Doe <john@example.com>
```

### CSV 格式示例

```csv
email,name,status
user1@example.com,张三,active
user2@example.com,李四,active
user3@example.com,王五,inactive
```

### JSON 格式示例

```json
{
  "recipients": [
    {
      "email": "user1@example.com",
      "name": "张三",
      "active": true
    },
    {
      "email": "user2@example.com",
      "name": "李四",
      "active": true
    }
  ]
}
```

## 使用方法

### 批量发送

```bash
php bin/w mail:bulk --file=recipients.txt --subject="主题" --body="内容"
```

### 指定邮件列表文件

```bash
php bin/w mail:bulk --file=emails/custom_list.txt
```

## 注意事项

1. 邮件地址必须符合标准格式
2. 以 `#` 开头的行将被视为注释
3. 空行将被忽略
4. CSV 文件中 status 为 inactive 的邮箱将被跳过
5. 支持中文姓名

