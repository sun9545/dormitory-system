# 数据库自动备份系统 - 使用说明

## 📋 系统概述

已为你的学生查寝系统配置了**全自动数据库备份系统**，确保数据安全。

---

## ✅ 已完成的配置

### 1. 备份脚本
- **位置：** `/www/wwwroot/localhost/scripts/database_backup.sh`
- **功能：** 自动导出数据库、压缩、保留7天备份

### 2. 定时任务（Cron）
- **执行时间：** 每天凌晨 **03:00**
- **自动运行：** 无需人工干预
- **查看命令：** `crontab -l`

### 3. 备份存储
- **目录：** `/www/backup/database/student_dorm/`
- **格式：** `student_dorm_sql_YYYYMMDD_HHMMSS.sql.gz`
- **保留期：** 最近 **7天**（自动清理旧备份）

### 4. Web管理界面
- **访问地址：** `http://你的IP/backup_management.php`
- **权限：** 仅管理员可访问
- **功能：** 查看、下载、删除备份文件

---

## 🎯 你需要做什么？

### 答案：**什么都不需要做！**

这是一个**全自动**系统：
- ✅ 每天凌晨3点自动备份
- ✅ 自动压缩节省空间
- ✅ 自动清理7天前的旧备份
- ✅ 自动记录日志

**你只需要：**
1. 偶尔登录Web界面查看备份是否正常（可选）
2. 如果需要恢复数据时，下载备份文件

---

## 📊 备份状态查看

### 方法1：Web界面（推荐）
1. 登录系统（管理员账号）
2. 点击左侧菜单 **"数据库备份"**
3. 查看备份列表、日志

### 方法2：命令行
```bash
# 查看备份文件
ls -lh /www/backup/database/student_dorm/

# 查看备份日志
tail -20 /www/backup/database/student_dorm/backup.log

# 查看Cron定时任务
crontab -l
```

---

## 🔄 如何恢复备份？

### 场景1：通过Web界面下载

1. 登录 → **数据库备份** 页面
2. 找到需要恢复的备份文件
3. 点击 **"下载"** 按钮
4. 解压文件：`gunzip student_dorm_sql_20251012_181457.sql.gz`
5. 恢复数据库：
   ```bash
   mysql -u Student_Dorm_Sql -pYOUR_DATABASE_PASSWORD student_dorm_sql < student_dorm_sql_20251012_181457.sql
   ```

### 场景2：直接命令行恢复

```bash
# 1. 进入备份目录
cd /www/backup/database/student_dorm/

# 2. 查看备份文件
ls -lh

# 3. 解压备份（假设文件名为 student_dorm_sql_20251012_181457.sql.gz）
gunzip -c student_dorm_sql_20251012_181457.sql.gz > /tmp/restore.sql

# 4. 恢复数据库（⚠️ 会覆盖现有数据！）
mysql -u Student_Dorm_Sql -pYOUR_DATABASE_PASSWORD student_dorm_sql < /tmp/restore.sql

# 5. 清理临时文件
rm /tmp/restore.sql
```

**⚠️ 警告：** 恢复操作会覆盖当前数据库，请谨慎操作！

---

## 🛠️ 手动执行备份

如果需要**立即**备份（不等到凌晨3点）：

```bash
# 方法1：直接执行脚本
/www/wwwroot/localhost/scripts/database_backup.sh

# 方法2：查看备份结果
ls -lh /www/backup/database/student_dorm/
tail -10 /www/backup/database/student_dorm/backup.log
```

---

## 📝 备份文件命名规则

**格式：** `student_dorm_sql_YYYYMMDD_HHMMSS.sql.gz`

**示例：**
- `student_dorm_sql_20251012_181457.sql.gz`
  - 2025年10月12日
  - 18:14:57 备份

---

## 🔍 常见问题

### Q1：备份会影响系统性能吗？
**A：** 不会。备份在凌晨3点执行，使用用户很少，且使用 `--single-transaction` 不锁表。

### Q2：备份文件占用空间大吗？
**A：** 目前约 **356KB**（压缩后）。即使保留7天，总共也不到 **3MB**。

### Q3：如果备份失败了怎么办？
**A：** 
1. 查看日志：`tail -50 /www/backup/database/student_dorm/backup.log`
2. 手动执行脚本测试：`/www/wwwroot/localhost/scripts/database_backup.sh`
3. 如果有错误，联系我帮你排查

### Q4：我可以修改备份时间吗？
**A：** 可以。编辑Cron任务：
```bash
crontab -e

# 修改这一行的时间（格式：分 时 日 月 周）
# 0 3 * * *  → 每天03:00
# 0 2 * * *  → 改为每天02:00
# 0 4 * * *  → 改为每天04:00
```

### Q5：我可以保留更多天的备份吗？
**A：** 可以。修改脚本中的这一行：
```bash
# 编辑脚本
vim /www/wwwroot/localhost/scripts/database_backup.sh

# 找到这一行：
find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete

# 修改 +7 为你想要的天数，如 +14（保留14天）
find $BACKUP_DIR -name "*.sql.gz" -mtime +14 -delete
```

### Q6：备份会包含哪些内容？
**A：** 备份包含完整的数据库：
- ✅ 所有表（students, check_records, users, 等）
- ✅ 所有数据（学生信息、查寝记录、请假记录、等）
- ✅ 触发器、存储过程、事件（如果有）
- ❌ 不包含：上传的文件（CSV等，需单独备份）

---

## 📞 技术支持

如果遇到问题，请检查：
1. **备份日志：** `/www/backup/database/student_dorm/backup.log`
2. **Cron日志：** `/var/log/cron`（如果有）
3. **磁盘空间：** `df -h`

需要帮助时，提供以上日志内容。

---

## ✅ 验证清单

请确认以下项目：
- [x] 备份脚本已创建并可执行
- [x] Cron定时任务已添加
- [x] 备份目录已创建
- [x] 已成功执行过一次备份测试
- [x] Web管理界面可访问
- [x] 导航菜单已添加"数据库备份"入口

---

**🎉 恭喜！你的数据库现在有了自动保护！**

从明天凌晨3点开始，系统会每天自动备份，你再也不用担心数据丢失了！

