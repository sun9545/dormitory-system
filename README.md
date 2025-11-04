# 🏠 智能宿舍查寝管理系统

一个基于 **PHP + MySQL + ESP32** 的完整智能宿舍考勤管理系统，支持指纹识别、请假管理、实时统计等功能。

<p align="center">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-blue" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-orange" alt="MySQL">
  <img src="https://img.shields.io/badge/ESP32-Arduino-green" alt="ESP32">
  <img src="https://img.shields.io/badge/License-MIT-yellow" alt="License">
</p>

---

## 📸 系统功能预览

- ✅ **学生信息管理** - 批量导入、编辑、查询
- ✅ **指纹识别签到** - ESP32 + R307 自动识别
- ✅ **请假管理** - 在线申请 + 审批流程
- ✅ **实时统计** - 按楼栋/楼层实时查看
- ✅ **设备监控** - 远程查看设备状态
- ✅ **权限管理** - 管理员/辅导员分权限

> 📖 **使用说明**：部署完成后，查看 [用户使用手册](USER_GUIDE.md) 了解如何使用系统

---

## 🚀 部署指南（手把手教程）

### 第一步：检查服务器环境

**必需环境**：
- PHP 7.4 或更高版本
- MySQL 5.7 或更高版本
- Apache 或 Nginx Web服务器
- Composer（PHP包管理器）

**检查命令**：
```bash
# 检查PHP版本（必须 >= 7.4）
php -v

# 检查MySQL版本
mysql --version

# 检查Composer
composer --version

# 如果没有Composer，安装它
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

### 第二步：下载项目代码

```bash
# 进入Web服务器目录（根据实际情况修改路径）
cd /var/www/html

# 克隆项目
git clone https://github.com/sun9545/dormitory-system.git

# 进入项目目录
cd dormitory-system
```

---

### 第三步：创建数据库

#### 3.1 登录MySQL

```bash
mysql -u root -p
# 输入MySQL root密码
```

#### 3.2 创建数据库和用户

在MySQL命令行中执行：

```sql
-- 1. 创建数据库（数据库名可以改成你想要的）
CREATE DATABASE dormitory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. 创建专用用户（改成你自己的用户名和密码）
CREATE USER 'dorm_admin'@'localhost' IDENTIFIED BY 'your_strong_password_here';

-- 3. 授予权限
GRANT ALL PRIVILEGES ON dormitory_system.* TO 'dorm_admin'@'localhost';

-- 4. 刷新权限
FLUSH PRIVILEGES;

-- 5. 退出MySQL
EXIT;
```

**⚠️ 记住你设置的**：
- 数据库名：`dormitory_system`
- 用户名：`dorm_admin`
- 密码：`your_strong_password_here`

#### 3.3 导入数据库表结构

```bash
# 在项目根目录执行
mysql -u dorm_admin -p dormitory_system < database/schema.sql

# 输入刚才设置的密码：your_strong_password_here
```

**验证是否成功**：
```bash
mysql -u dorm_admin -p dormitory_system -e "SHOW TABLES;"
# 应该显示 11 个表
```

---

### 第四步：配置 Web 端（重要！）

#### 4.1 复制配置文件

```bash
cd web
cp config/env.example.php config/env.php
```

#### 4.2 编辑配置文件

```bash
nano config/env.php
# 或使用你喜欢的编辑器：vim, vi, gedit 等
```

#### 4.3 修改配置参数（逐项说明）

**打开 `web/config/env.php` 文件，按照以下说明修改：**

##### ① 网站基本配置

**📄 文件：`web/config/env.php`**

```php
// 第 22 行：改成你的域名或服务器IP
define('ENV_BASE_URL', 'http://localhost');
// 改为 ↓
define('ENV_BASE_URL', 'http://你的域名或IP');
// 例如：'http://192.168.1.100' 或 'http://dorm.example.com'
```

##### ② 数据库配置（使用第三步创建的信息）

**📄 文件：`web/config/env.php`**

```php
// 第 26 行：数据库主机（通常不用改）
define('ENV_DB_HOST', 'localhost');

