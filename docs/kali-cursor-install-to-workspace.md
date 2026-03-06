# 在根分区已满时把 Cursor CLI 装到 workspace

根分区 `/` 已满，安装脚本会往 `~/.local`（在根分区）写，导致报错「设备上没有空间」。  
把 `~/.local` 迁到 `/home/kali/workspace` 后，再运行官方安装脚本即可。

## 步骤（在终端执行）

### 1. 把 ~/.local 迁到 workspace 并做符号链接

```bash
# 若正在用 .local 里的程序，先关掉
mkdir -p /home/kali/workspace/.local
# 把现有内容移到 workspace（保留原有 bin/share/state）
mv /home/kali/.local/* /home/kali/workspace/.local/
rmdir /home/kali/.local 2>/dev/null || true
ln -s /home/kali/workspace/.local /home/kali/.local
```

### 2. 让安装过程的临时文件也写到大盘

```bash
export TMPDIR=/home/kali/workspace/tmp
mkdir -p "$TMPDIR"
```

### 3. 再运行 Cursor 安装脚本

```bash
curl https://cursor.com/install -fsS | bash
```

### 4. 确保 PATH 包含 ~/.local/bin（zsh）

```bash
echo 'export PATH="$HOME/.local/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

之后 `~/.local` 实际在 workspace，新装的 Cursor CLI 和别的用 `~/.local` 的软件都会用大盘空间。

## 可选：长期把 TMPDIR 设到 workspace

在 `~/.zshrc` 里加：

```bash
export TMPDIR=/home/kali/workspace/tmp
mkdir -p "$TMPDIR" 2>/dev/null
```

这样以后各种安装、解压的临时文件也会用 workspace，减轻根分区压力。
