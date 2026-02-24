---
name: http-request-testing
description: "Tests HTTP endpoints using http:request (http:req) CLI in Weline Framework. Use when testing routes, APIs, 404/500, or page content. Supports -b/-api auto login. 不懂的命令先 -h 查帮助；path 为必填参数，无 --url，URL 由 env server 配置拼接。"
globs:
alwaysApply: false
---

# HTTP Request Testing Skill

## Purpose
This skill guides the use of the `http:request` (alias: `http:req`) command for testing HTTP endpoints, routes, and API responses in Weline Framework.

## 命令不熟时先查帮助
**对任何 CLI 命令不确定时，先执行 `-h` 或 `--help` 查看用法。**
```bash
php bin/m http:request -h
# 或
php bin/w http:request --help
```
项目入口可为 `bin/m` 或 `bin/w`，以实际存在为准。

## When to Use
- Testing frontend routes and pages
- Testing backend/admin routes (with automatic login)
- Testing API endpoints
- Verifying route accessibility and response content
- Debugging routing issues
- Performance testing with concurrent requests

## Command Overview

### Basic Syntax
```bash
php bin/m http:request <path> [options]
# 或
php bin/w http:request <path> [options]
# 别名
php bin/m http:req <path> [options]
```
**注意**：第一个参数为 **path（路径）**，必填。URL 由 `app/etc/env.php` 中 `server.host`、`server.port` 与 path 自动拼接，无需传完整 `--url`。测试 WLS 根路径示例：`php bin/m http:request /`。

### Key Features
- 鉁?**Automatic Login**: Use `-b` or `-api` flags for automatic backend/API login
- 鉁?**Smart Cookie Management**: Automatically saves and reuses cookies (`var/http_request_cookies.txt`)
- 鉁?**Auto Key Injection**: `-b` adds admin key, `-api` adds api_admin key
- 鉁?**Content Filtering**: Search response content with `filter=` option
- 鉁?**Concurrent Testing**: Support multi-threaded concurrent requests

## Common Use Cases

### 1. Testing Frontend Routes
```bash
# Test homepage
php bin/w http:req /

# Test specific frontend route
php bin/w http:req category/view
php bin/w http:req product/view?id=123
```

### 2. Testing Backend Routes (Auto Login)
```bash
# Test backend homepage (auto login with admin credentials)
php bin/w http:req admin -b

# Test backend dashboard
php bin/w http:req admin/dashboard -b

# Test specific backend page
php bin/w http:req ai/backend/model -b

# Custom login credentials
php bin/w http:req admin/dashboard -b -u=myuser -p=mypass
```

### 3. Testing API Endpoints (Auto Login)
```bash
# Test API endpoint (auto login with api_admin key)
php bin/w http:req rest -api

# Test specific API route
php bin/w http:req rest/v1/module/action -api

# With custom credentials
php bin/w http:req rest/v1/data -api -u=admin -p=admin123456
```

### 4. Content Search and Filtering
```bash
# Search for specific content in response
php bin/w http:req / filter=welcome

# Show context lines around matches (default 3 lines)
php bin/w http:req / filter=welcome -n=5

# Search for errors in backend pages
php bin/w http:req ai/backend/model -b filter=Warning
php bin/w http:req admin/dashboard -b filter=Fatal
```

### 5. POST/PUT Requests
```bash
# POST request with form data
php bin/w http:req api/data -m=POST -d='{"key":"value"}'

# POST request with form-encoded data
php bin/w http:req api/submit -m=POST -d='name=value&key=value'
```

### 6. Custom Headers
```bash
# Add custom HTTP headers
php bin/w http:req / -H="User-Agent: CustomBot"
php bin/w http:req / -H="X-Custom-Header: value"
```

### 7. Concurrent/Performance Testing
```bash
# Concurrent requests (100 times)
php bin/w http:req / -C -t=100

# Concurrent backend testing with login
php bin/w http:req admin/dashboard -b -C -t=50
```

## Command Options

| Option | Short | Description |
|--------|-------|-------------|
| `-backend` | `-b` | Backend route (auto login, admin key) |
| `-api-backend` | `-api` | API backend route (auto login, api_admin key) |
| `--login` | `-l` | Force login (use with -u and -p) |
| `--username` | `-u` | Login username (default: admin) |
| `--password` | `-p` | Login password (default: admin123456，以 `-h` 为准) |
| `--cookie` | `-c` | Use specified cookie file |
| `--save-cookie` | `-s` | Save cookie to file |
| `filter=` | | Search response content |
| `-n=` | | Context lines for filter (default: 3) |
| `tls` | | Enable HTTPS TLS verification |
| `method=` | `-m` | HTTP method (default: GET) |
| `header=` | `-H` | Add HTTP request header |
| `data=` | `-d` | POST/PUT request data |
| `--concurrent` | `-C` | Enable concurrent mode |
| `--times` | `-t` | Concurrent request count |
| `--help` | `-h` | Show help information |

## Integration with Testing

### In Test Scripts
When writing test scripts or automation, use `http:req` to:
1. Verify routes are accessible
2. Check response content for expected values
3. Test authentication flows
4. Validate API responses
5. Debug routing issues

### Example Test Flow
```bash
# 1. Test frontend route accessibility
php bin/w http:req / | grep -q "200 OK" && echo "Frontend OK" || echo "Frontend FAILED"

# 2. Test backend with content verification
php bin/w http:req admin/dashboard -b filter="Dashboard" && echo "Backend OK" || echo "Backend FAILED"

# 3. Test API endpoint
php bin/w http:req rest/v1/data -api | grep -q "success" && echo "API OK" || echo "API FAILED"
```

## Best Practices

1. **不懂先查帮助**：不确定参数或用法时，先执行 `php bin/m http:request -h` 查看当前帮助。
2. **Use `-b` for Backend Routes**: Always use `-b` flag for backend routes to enable automatic login
3. **Use `-api` for API Routes**: Use `-api` flag for API endpoints to enable automatic authentication
4. **Cookie Management**: Let the command handle cookies automatically (saved in `var/http_request_cookies.txt`)
5. **Content Filtering**: Use `filter=` to quickly locate errors or verify content
6. **Concurrent Testing**: Use `-C -t=N` for performance/load testing
7. **Error Detection**: Use `filter=Warning` or `filter=Fatal` to detect PHP errors in responses

## Notes

- **任意命令不熟时**：先运行 `php bin/m <command> -h` 或 `php bin/w <command> --help` 查看帮助，避免猜参数。
- Cookie files are automatically managed and reused
- Expired cookies trigger automatic re-login
- Public pages (like login pages) don't require login even with `-b` flag
- The command uses Guzzle HTTP client for requests
- Supports HTTP/2 protocol
- 目标地址来自 env 的 `server.host`、`server.port`，测试 WLS 时确保 env 中 server 配置与当前运行端口一致
