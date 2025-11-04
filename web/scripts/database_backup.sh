#!/bin/bash
# ============================================
# 学生查寝系统 - 数据库自动备份脚本
# ============================================

# ⚠️ 配置信息 - 请根据实际情况修改
DB_USER="your_database_user"           # ⚠️ 修改为您的数据库用户名
DB_PASS="your_database_password"       # ⚠️ 修改为您的数据库密码
DB_NAME="your_database_name"           # ⚠️ 修改为您的数据库名
BACKUP_DIR="/path/to/backup/database"  # ⚠️ 修改为您的备份目录路径
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_${DATE}.sql"
LOG_FILE="$BACKUP_DIR/backup.log"

# 创建备份目录
mkdir -p $BACKUP_DIR

# 开始备份
echo "[$(date '+%Y-%m-%d %H:%M:%S')] 开始备份数据库..." >> $LOG_FILE

# 执行mysqldump备份
mysqldump -u$DB_USER -p$DB_PASS \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    $DB_NAME > $BACKUP_FILE 2>> $LOG_FILE

# 检查备份是否成功
if [ $? -eq 0 ]; then
    # 压缩备份文件
    gzip $BACKUP_FILE
    
    # 设置权限，让www用户可以读取
    chmod 644 "${BACKUP_FILE}.gz"
    chmod 644 "$LOG_FILE"
    
    # 计算文件大小
    FILE_SIZE=$(du -h "${BACKUP_FILE}.gz" | cut -f1)
    
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✅ 备份成功: ${BACKUP_FILE}.gz (大小: $FILE_SIZE)" >> $LOG_FILE
    
    # 删除7天前的备份（保留最近7天）
    find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] 已清理7天前的旧备份" >> $LOG_FILE
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ❌ 备份失败！" >> $LOG_FILE
    exit 1
fi

# 显示当前备份文件数量
BACKUP_COUNT=$(ls -1 $BACKUP_DIR/*.sql.gz 2>/dev/null | wc -l)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] 当前共有 $BACKUP_COUNT 个备份文件" >> $LOG_FILE
echo "----------------------------------------" >> $LOG_FILE

