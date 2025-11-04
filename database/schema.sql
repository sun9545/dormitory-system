/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `check_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `check_time` datetime NOT NULL,
  `status` enum('在寝','离寝','请假','未签到') NOT NULL DEFAULT '未签到',
  `device_id` varchar(50) DEFAULT NULL COMMENT '签到设备ID',
  PRIMARY KEY (`id`),
  KEY `idx_check_time` (`check_time`),
  KEY `idx_status` (`status`),
  KEY `idx_student_check_time` (`student_id`,`check_time`),
  KEY `idx_check_records_student_time` (`student_id`,`check_time`),
  KEY `idx_check_records_time_status` (`check_time`,`status`),
  KEY `idx_check_records_status` (`status`),
  KEY `idx_check_records_device` (`device_id`),
  KEY `idx_student_time` (`student_id`,`check_time`),
  KEY `idx_check_date` (`check_time`),
  CONSTRAINT `check_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=35813 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT '设备ID',
  `log_level` enum('DEBUG','INFO','WARN','ERROR') NOT NULL DEFAULT 'INFO' COMMENT '日志级别',
  `component` varchar(50) NOT NULL DEFAULT 'system' COMMENT '组件名称',
  `message` text NOT NULL COMMENT '日志消息',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP地址',
  `memory_free` int(11) DEFAULT NULL COMMENT '可用内存(字节)',
  `uptime` bigint(20) DEFAULT NULL COMMENT '运行时间(毫秒)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_log_level` (`log_level`),
  KEY `idx_component` (`component`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_device_date` (`device_id`,`created_at`),
  KEY `idx_level_component` (`log_level`,`component`)
) ENGINE=InnoDB AUTO_INCREMENT=39938 DEFAULT CHARSET=utf8mb4 COMMENT='设备日志记录表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT '设备编号 如:xxxx-10-1',
  `device_name` varchar(100) NOT NULL COMMENT '设备名称',
  `building_number` int(11) NOT NULL COMMENT '楼号',
  `device_sequence` int(11) NOT NULL COMMENT '设备序号(该楼第几台)',
  `location` varchar(200) DEFAULT NULL COMMENT '设备位置描述',
  `status` enum('active','inactive','maintenance') DEFAULT 'active' COMMENT '设备状态',
  `max_fingerprints` int(11) DEFAULT '1000' COMMENT '最大指纹存储数量',
  `current_fingerprints` int(11) DEFAULT '0' COMMENT '当前已存储指纹数量',
  `last_seen` datetime DEFAULT NULL COMMENT '最后在线时间',
  `ip_address` varchar(45) DEFAULT NULL COMMENT '设备IP地址',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_id` (`device_id`),
  UNIQUE KEY `device_building_sequence` (`building_number`,`device_sequence`),
  KEY `idx_building` (`building_number`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COMMENT='指纹设备管理表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fingerprint_checkin_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL COMMENT '学生学号',
  `device_id` varchar(50) NOT NULL COMMENT '设备编号',
  `fingerprint_id` int(11) NOT NULL COMMENT '设备内指纹编号',
  `checkin_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签到时间',
  `checkin_type` enum('in','out') DEFAULT 'in' COMMENT '签到类型:进入/离开',
  `confidence` decimal(5,2) DEFAULT NULL COMMENT '识别置信度',
  `building_number` int(11) NOT NULL COMMENT '楼号',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_checkin_time` (`checkin_time`),
  KEY `idx_building` (`building_number`),
  KEY `idx_checkin_type` (`checkin_type`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COMMENT='指纹签到记录表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fingerprint_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL COMMENT '学生学号',
  `device_id` varchar(50) NOT NULL COMMENT '设备编号',
  `fingerprint_id` int(11) NOT NULL COMMENT '设备内指纹编号(0-999)',
  `finger_index` tinyint(4) DEFAULT NULL COMMENT '手指编号(1-10)',
  `enrollment_status` enum('pending','enrolled','failed') DEFAULT 'enrolled' COMMENT '录入状态',
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '录入时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_fingerprint_unique` (`device_id`,`fingerprint_id`),
  KEY `idx_student_id` (`student_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_enrollment_status` (`enrollment_status`),
  KEY `idx_device_fingerprint` (`device_id`,`fingerprint_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7516 DEFAULT CHARSET=utf8mb4 COMMENT='学生指纹映射表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL COMMENT '学号',
  `leave_dates` json NOT NULL COMMENT '请假日期列表（JSON格式）',
  `reason` text COMMENT '请假原因',
  `status` enum('pending','approved','rejected') DEFAULT 'pending' COMMENT '审批状态：待审批/已批准/已拒绝',
  `apply_time` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '申请时间',
  `apply_method` enum('student','counselor','admin') DEFAULT 'student' COMMENT '申请方式：学生/辅导员代录/管理员',
  `counselor_id` int(11) DEFAULT NULL COMMENT '审批辅导员ID',
  `review_time` datetime DEFAULT NULL COMMENT '审批时间',
  `review_note` text COMMENT '审批备注',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_submit_time` datetime DEFAULT NULL COMMENT '最后提交时间（防刷限制）',
  `submit_count` int(11) DEFAULT '0' COMMENT '1小时内提交次数',
  PRIMARY KEY (`id`),
  KEY `idx_student_date` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_apply_time` (`apply_time`),
  KEY `idx_counselor` (`counselor_id`),
  KEY `idx_last_submit` (`student_id`,`last_submit_time`),
  CONSTRAINT `leave_applications_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `leave_applications_ibfk_2` FOREIGN KEY (`counselor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=754 DEFAULT CHARSET=utf8mb4 COMMENT='学生请假申请表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_batch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_name` varchar(100) NOT NULL,
  `upload_time` datetime NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `effective_date` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  CONSTRAINT `leave_batch_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text,
  `approved_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `approved_by` (`approved_by`),
  KEY `student_id` (`student_id`),
  KEY `start_date` (`start_date`),
  KEY `end_date` (`end_date`),
  CONSTRAINT `leave_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `leave_records_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `login_time` datetime NOT NULL,
  `ip_address` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `login_time` (`login_time`)
) ENGINE=InnoDB AUTO_INCREMENT=1129 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `operation_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `operation_type` varchar(50) NOT NULL,
  `operation_desc` text NOT NULL,
  `ip_address` varchar(50) DEFAULT NULL COMMENT 'IP地址',
  `operation_time` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_operation_logs_user` (`user_id`),
  KEY `idx_operation_logs_time` (`operation_time`),
  KEY `idx_operation_logs_type` (`operation_type`),
  CONSTRAINT `operation_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5062 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COMMENT='用户密码历史记录';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `student_id` varchar(20) NOT NULL,
  `name` varchar(50) NOT NULL,
  `gender` enum('男','女') NOT NULL,
  `class_name` varchar(50) NOT NULL,
  `building` int(11) NOT NULL,
  `building_area` enum('A','B') NOT NULL,
  `building_floor` int(11) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `bed_number` int(11) NOT NULL,
  `counselor` varchar(50) NOT NULL,
  `counselor_phone` varchar(20) NOT NULL,
  PRIMARY KEY (`student_id`),
  KEY `idx_building` (`building`),
  KEY `idx_class_name` (`class_name`),
  KEY `idx_building_combined` (`building`,`building_area`,`building_floor`),
  KEY `idx_name` (`name`),
  KEY `idx_students_building` (`building`,`building_area`,`building_floor`),
  KEY `idx_students_class` (`class_name`),
  KEY `idx_students_counselor` (`counselor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','counselor') NOT NULL,
  `name` varchar(50) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_changed_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '密码最后修改时间',
  `password_expires_at` datetime DEFAULT NULL COMMENT '密码过期时间',
  `login_attempts` int(11) DEFAULT '0' COMMENT '登录尝试次数',
  `locked_until` datetime DEFAULT NULL COMMENT '账号锁定截止时间',
  `status` enum('active','inactive','locked') DEFAULT 'active' COMMENT '账号状态',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`),
  KEY `idx_role` (`role`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