// 第 27 行：数据库名（改成第三步创建的数据库名）
define('ENV_DB_NAME', 'your_database_name');
// 改为 ↓
define('ENV_DB_NAME', 'dormitory_system');

// 第 28 行：数据库用户名（改成第三步创建的用户名）
define('ENV_DB_USER', 'your_database_user');
// 改为 ↓
define('ENV_DB_USER', 'dorm_admin');

// 第 29 行：数据库密码（改成第三步设置的密码）
define('ENV_DB_PASS', 'your_database_password');
// 改为 ↓
define('ENV_DB_PASS', 'your_strong_password_here');
```

##### ③ 安全Token配置（必须生成新的）

**生成CSRF Token**：
```bash
# 在终端执行，会生成一串随机字符
openssl rand -hex 32
```

复制生成的字符串（例如：`a1b2c3d4e5f6...`），然后：

**📄 文件：`web/config/env.php`**

```php
// 第 35 行：粘贴刚才生成的Token
define('ENV_CSRF_TOKEN', 'PLEASE_GENERATE_YOUR_OWN_RANDOM_TOKEN_HERE');
// 改为 ↓
define('ENV_CSRF_TOKEN', 'a1b2c3d4e5f6...');  // 粘贴你生成的Token
```

**生成API Token**：
```bash
# 再执行一次，生成另一个Token
openssl rand -hex 32
```

复制生成的字符串，然后：

**📄 文件：`web/config/env.php`**

```php
// 第 49 行：粘贴刚才生成的另一个Token
define('ENV_API_TOKEN', 'PLEASE_GENERATE_YOUR_OWN_API_TOKEN_HERE');
// 改为 ↓
define('ENV_API_TOKEN', 'x9y8z7w6v5u4...');  // 粘贴你生成的另一个Token
```

**⚠️ 重要**：
- CSRF_TOKEN 和 API_TOKEN 必须不同
- API_TOKEN 需要同步配置到 ESP32 设备（如果使用硬件）

#### 4.4 保存配置文件

```bash
# 按 Ctrl+O 保存，Ctrl+X 退出（nano编辑器）
# 或 :wq 保存退出（vim编辑器）
```

---

### 第五步：安装PHP依赖

```bash
# 在 web 目录下执行
composer install

# 如果提示缺少扩展，安装对应的PHP扩展：
# sudo apt install php7.4-mbstring php7.4-xml php7.4-zip
```

---

### 第六步：设置文件权限

```bash
# 回到项目根目录
cd ..

# 设置基本权限
chmod 755 -R web/

# 设置上传、日志、缓存目录为可写
chmod 777 web/uploads/
chmod 777 web/logs/
chmod 777 web/cache/

# 设置所有者为Web服务器用户
# Apache用户：
sudo chown -R www-data:www-data web/

# 或 Nginx用户：
sudo chown -R nginx:nginx web/
```

---

### 第七步：配置Web服务器   （这个我建议直接去网上找宝塔配置的视频就可以啦）

> **💡 提示**：如果使用宝塔面板，请直接跳到 [方式C：宝塔面板配置](#方式c宝塔面板配置推荐)

#### 方式A：Apache配置

**创建虚拟主机配置文件**：

```bash
sudo nano /etc/apache2/sites-available/dormitory.conf
```

**粘贴以下内容**（修改路径为你的实际路径）：

**📄 文件：`/etc/apache2/sites-available/dormitory.conf`**

```apache
<VirtualHost *:80>
    # 改成你的域名（或注释掉）
    ServerName dorm.example.com
    
    # 改成项目的实际路径
    DocumentRoot /var/www/html/dormitory-system/web
    
    <Directory /var/www/html/dormitory-system/web>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/dormitory-error.log
    CustomLog ${APACHE_LOG_DIR}/dormitory-access.log combined
</VirtualHost>
```

**启用站点**：

```bash
# 启用站点
sudo a2ensite dormitory.conf

# 启用rewrite模块
sudo a2enmod rewrite

