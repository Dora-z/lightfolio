# Lightfolio

`Lightfolio` 是一个基于 `PHP 8.5 + Vue 3` 的摄影作品展示网站，包含公开画廊与带登录保护的后台管理面板，适合个人摄影作品、主题分组作品集和轻量级在线展示。

## 功能特点

- 公开画廊：
  使用白底画廊布局展示作品，支持分类浏览、分组展示、灯箱查看、滚轮缩放与拖拽查看大图。
- 后台管理：
  需要登录后才能进入，可管理分类、分组和作品信息。
- 浏览器端图片处理：
  上传图片时先在浏览器端转换为 `WebP`，减少服务器处理压力。
- 预览图机制：
  画廊列表与后台缩略图优先加载预览图，进入全屏灯箱时再加载原图，提升页面加载速度。
- SQLite 存储：
  分类、分组、作品数据默认保存在项目同级的 `lightfolio-storage/lightfolio.sqlite`，避免被 Web 服务器直接下载；首次运行会尝试从旧 JSON 文件迁移。

## 目录结构

```text
lightfolio/
├─ index.html            # 前台画廊入口
├─ install.php           # 首次部署安装程序
├─ admin.php             # 后台管理入口
├─ login.php             # 后台登录页
├─ logout.php            # 后台退出
├─ styles.css            # 前台与后台样式
├─ script.js             # 前台 Vue 逻辑
├─ admin.js              # 后台 Vue 逻辑
├─ gallery-data.js       # 前后台共享数据层
├─ api/                  # 分类、分组、作品、上传接口
├─ lib/                  # 登录鉴权与安全相关逻辑
├─ data/                 # 旧 JSON 迁移源，禁止作为公开下载目录
├─ router.php            # PHP 内置服务器敏感路径拦截
└─ uploads/              # 上传目录（默认不纳入仓库）
```

## 运行环境

- PHP `8.5` 或更高版本
- 浏览器支持 `Canvas` 与 `WebP`
- 建议开启 PHP 内置开发服务器或使用 Nginx / Apache 指向项目根目录

## 本地运行

在项目根目录执行：

```powershell
D:\EServer-data\childApp\php\php-8.5\php.exe -S 127.0.0.1:5273 -t C:\Users\DoraZhang\Documents\lightfolio C:\Users\DoraZhang\Documents\lightfolio\router.php
```

然后访问：

```text
http://127.0.0.1:5273/
```

## 服务器安装

1. 上传项目文件到站点目录，并确保 Web 服务器指向项目根目录。
2. 访问 `https://你的域名/install.php`。
3. 按页面检查结果开启 `pdo_sqlite`，并确认 `lib/`、`uploads/` 和项目同级目录可写。
4. 在安装页面设置后台管理员账号和密码。
5. 安装完成后访问 `login.php` 登录后台。
6. 正式上线后建议删除 `install.php`，或在 Nginx / Apache 中限制它的访问。

安装程序会生成 `lib/config.php` 保存管理员账号配置，并初始化 `lightfolio-storage/lightfolio.sqlite`。

## 后台登录

默认后台账号：

- 用户名：`admin`
- 密码：`admin123`

如果已运行安装程序，请使用安装时设置的账号密码。未安装时会回退到上面的默认账号，正式上线前务必运行安装程序或手动修改配置。

## 上传与图片策略

- 上传时会在浏览器端生成两份 `WebP`：
  一份原图用于灯箱查看，一份预览图用于列表展示。
- 原图保存到 `uploads/`
- 预览图保存到 `uploads/previews/`
- 作品数据中的 `previewUrl` 字段用于未全屏时的缩略图加载

## 仓库说明

- 仓库默认不提交实际图片文件
- `uploads/` 中仅保留占位用的 `.gitignore`
- `vendor/` 目录默认忽略

如果你要继续扩展这个项目，比较自然的方向是：

- 增加作品排序
- 增加批量上传
- 增加作品描述、拍摄参数与时间信息
- 增加数据库备份与导出