# 重启Apache
sudo systemctl restart apache2
```

#### 方式B：Nginx配置

**创建站点配置文件**：

```bash
sudo nano /etc/nginx/sites-available/dormitory
```

**粘贴以下内容**（修改路径为你的实际路径）：

**📄 文件：`/etc/nginx/sites-available/dormitory`**

```nginx
server {
    listen 80;
    
    # 改成你的域名（或改成服务器IP）
    server_name dorm.example.com;
    
    # 改成项目的实际路径
    root /var/www/html/dormitory-system/web;
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    location ~ /\.ht {
        deny all;
    }
}
```

**启用站点**：

```bash
# 创建软链接
sudo ln -s /etc/nginx/sites-available/dormitory /etc/nginx/sites-enabled/

# 测试配置
sudo nginx -t

# 重启Nginx
sudo systemctl restart nginx
```

#### 方式C：宝塔面板配置（推荐）

> **使用宝塔面板最简单，不需要手动编辑配置文件！**

##### C.1 进入宝塔面板

浏览器访问：`http://你的服务器IP:8888`

登录宝塔面板。

##### C.2 创建网站

1. 点击左侧菜单 **"网站"**
2. 点击 **"添加站点"**
3. 填写信息：
   - **域名**：填写你的域名（如 `dorm.example.com`）  
     如果没有域名，填写服务器IP地址（如 `192.168.1.100`）
   - **根目录**：点击选择或输入：`/var/www/html/dormitory-system/web`  
     ⚠️ 必须指向项目的 `web` 目录，不是项目根目录！
   - **数据库**：选择 "不创建"（我们已经手动创建了）
   - **PHP版本**：选择 `PHP-74` 或更高版本
   - **备注**：宿舍管理系统

4. 点击 **"提交"**

##### C.3 配置PHP

1. 在网站列表中，找到刚才创建的站点
2. 点击 **"设置"**
3. 左侧选择 **"PHP"**
4. 启用以下函数（如果被禁用）：
   - `exec`
   - `shell_exec`
   - `proc_open`
5. 上传限制：
   - `upload_max_filesize` 改为 `20M`
   - `post_max_size` 改为 `20M`
   - `max_execution_time` 改为 `300`

##### C.4 设置伪静态（重要！）

1. 在网站设置中，左侧选择 **"伪静态"**
2. 选择 **"thinkphp"** 或手动输入：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

3. 点击 **"保存"**

##### C.5 设置目录权限

1. 在网站设置中，左侧选择 **"网站目录"**
2. 找到 **"运行目录"**，确认为：`/`（根目录）
3. 勾选 **"防跨站攻击"**

返回宝塔文件管理：

1. 进入 `/var/www/html/dormitory-system/web/`
2. 右键点击 `uploads` 文件夹，选择 **"权限"**，改为 `777`
3. 右键点击 `logs` 文件夹，选择 **"权限"**，改为 `777`
4. 右键点击 `cache` 文件夹，选择 **"权限"**，改为 `777`

##### C.6 重启PHP（可选）

在宝塔面板左侧菜单：
1. 点击 **"软件商店"**
2. 找到 **"PHP 7.4"**
3. 点击 **"重载配置"** 或 **"重启"**

##### C.7 配置SSL（可选，推荐）

如果有域名，建议启用HTTPS：

1. 在网站设置中，左侧选择 **"SSL"**
2. 选择 **"Let's Encrypt"** 或上传证书
3. 勾选 **"强制HTTPS"**
4. 点击 **"申请"** 或 **"部署"**

---

### 第八步：访问系统并登录

#### 8.1 浏览器访问

```
http://你的域名或IP
```

例如：
- `http://192.168.1.100`
- `http://dorm.example.com`

#### 8.2 使用默认账号登录

```
用户名：admin
密码：admin123
```

#### 8.3 ⚠️ 立即修改密码

登录后：
1. 点击右上角头像
2. 选择"修改密码"
3. 修改为强密码（至少8位，包含字母和数字）

---

### 第九步：ESP32 硬件配置（可选）

> 如果不使用指纹识别硬件，可以跳过此步骤

#### 9.1 打开主程序文件

```bash
# 使用Arduino IDE或其他编辑器打开
nano esp32/squareline_port/squareline_port.ino
# 或使用Arduino IDE: 文件 → 打开 → 选择 squareline_port.ino
```




# ESP32 配置部分需要手动修正

## 问题位置

`README.md` 第 487-516 行（第9.3节）

## 当前内容（错误）

```markdown
#### 9.3 修改配置参数

**📄 文件：`esp32/esp32_config.h`**（可以不用，我直接都写在主程序里了）

```cpp
// 改成你的WiFi名称
const char* ssid = "YOUR_WIFI_SSID";（固定WIFI名字和密码的时候使用）
...
```
```

## 应该改成（正确）

```markdown
#### 9.2 修改配置参数

**📄 文件：`esp32/squareline_port/squareline_port.ino`**

> **💡 说明**：新版本已将配置直接写在主程序中，不再使用 `esp32_config.h` 文件

打开 `esp32/squareline_port/squareline_port.ino`，找到第 **49-52** 行，修改以下参数：

```cpp
// ==================== 第 49-52 行：服务器配置 ====================

// 第 50 行：改成你的服务器签到接口地址
const char* SERVER_URL = "http://YOUR_SERVER_IP/api/checkin.php";
// 改为 ↓
const char* SERVER_URL = "http://你的域名或IP/api/checkin.php";
// 例如："http://192.168.1.100/api/checkin.php"

// 第 51 行：改成第四步生成的API_TOKEN（必须完全一致！）
const char* API_TOKEN = "YOUR_API_TOKEN_HERE";
// 改为 ↓
const char* API_TOKEN = "x9y8z7w6v5u4...";  // 与 web/config/env.php 中的 ENV_API_TOKEN 完全一致

// 第 52 行：改成设备ID（格式：FP001-楼号-设备序号）
const char* DEVICE_ID = "FP001-10-2";
// 改为 ↓
const char* DEVICE_ID = "FP001-1-1";  // 例如：1号楼第1台设备
```

**⚠️ WiFi配置说明**：
- **新版本已改为界面动态配置**，设备启动后会显示WiFi扫描列表
- 用户可以在触摸屏上选择WiFi并输入密码
- **不需要在代码中硬编码WiFi信息**
- 如果你确实需要固定WiFi（不推荐），可以取消注释第 45-47 行：
  ```cpp
  // 取消注释并修改为你的WiFi
  const char* WIFI_SSID = "你的WiFi名称";
  const char* WIFI_PASSWORD = "你的WiFi密码";
  ```
```




#### 9.2 上传代码到ESP32

1. 打开 Arduino IDE
2. 打开 `esp32/squareline_port/squareline_port.ino`（已修改配置的文件）
3. 选择开发板：工具 → 开发板 → ESP32 Arduino → ESP32S3 Dev Module
4. 选择端口：工具 → 端口 → COMX（根据实际情况）
5. 点击"上传"按钮（→ 图标）
6. 等待编译和上传完成

**💡 首次使用**：
1. 设备启动后会自动进入WiFi配置界面
2. 从列表中选择你的WiFi
3. 输入密码并连接
4. 连接成功后，WiFi信息会自动保存

#### 9.3 在Web端注册设备

1. 登录管理后台
2. 导航菜单 → 设备管理
3. 点击"添加设备"
4. 填写设备信息（设备ID格式：`FP001-1-1`，表示第1号楼第1台设备）

---

## ✅ 部署完成检查清单

部署完成后，逐项检查：

- [ ] 能正常访问登录页面
- [ ] 能使用默认账号登录
- [ ] 已修改默认密码
- [ ] 能查看学生列表（虽然是空的）
- [ ] 能添加测试学生
- [ ] 能进行手动签到
- [ ] 上传目录可写（测试导入学生）
- [ ] 日志目录可写（查看 `web/logs/error.log`）

---

## 📝 配置文件总结

### 必须修改的配置文件

| 文件 | 必须改的参数 | 说明 |
|------|-------------|------|
| `web/config/env.php` | ENV_BASE_URL | 你的域名或IP |
| ↑ | ENV_DB_NAME | 数据库名 |
| ↑ | ENV_DB_USER | 数据库用户名 |
| ↑ | ENV_DB_PASS | 数据库密码 |
| ↑ | ENV_CSRF_TOKEN | 生成的随机Token |
| ↑ | ENV_API_TOKEN | 生成的另一个随机Token |
| `esp32/squareline_port/squareline_port.ino` | SERVER_URL | 服务器签到接口地址 |
| ↑ | API_TOKEN | 与env.php中的ENV_API_TOKEN一致 |
| ↑ | DEVICE_ID | 设备ID（格式：FP001-楼号-序号） |
| ↑ | WiFi配置 | 通过触摸屏界面配置（无需硬编码） |

### 宝塔面板配置要点

| 配置项 | 设置值 | 说明 |
|--------|--------|------|
| 网站根目录 | `/path/to/dormitory-system/web` | 必须指向web目录 |
| PHP版本 | 7.4 或更高 | 必需 |
| 上传限制 | 20M | 支持批量导入 |
| 伪静态 | thinkphp | 或自定义规则 |
| 目录权限 | uploads/logs/cache 设为 777 | 可写 |

---

## ❓ 常见部署问题

### 问题1：访问页面显示空白

**原因**：PHP错误  
**解决**：
```bash
# 查看错误日志
tail -f web/logs/error.log
# 或
tail -f /var/log/apache2/error.log  # Apache
tail -f /var/log/nginx/error.log    # Nginx
```

### 问题2：无法连接数据库

**原因**：数据库配置错误  
**解决**：
1. 检查 `web/config/env.php` 中的数据库配置
2. 测试数据库连接：
```bash
mysql -u dorm_admin -p dormitory_system
```

### 问题3：文件上传失败

**原因**：目录权限不足  
**解决**：
```bash
chmod 777 web/uploads/
ls -la web/ | grep uploads  # 查看权限
```

### 问题4：ESP32连接失败

**原因**：API Token不一致  
**解决**：
1. 检查 `web/config/env.php` 中的 `ENV_API_TOKEN`
2. 检查 `esp32/esp32_config.h` 中的 `apiToken`
3. 确保两者完全一致（包括大小写）

### 问题5：登录后跳转到登录页

**原因**：Session配置问题  
**解决**：
```bash
# 检查session目录权限
ls -la /var/lib/php/sessions/

# 或创建自定义session目录
mkdir -p web/sessions
chmod 777 web/sessions
```

### 问题6：宝塔面板访问显示404

**原因**：网站根目录配置错误  
**解决**：
1. 检查网站根目录是否指向 `项目/web` 目录（不是项目根目录）
2. 检查伪静态规则是否配置
3. 检查PHP版本是否 >= 7.4

### 问题7：宝塔面板文件上传失败

**原因**：PHP上传限制  
**解决**：
1. 网站设置 → PHP → 上传限制改为 20M
2. 宝塔面板 → 软件商店 → PHP 7.4 → 配置修改
3. 找到 `upload_max_filesize` 和 `post_max_size` 改为 `20M`
4. 重载PHP配置

---

## 📖 下一步

部署完成后：

1. 📚 阅读 [用户使用手册](USER_GUIDE.md) 了解如何使用系统
2. 📋 查看 [TODO.md](TODO.md) 了解后续开发计划
3. 💾 设置定时备份（参考 `web/crontab.example`）

---

## 🔒 安全建议

1. ✅ 定期备份数据库
2. ✅ 启用 HTTPS（生产环境必须）
3. ✅ 定期更新系统和PHP
4. ✅ 限制文件上传大小
5. ✅ 监控日志文件

---

## 🤝 需要帮助？

- 📖 查看 [详细安装文档](INSTALL.md)
- 📝 查看 [用户手册](USER_GUIDE.md)
- 🐛 提交 [Issue](https://github.com/sun9545/dormitory-system/issues)

---

## 📄 开源协议

本项目采用 [MIT License](LICENSE) 开源协议。

---

<p align="center">
  Made by T-SUN
</p>
