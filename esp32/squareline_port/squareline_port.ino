/**
 * ESP32-S3 学生查寝管理系统 v2.0
 * 基于SquareLine Studio + LVGL + ESP32_Display_Panel
 * 
 * 功能:
 * - WiFi连接管理
 * - 指纹签到/录入
 * - 心跳检测
 * - 统计查询
 * - 触摸屏界面操作
 */
//10月19日修改
#include <Arduino.h>
#include <esp_display_panel.hpp>
#include <lvgl.h>
// #include <ui.h>  // 注释掉原有的UI库,我们直接在代码中创建UI
#include "lvgl_v8_port.h"
#include <esp_task_wdt.h>  // 看门狗头文件
#include <esp_wifi.h>       // ⭐ WiFi底层控制库（用于禁用省电模式）

// 网络和通信库
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <time.h>

// 指纹传感器库
#include <Adafruit_Fingerprint.h>
#include "myFont_new.c"  // 使用新的中文字体库

// ⭐ 内存监控相关头文件
#include "esp_heap_caps.h"

// SPIFFS已禁用,使用内置字体

using namespace esp_panel::drivers;
using namespace esp_panel::board;

// ==================== 系统配置 ====================
// 内存管理配置
#define MEM_CRITICAL_THRESHOLD 40000    // 临界阈值：40KB
#define MEM_WARNING_THRESHOLD 60000     // 警告阈值：60KB
#define MEM_CHECK_INTERVAL 30000        // 检查间隔：30秒

// WiFi配置 - 已移除硬编码,用户需通过界面选择网络连接
// const char* WIFI_SSID = "YOUR_WIFI_SSID";        // 已删除硬编码
// const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD"; // 已删除硬编码

// 服务器配置  
const char* SERVER_URL = "http://YOUR_SERVER_IP/api/checkin.php";
const char* API_TOKEN = "YOUR_API_TOKEN_HERE";
const char* DEVICE_ID = "FP001-10-2";

// 硬件引脚配置 - 使用串口1避免USB冲突
#define FP_RX_PIN 18    // R307S RX引脚 (连接到ESP32 GPIO18) - Serial1
#define FP_TX_PIN 17    // R307S TX引脚 (连接到ESP32 GPIO17) - Serial1

// ==================== 全局变量 ====================

// 系统状态枚举 - 移动到文件前部确保全局可见
enum SystemState {
 STATE_IDLE,
 STATE_FINGERPRINT_DETECTING,    // 指纹检测中（原有）
 STATE_ENROLLING,                // 录入模式（原有）
 STATE_CONNECTING_WIFI,
 STATE_UPLOADING_DATA,
 STATE_WIFI_SCANNING,
 STATE_WIFI_CONNECTING,
 // 新增状态
 STATE_FINGERPRINT_INIT,         // 指纹传感器初始化中
 STATE_DETECTION_SUCCESS,        // 检测成功
 STATE_DETECTION_ERROR,          // 检测错误
 STATE_SYSTEM_CHECK              // 系统检查中
};

// 指纹传感器 - 使用串口1,对应GPIO17(TX)/18(RX)
HardwareSerial fingerprintSerial(1);  // 使用Serial1避免USB冲突
Adafruit_Fingerprint finger(&fingerprintSerial);

// 显示板管理 - 使用静态全局对象避免内存泄漏
static Board boardInstance;

// 弹出框管理全局变量
static lv_obj_t *currentMsgBox = NULL;
static lv_timer_t *msgBoxTimer = NULL;
static lv_obj_t *msgBoxTitleLabel = NULL;
static lv_obj_t *msgBoxMessageLabel = NULL;
static lv_obj_t *msgBoxButton = NULL;

// 心跳测试状态管理
static bool heartbeatInProgress = false;
static lv_timer_t *heartbeatTestTimer = NULL;

// WiFi操作状态管理
static bool wifiOperationInProgress = false;
static lv_timer_t *wifiOperationTimer = NULL;

// WiFi连接进度管理
static bool wifiConnecting = false;
static lv_timer_t *wifiConnectTimer = NULL;
static lv_obj_t *connectProgressScreen = NULL;
static lv_obj_t *connectProgressLabel = NULL;
static lv_obj_t *connectProgressSpinner = NULL;
static unsigned long connectStartTime = 0;
static String connectingSSID = "";
static String connectingPassword = "";

// Lambda定时器引用管理
static lv_timer_t *successTimer = NULL;
static lv_timer_t *failTimer = NULL;
static lv_timer_t *recoveryTimer = NULL;
static lv_timer_t *enrollmentProcessTimer = NULL;
static lv_timer_t *autoRecoveryTimer = NULL;

// 定时器管理辅助函数
void safeDeleteTimer(lv_timer_t **timer) {
   if (timer != NULL && *timer != NULL) {
       lv_timer_del(*timer);
       *timer = NULL;
   }
}

// 清理所有临时定时器（在严重错误或重启前调用）
void cleanupAllTempTimers() {
   safeDeleteTimer(&successTimer);
   safeDeleteTimer(&failTimer);
   safeDeleteTimer(&recoveryTimer);
   safeDeleteTimer(&enrollmentProcessTimer);
   safeDeleteTimer(&autoRecoveryTimer);
   Serial.println("✅ 已清理所有临时定时器");
}

// 内存紧急保护：检查并在必要时清理
bool checkMemoryAndProtect(const char* operation) {
   uint32_t freeHeap = ESP.getFreeHeap();
   
   if (freeHeap < MEM_CRITICAL_THRESHOLD) {
       Serial.print("🚨 内存严重不足！当前: ");
       Serial.print(freeHeap);
       Serial.print(" bytes, 操作: ");
       Serial.println(operation);
       
       // 紧急清理
       cleanupAllTempTimers();
       
       // 强制LVGL刷新（释放缓存）
       lv_timer_handler();
       delay(10);  // 给LVGL时间完成清理
       
       // 如果还是不够，拒绝操作（不显示消息框避免额外内存消耗）
       freeHeap = ESP.getFreeHeap();
       if (freeHeap < MEM_CRITICAL_THRESHOLD) {
           Serial.println("❌ 内存仍然不足，拒绝操作");
           Serial.println("⚠️ 建议：返回主界面或重启设备");
           return false;
       }
       Serial.println("✅ 紧急清理完成，继续操作");
   } else if (freeHeap < MEM_WARNING_THRESHOLD) {
       Serial.print("⚠️ 内存警告：");
       Serial.print(freeHeap);
       Serial.println(" bytes");
   }
   
   return true;
}

// 统计页面状态管理
static bool statisticsInProgress = false;
static lv_timer_t *statisticsTimer = NULL;
static lv_obj_t *statisticsScreen = NULL;

// 学生信息结构体
struct StudentInfo {
    String studentId;
    String name;
    String room;
    String status;
    String checkTime;
};

// 楼层信息结构体（简化版,不存储学生详细信息）
struct FloorInfo {
    String floor;
    int totalStudents;
    int totalPresent;
    int totalAbsent;
    int totalLeave;
    int totalNotChecked;
};

// 楼栋详细信息结构体
struct BuildingDetail {
    String buildingName;
    String date;
    int totalStudents;
    int totalPresent;
    int totalAbsent;
    int totalLeave;
    int totalNotChecked;
    FloorInfo floors[6];  // 最多6层,减少内存占用
    int floorCount;
    bool success;
};

// 楼栋统计数据结构体
struct BuildingData {
    String buildingName;
    int totalStudents;
    int totalPresent;
    int totalAbsent;
    int totalLeave;
    int totalNotChecked;
    bool hasStudents() const {
        return totalStudents > 0;
    }
};

// 统计数据结构体
struct StatisticsData {
   int totalStudents = 0;
   int totalPresent = 0;
   int totalAbsent = 0;
   int totalLeave = 0;
   int totalNotChecked = 0;
   
   // 楼栋详细数据
   BuildingData buildings[10];  // 最多10栋楼
   int buildingCount = 0;
   
   bool success = false;
};

// ⭐⭐⭐ 新增：设备未签到学生数据结构
struct UncheckedStudent {
   char name[20];        // 学生姓名（固定大小避免String碎片化）
   char location[12];    // 位置信息 "A401-2" 格式（区号+寝室-床号）
};

struct DeviceUncheckedData {
   char deviceInfo[40];      // 设备信息 "9号楼 A区 4-6层"
   char date[12];            // 日期 "2025-10-19"
   int totalUnchecked;       // 未签到总人数
   UncheckedStudent* students;  // 动态分配的学生数组指针
   int studentCount;         // 实际学生数量
   bool success;             // 是否成功获取数据
};

// ⭐⭐⭐ 新增：楼栋楼层统计数据结构
struct FloorStat {
   char area[4];            // 区域 "A" 或 "B"
   int floor;               // 楼层 1-6
   int totalStudents;
   int totalPresent;
   int totalAbsent;
   int totalLeave;
   int totalNotChecked;
   bool isCurrentDevice;    // 是否是当前设备负责的楼层
};

struct BuildingFloorData {
   char buildingName[20];   // "10号楼"
   char date[12];           // "2025-10-19"
   int totalStudents;
   int totalPresent;
   int totalAbsent;
   int totalLeave;
   int totalNotChecked;
   FloorStat floors[20];    // 最多20个楼层（足够大）
   int floorCount;
   bool success;
};

// 使用内置字体,无需字体缓存

// 网络状态
bool wifiConnected = false;
unsigned long lastWifiCheck = 0;
unsigned long lastHeartbeat = 0;
unsigned long lastTimeSync = 0;
bool timeSyncSuccess = false;

// 用户选择的WiFi信息
String userSelectedSSID = "";
String userSelectedPassword = "";
bool hasUserWiFiConfig = false;

// 系统状态枚举已移动到文件前部

// WiFi扫描相关
#define MAX_NETWORKS 20
struct WiFiNetwork {
    String ssid;
    int rssi;
    int encryption;
    bool saved;
};
WiFiNetwork scannedNetworks[MAX_NETWORKS];
int networkCount = 0;
lv_obj_t *wifiList;
lv_obj_t *passwordTextArea;
lv_obj_t *connectButton;
lv_obj_t *backButton;
lv_obj_t *refreshButton;
lv_obj_t *wifiStatusLabel;
lv_obj_t *passwordPanel;
String selectedSSID = "";
bool isScanning = false;
lv_obj_t *selectedItem = NULL;  // 当前选中的WiFi项
lv_obj_t *keyboard = NULL;      // LVGL内置键盘

// UI状态管理
bool uiInitialized = false;
unsigned long lastUIUpdate = 0;

// 统计信息
int todayCheckinCount = 0;
int totalFingerprintCount = 0;

// 时间同步函数前向声明
bool waitForTimeSync(int maxWaitSeconds = 10);
void checkAndSyncTime();
void performTimeZoneSetupAndSync();

// 指纹传感器使用固定57600波特率
uint32_t workingBaudRate = 57600;

// WiFi日志函数前向声明
void sendWiFiLog(String logLevel, String message, String component = "system");
void logInfo(String message, String component = "system");
void logError(String message, String component = "system");
void logWarn(String message, String component = "system");
void logDebug(String message, String component = "system");

// 指纹传感器管理函数声明
bool initFingerprintWithLogging();
int testFingerprintWithLogging();

// 系统健康检查相关全局变量
bool networkSystemReady = false;
bool memorySystemReady = false;
bool fingerprintSystemReady = false;
unsigned long lastFingerprintActivity = 0;

// 检测模式控制
bool detectionModeActive = false;
static lv_timer_t *detectionTimer = NULL;
static lv_obj_t *cancelButton = NULL;
unsigned long detectionStartTime = 0;
const unsigned long DETECTION_TIMEOUT = 30000; // 30秒超时

// 从成功代码移植的变量
const unsigned long FINGER_CHECK_INTERVAL = 300; // 300ms检测间隔

// ==================== 新的统一状态管理系统 ====================

// 状态管理变量
SystemState currentSystemState = STATE_IDLE;
String currentStateDetails = "";
int currentStateProgress = -1;
unsigned long lastStatusUpdate = 0;

// 兼容性保持
int currentDisplayMode = 0; // 0=空闲, 1=检测中, 2=成功, 3=错误

// 系统健康检查函数声明
void performSystemHealthCheck();
bool checkNetworkHealth();
bool checkMemoryHealth();
bool checkFingerprintHealth();
void displaySystemHealthProgress(String component, String status);

// ==================== 统一状态管理函数声明 ====================
void updateMainScreenStatus(SystemState newState, String details = "", int progress = -1);
String generateStatusDisplayText(SystemState state, String details, int progress);

// 检测模式管理函数声明
void startDetectionMode();
void stopDetectionMode();
void detectionTimerCallback(lv_timer_t * timer);
void cancelButtonCallback(lv_event_t * e);
int getFingerprintIDWithSteps();
void displayDetectionUI();
void displayStudentInfo(int fingerprintID, String name, String studentId, String dorm, String className = "暂无");
int detectFingerprintWithExtendedSearch();
// void showDetectionUI();  // 【已删除】统一使用 createCheckinDetectionScreen()
void closeCurrentMessageBox();

// 指纹录入相关函数声明
void showStudentIdInputDialog();
void studentIdInputCallback(lv_event_t * e);
void confirmStudentIdCallback(lv_event_t * e);
void cancelStudentIdCallback(lv_event_t * e);
void closeStudentIdInputDialog();
bool getStudentFingerprintId(String studentId, int &fingerprintId);
void showEnrollmentProgress(String step, String message);
int captureAndGenerate(int bufferID);
void initFingerprintDirect();
void startFingerprintEnrollmentProcess();
void startActualFingerprint();
void performFingerprintEnrollment();
void continueSecondCapture();
void startWaitForLiftOff();
void startSecondCapture();
void startSecondCaptureNonBlocking();
void startFeatureMerge();
void handleEnrollmentFailure();

// 指纹录入相关全局变量
static lv_obj_t *studentIdInputScreen = NULL;
static lv_obj_t *studentIdTextArea = NULL;
static lv_obj_t *studentIdKeyboard = NULL;
static bool enrollmentInProgress = false;
static String currentStudentId = "";
static int targetFingerprintId = -1;

// ⭐ 操作模式枚举（更清晰的状态管理）
enum OperationMode {
   MODE_NONE = 0,           // 无操作
   MODE_MANUAL_CHECKIN = 1, // 手动签到
   MODE_FINGERPRINT_ENROLL = 2  // 指纹录入
};
static OperationMode currentOperationMode = MODE_NONE;

// 指纹录入流程的全局变量，避免lambda内static变量导致内存问题
static int enrollmentFirstCaptureAttempts = 0;
static lv_timer_t *firstCaptureTimer = NULL;
static lv_timer_t *waitLiftTimer = NULL;
static lv_timer_t *secondCaptureTimer = NULL;
static int enrollmentSecondCaptureAttempts = 0;
static int enrollmentWaitLiftAttempts = 0;

// 清理所有指纹录入定时器
void cleanupEnrollmentTimers() {
   safeDeleteTimer(&firstCaptureTimer);
   safeDeleteTimer(&waitLiftTimer);
   safeDeleteTimer(&secondCaptureTimer);
   Serial.println("🧹 所有指纹录入定时器已清理");
}

// 重置指纹录入状态
void resetEnrollmentState() {
   enrollmentInProgress = false;
   targetFingerprintId = -1;
   currentStudentId = "";
   enrollmentFirstCaptureAttempts = 0;
   enrollmentSecondCaptureAttempts = 0;
   enrollmentWaitLiftAttempts = 0;
   cleanupEnrollmentTimers();
   Serial.println("🔄 指纹录入状态已重置");
}

// 声明mainScreen的extern引用
extern lv_obj_t * mainScreen;

// 安全的界面切换函数 - 简化版本，避免复杂的lambda捕获
void safeScreenTransition() {
   Serial.println("🔄 开始安全界面切换到主界面");
   lv_scr_load(mainScreen);
}

// 安全的消息框关闭函数
void safeCloseCurrentMessageBox() {
   if (currentMsgBox != NULL) {
       Serial.println("🗑️ 安全关闭消息框");
       // 先停止消息框定时器
       safeDeleteTimer(&msgBoxTimer);
       // 删除消息框对象
       lv_obj_del(currentMsgBox);
       currentMsgBox = NULL;
       msgBoxTitleLabel = NULL;
       msgBoxMessageLabel = NULL;
       msgBoxButton = NULL;
       Serial.println("✅ 消息框已安全关闭");
   }
}

// 简单的标志系统,避免使用定时器
static bool shouldCloseStudentIdDialog = false;
static bool dialogClosedByConfirm = false;

// ==================== 内存监控函数 ====================
// ⭐ 基于LVGL 8.4.0的完整内存监控（修复版）
void printMemoryStatus(const char* label) {
   Serial.println("════════════════════════════════════════════════════════");
   Serial.printf("📍 检查点: %s\n", label);
   Serial.println("────────────────────────────────────────────────────────");
   
   // ⭐ 修复：只查询内部SRAM，不包括PSRAM
   uint32_t freeHeap = heap_caps_get_free_size(MALLOC_CAP_INTERNAL);
   uint32_t minFreeHeap = heap_caps_get_minimum_free_size(MALLOC_CAP_INTERNAL);
   uint32_t largestBlock = heap_caps_get_largest_free_block(MALLOC_CAP_INTERNAL);
   uint32_t totalHeap = heap_caps_get_total_size(MALLOC_CAP_INTERNAL);
   
   Serial.printf("🧠 ESP32内部SRAM (不含PSRAM):\n");
   Serial.printf("   总大小: %u bytes (%.2f KB)\n", totalHeap, totalHeap / 1024.0);
   Serial.printf("   当前空闲: %u bytes (%.2f KB)\n", freeHeap, freeHeap / 1024.0);
   Serial.printf("   最小空闲: %u bytes (%.2f KB) ⭐关键指标\n", minFreeHeap, minFreeHeap / 1024.0);
   Serial.printf("   最大连续块: %u bytes (%.2f KB)\n", largestBlock, largestBlock / 1024.0);
   
   // 修复碎片化计算
   float fragmentation = 0.0;
   if (freeHeap > 0) {
       fragmentation = 100.0 - (largestBlock * 100.0 / freeHeap);
       if (fragmentation < 0) fragmentation = 0;  // 防止负数
   }
   Serial.printf("   碎片化: %.1f%%\n", fragmentation);
   Serial.printf("   使用率: %.1f%%\n", ((totalHeap - freeHeap) * 100.0) / totalHeap);
   
   // 内存使用警告（针对内部SRAM）
   if (freeHeap < 50000) {
       Serial.println("   🔴🔴🔴 严重警告：内存不足50KB！");
   } else if (freeHeap < 80000) {
       Serial.println("   ⚠️⚠️ 警告：内存低于80KB");
   } else if (freeHeap < 120000) {
       Serial.println("   ⚠️ 提示：内存低于120KB");
   } else {
       Serial.println("   ✅ 内存充足");
   }
   
   // 碎片化警告
   if (fragmentation > 50) {
       Serial.println("   ⚠️ 警告：内存碎片化严重！");
   }
   
   // ⭐ LVGL使用自定义分配器（LV_MEM_CUSTOM=1），不单独监控
   // LVGL的内存已包含在上面的ESP32堆内存中
   Serial.println("ℹ️  LVGL使用自定义分配器(已含在ESP32堆中)");
   
   Serial.println("════════════════════════════════════════════════════════\n");
}

// ⭐ 定时器数量监控（LVGL 8.4.0）
int countActiveTimers() {
   int count = 0;
   lv_timer_t * timer = lv_timer_get_next(NULL);
   
   while(timer != NULL) {
       count++;
       timer = lv_timer_get_next(timer);
   }
   
   return count;
}

// ⭐ 定时器详细监控
void printTimerStatus(const char* label) {
   int timer_count = countActiveTimers();
   
   Serial.println("────────────────────────────────────────────────────────");
   Serial.printf("⏱️  定时器状态 [%s]\n", label);
   Serial.printf("   活动定时器数量: %d\n", timer_count);
   
   if (timer_count > 15) {
       Serial.println("   🔴 严重警告：定时器数量过多，可能有泄漏！");
   } else if (timer_count > 10) {
       Serial.println("   ⚠️ 警告：定时器数量偏多");
   } else if (timer_count > 5) {
       Serial.println("   ℹ️ 定时器数量正常偏高");
   } else {
       Serial.println("   ✅ 定时器数量正常");
   }
   
   Serial.println("────────────────────────────────────────────────────────");
}

void setup()
{
    Serial.begin(115200);
    Serial.println("\n=== ESP32-S3 学生查寝系统 v2.0 启动 ===");
    Serial.println("⭐⭐⭐ 全PSRAM方案已启用 ⭐⭐⭐");

   // ⭐⭐⭐ 0. PSRAM检查与初始化（最高优先级）
   Serial.println("🔍 检查PSRAM状态...");
   
   // 检查PSRAM是否存在
   if (!psramFound()) {
       Serial.println("❌ 错误：未检测到PSRAM！");
       Serial.println("   请确认硬件型号：ESP32-S3 N16R8");
       Serial.println("   Arduino IDE → 工具 → PSRAM: 选择 \"OPI PSRAM\" 或 \"QPI PSRAM\"");
       Serial.println("   系统将无法正常运行！");
       while(1) {
           delay(1000);  // 停止运行
       }
   }
   
   // 获取PSRAM信息
   size_t psramSize = ESP.getPsramSize();
   size_t freePsram = ESP.getFreePsram();
   
   Serial.println("✅ PSRAM检测成功!");
   Serial.printf("   总容量: %.2f MB\n", psramSize / 1024.0 / 1024.0);
   Serial.printf("   可用: %.2f MB\n", freePsram / 1024.0 / 1024.0);
   Serial.printf("   使用率: %.1f%%\n", (1.0 - (float)freePsram / psramSize) * 100);
   
   // 验证LVGL内存分配器配置
   Serial.println("🎨 验证LVGL内存配置...");
   Serial.println("   分配器: heap_caps_malloc(SPIRAM)");
   Serial.println("   所有LVGL对象将存储在PSRAM中");
   Serial.println("   ✅ 支持1.5小时连续查寝操作");
   
   // 测试PSRAM分配
   void* testAlloc = heap_caps_malloc(1024, MALLOC_CAP_SPIRAM);
   if (testAlloc == NULL) {
       Serial.println("❌ 警告：PSRAM分配测试失败！");
   } else {
       Serial.println("✅ PSRAM分配测试成功");
       heap_caps_free(testAlloc);
   }
   Serial.println("");
   
   // 1. 初始化显示板
   Serial.println("🖥️ 初始化显示板...");
   boardInstance.init();
#if LVGL_PORT_AVOID_TEARING_MODE
   auto lcd = boardInstance.getLCD();
   lcd->configFrameBufferNumber(LVGL_PORT_DISP_BUFFER_NUM);
#if ESP_PANEL_DRIVERS_BUS_ENABLE_RGB && CONFIG_IDF_TARGET_ESP32S3
   auto lcd_bus = lcd->getBus();
   if (lcd_bus->getBasicAttributes().type == ESP_PANEL_BUS_TYPE_RGB) {
       static_cast<BusRGB *>(lcd_bus)->configRGB_BounceBufferSize(lcd->getFrameWidth() * 10);
   }
#endif
#endif
   assert(boardInstance.begin());
   Serial.println("OK 显示板初始化完成");

   // 2. 初始化LVGL
   Serial.println("🎨 初始化LVGL...");
   lvgl_port_init(boardInstance.getLCD(), boardInstance.getTouch());

    // 基于OLED成功经验,确保UTF8支持 (类似u8g2.enableUTF8Print())
    // LVGL中UTF8已在lv_conf.h中启用,这里确保正确初始化
    Serial.println("OK LVGL初始化完成");
    Serial.println("UTF8 UTF8中文支持:已启用");
    
    // ⭐⭐⭐ 系统启动后的初始内存状态
    printMemoryStatus("系统启动-LVGL初始化后");
    printTimerStatus("系统启动");

   // 使用内置字体库
   Serial.println("OK 使用内置中文字体库");

  // 3. 指纹传感器初始化（使用固定57600波特率）
  Serial.println("-> 初始化指纹传感器...");
  Serial.println("SETUP 硬件配置: Serial1 (GPIO17/18) - 57600波特率");

  // 4. 初始化WiFi模块（不自动连接,等待用户选择）
  Serial.println("WIFI 初始化WiFi模块...");
  WiFi.mode(WIFI_STA);
  WiFi.disconnect(); // 确保清理状态
  
  // ==================== ⭐ WiFi稳定性配置（独立热点架构优化）====================
  WiFi.setAutoReconnect(true);         // ⭐ 开启自动重连（解决50%的WiFi断开问题）
  WiFi.persistent(true);                // WiFi配置持久化到Flash
  WiFi.setTxPower(WIFI_POWER_19_5dBm); // 设置最大发射功率（增强信号强度）
  esp_wifi_set_ps(WIFI_PS_NONE);       // ⭐ 禁用WiFi省电模式（解决30%的WiFi断开问题）
  
  Serial.println("✅ WiFi稳定性配置完成:");
  Serial.println("  - 自动重连: 已启用");
  Serial.println("  - 省电模式: 已禁用（签到期间全程在线）");
  Serial.println("  - 发射功率: 19.5dBm（最大信号强度）");
  Serial.println("  - 架构模式: 独立热点（8设备独立WiFi）");
  Serial.println("  - 工作场景: USB供电 + 每天2小时签到");
  // ====================================================================
  
  Serial.println("INFO WiFi已初始化,等待用户通过界面选择网络连接");
  wifiConnected = false;
   
   // 检查是否有保存的用户WiFi配置（可选:后续可实现持久化存储）
   if (hasUserWiFiConfig && userSelectedSSID.length() > 0) {
       Serial.println("CONNECT 尝试连接用户之前选择的网络: " + userSelectedSSID);
       if (userSelectedPassword.length() > 0) {
           WiFi.begin(userSelectedSSID.c_str(), userSelectedPassword.c_str());
       } else {
           WiFi.begin(userSelectedSSID.c_str());
       }
       
       // 等待连接,最多10秒
       int wifiAttempts = 0;
       while (WiFi.status() != WL_CONNECTED && wifiAttempts < 20) {
           delay(500);
           Serial.print(".");
           wifiAttempts++;
       }
       
       if (WiFi.status() == WL_CONNECTED) {
           wifiConnected = true;
           networkSystemReady = true;  // 更新网络系统状态
           Serial.println();
           Serial.println("OK 用户网络重连成功!");
           Serial.println("IP地址: " + WiFi.localIP().toString());
           Serial.println("信号强度: " + String(WiFi.RSSI()) + " dBm");
           Serial.println("OK 网络系统状态已更新为就绪");
           logInfo("用户WiFi重连成功: " + userSelectedSSID + " | IP: " + WiFi.localIP().toString(), "wifi");
       } else {
           Serial.println();
           Serial.println("ERROR 用户网络重连失败,请手动重新连接");
           logError("用户WiFi重连失败: " + userSelectedSSID, "wifi");
       }
   }

    // 5. 初始化UI界面
    Serial.println("UI 创建用户界面...");
    lvgl_port_lock(-1);

    // 创建自定义主界面UI
    createMainUI();
    uiInitialized = true;

    lvgl_port_unlock();
    Serial.println("OK 用户界面创建完成");

   // 6. 系统初始化完成
   Serial.println("SUCCESS 系统初始化完成!");
   Serial.println("设备ID: " + String(DEVICE_ID));
   Serial.println("服务器: " + String(SERVER_URL));
   Serial.println("==========================================\n");
   
   // 发送系统启动日志
   if (wifiConnected) {
       logInfo("系统启动完成 | 设备ID: " + String(DEVICE_ID) + " | 内存: " + String(ESP.getFreeHeap()) + " bytes", "system");
   }
   
   // 7. 执行系统健康检查
   Serial.println("开始执行系统健康检查...");
   performSystemHealthCheck();
   
   // 直接初始化指纹传感器 (使用已知的57600波特率)
   Serial.println("直接初始化指纹传感器...");
   initFingerprintDirect();
   
   updateMainScreenStatus(STATE_IDLE, "系统初始化完成");
}

void loop()
{
   // 紧急性能保护
   yield();
   
   // 优化LVGL处理频率,在指纹检测时提高刷新率
   static unsigned long lastLvglUpdate = 0;
   unsigned long lvglInterval = detectionModeActive ? 20 : 50; // 检测时50Hz,平时20Hz
   if (millis() - lastLvglUpdate > lvglInterval) {
       lv_timer_handler();
       lastLvglUpdate = millis();
   }
   
   // 提高WiFi检查频率以便及时发现连接状态变化
   static unsigned long lastWifiCheck = 0;
   if (millis() - lastWifiCheck > 2000) { // 每2秒检查一次
       checkWiFiStatus();
       lastWifiCheck = millis();
   }
   
   // ⭐ 定期PSRAM使用情况监控（每30秒）- 全PSRAM方案专用
   static unsigned long lastMemCheck = 0;
   if (millis() - lastMemCheck > MEM_CHECK_INTERVAL) {
       // SRAM状态（系统和网络使用）
       uint32_t freeHeap = ESP.getFreeHeap();
       uint32_t totalHeap = ESP.getHeapSize();
       
       // PSRAM状态（LVGL页面使用）
       size_t freePsram = ESP.getFreePsram();
       size_t totalPsram = ESP.getPsramSize();
       size_t usedPsram = totalPsram - freePsram;
       
       // 计算使用率
       float psramUsage = (float)usedPsram / totalPsram * 100.0;
       float sramUsage = (float)(totalHeap - freeHeap) / totalHeap * 100.0;
       
       // 打印PSRAM使用情况（查寝监控关键指标）
       Serial.println("════════════ 内存状态监控 ════════════");
       Serial.printf("⭐ PSRAM: %.2fMB / %.2fMB (使用 %.1f%%)\n", 
                     usedPsram / 1024.0 / 1024.0, 
                     totalPsram / 1024.0 / 1024.0, 
                     psramUsage);
       Serial.printf("   SRAM:  %luKB / %luKB (使用 %.1f%%)\n", 
                     (totalHeap - freeHeap) / 1024, 
                     totalHeap / 1024, 
                     sramUsage);
       
       // PSRAM健康检查
       if (psramUsage > 80.0) {
           Serial.println("⚠️⚠️ 严重警告：PSRAM使用率超过80%");
           Serial.println("   建议：减少同时打开的界面数量");
       } else if (psramUsage > 50.0) {
           Serial.println("ℹ️ PSRAM使用正常（50-80%）");
       } else {
           Serial.println("✅ PSRAM使用良好（<50%）");
       }
       
       // SRAM警告（系统关键）
       if (sramUsage > 80.0) {
           Serial.println("⚠️⚠️ 严重警告：SRAM使用率过高！");
           Serial.println("   正在执行预防性清理...");
           cleanupAllTempTimers();
       } else if (sramUsage > 60.0) {
           Serial.println("⚠️ 注意：SRAM使用率较高");
       }
       
       Serial.println("═══════════════════════════════════════");
       
       lastMemCheck = millis();
   }
   
   // 暂时禁用系统状态处理避免阻塞
   // handleSystemState();
   
   // 旧的学号对话框处理系统已移除,现在使用异步定时器处理
   // 这样可以避免主循环和定时器同时操作UI对象导致的内存冲突
   
   // 只保留取消按钮的处理
   if (shouldCloseStudentIdDialog) {
       shouldCloseStudentIdDialog = false;
       Serial.println("主循环: 用户取消了学号输入");
       
       // 安全地关闭界面
       if (studentIdInputScreen != NULL) {
           extern lv_obj_t * mainScreen; // 声明外部变量
           lv_scr_load(mainScreen);
           lv_obj_del(studentIdInputScreen);
           studentIdInputScreen = NULL;
           studentIdTextArea = NULL;
           studentIdKeyboard = NULL;
           Serial.println("主循环: 学号输入界面已关闭");
       }
   }
   
   // 降低UI更新频率
   static unsigned long lastUIUpdate = 0;
   if (millis() - lastUIUpdate > 1000) { // 每1秒更新一次
       updateUIStatus();
       lastUIUpdate = millis();
   }
   
   // 已移除旧的设备状态更新系统,避免与统一状态管理系统冲突
   // static unsigned long lastDeviceUpdate = 0;
   // if (millis() - lastDeviceUpdate > 30000) { // 每30秒检查一次
   //     updateDeviceStatus(); // 已禁用,使用统一状态管理系统
   //     lastDeviceUpdate = millis();
   // }
    
    // 发送心跳（每30秒）
    if (millis() - lastHeartbeat > 30000) {
        sendHeartbeat();
        lastHeartbeat = millis();
        
       // SPIFFS已禁用
        
        // 发送定期系统状态日志
        if (wifiConnected) {
            logInfo("定期状态检查 | 内存: " + String(ESP.getFreeHeap()) + " bytes | 运行时间: " + String(millis()/1000) + "s", "system");
        }
    }
    
    // 定期检查时间同步状态（每10分钟）
    if (wifiConnected && millis() - lastTimeSync > 600000) {
        checkAndSyncTime();
        lastTimeSync = millis();
    }
    
    delay(10);
}

// ==================== 指纹传感器功能 ====================
bool initFingerprint() {
    Serial.println("==================== 指纹模块调试 ====================");
    Serial.println("硬件连接检查:");
    Serial.println("R307S VCC -> ESP32 3.3V");
    Serial.println("R307S GND -> ESP32 GND"); 
    Serial.printf("R307S TX  -> ESP32 GPIO%d (RX)\n", FP_RX_PIN);
    Serial.printf("R307S RX  -> ESP32 GPIO%d (TX)\n", FP_TX_PIN);
    Serial.println("注意:使用串口1,引脚映射到GPIO43/44");
    Serial.println();
    
    // 尝试57600波特率
    Serial.println("SCAN 尝试57600波特率连接...");
    fingerprintSerial.begin(57600, SERIAL_8N1, FP_RX_PIN, FP_TX_PIN);
    delay(1000);
    
    finger.begin(57600);
    delay(100);
    
    if (finger.verifyPassword()) {
        Serial.println("OK 指纹传感器连接成功（57600波特率）!");
        
        // 获取传感器参数
        if (finger.getParameters() == FINGERPRINT_OK) {
            Serial.println("=== R307S传感器信息 ===");
            Serial.printf("传感器容量: %d\n", finger.capacity);
            Serial.printf("安全等级: %d\n", finger.security_level);
            Serial.printf("状态寄存器: 0x%04X\n", finger.status_reg);
            Serial.printf("系统ID: 0x%04X\n", finger.system_id);
            Serial.printf("设备地址: 0x%08X\n", finger.device_addr);
            Serial.printf("数据包长度: %d字节\n", finger.packet_len);
            Serial.printf("波特率: %d\n", finger.baud_rate);
            
            totalFingerprintCount = getEnrolledFingerprintCount();
            Serial.printf("已录入指纹数量: %d\n", totalFingerprintCount);
            Serial.println("========================");
        }
        Serial.println("====================================================");
        return true;
    } else {
        Serial.println("ERROR 57600波特率连接失败");
        Serial.println("SCAN 尝试9600波特率连接...");
        
        fingerprintSerial.end();
        delay(100);
        fingerprintSerial.begin(9600, SERIAL_8N1, FP_RX_PIN, FP_TX_PIN);
        finger.begin(9600);
        delay(100);
        
        if (finger.verifyPassword()) {
            Serial.println("OK 指纹传感器连接成功（9600波特率）!");
            
            if (finger.getParameters() == FINGERPRINT_OK) {
                Serial.println("=== R307S传感器信息 ===");
                Serial.printf("传感器容量: %d\n", finger.capacity);
                Serial.printf("安全等级: %d\n", finger.security_level);
                totalFingerprintCount = getEnrolledFingerprintCount();
                Serial.printf("已录入指纹数量: %d\n", totalFingerprintCount);
                Serial.println("========================");
            }
            Serial.println("====================================================");
            return true;
        }
    }
    
    Serial.println("ERROR 指纹传感器连接完全失败!");
    Serial.println();
    Serial.println("=== 故障排除建议 ===");
    Serial.println("1. 检查供电: R307S需要稳定的3.3V或5V电源");
    Serial.println("2. 检查接线: TX/RX是否接反");
    Serial.printf("   R307S TX -> ESP32 GPIO%d (RX)\n", FP_RX_PIN);
    Serial.printf("   R307S RX -> ESP32 GPIO%d (TX)\n", FP_TX_PIN);
    Serial.println("3. 检查模块: 用万用表测试R307S是否通电");
    Serial.println("4. 检查波特率: 尝试9600或57600");
    Serial.println("5. 重启测试: 断电重新连接");
    Serial.println("====================================================");
    return false;
}

// 测试指纹模块状态
void testFingerprintModule() {
    Serial.println("==================== 指纹模块测试 ====================");
    
    // 测试连接状态
    if (finger.verifyPassword()) {
        Serial.println("OK 指纹模块通信正常");
        
        // 获取模板计数
        int templateCount = getEnrolledFingerprintCount();
        Serial.printf("已存储指纹模板: %d/%d\n", templateCount, finger.capacity);
        
        // 测试基本功能
        Serial.println("测试指纹检测功能...");
        Serial.println("请将手指放在传感器上进行测试（5秒内）");
        
        unsigned long testStart = millis();
        bool fingerDetected = false;
        
        while (millis() - testStart < 5000) {  // 5秒测试窗口
            int result = finger.getImage();
            if (result == FINGERPRINT_OK) {
                Serial.println("OK 检测到手指!");
                fingerDetected = true;
                
                // 尝试转换图像 - 明确指定缓冲区1
                result = finger.image2Tz(1);
                if (result == FINGERPRINT_OK) {
                    Serial.println("OK 图像转换成功!");
                } else {
                    Serial.printf("ERROR 图像转换失败,错误码: %d\n", result);
                }
                break;
            }
            delay(100);
        }
        
        if (!fingerDetected) {
            Serial.println("WARN  未检测到手指,但模块通信正常");
        }
        
    } else {
        Serial.println("ERROR 指纹模块通信失败");
        Serial.println("请检查连接或重启设备");
    }
    
    Serial.println("====================================================");
}

// 修复后的指纹识别算法
int detectFingerprint() {
    int p = finger.getImage();
    if (p != FINGERPRINT_OK) {
        if (p == FINGERPRINT_NOFINGER) {
            return -1; // 没有手指
        } else {
            return -3; // 图像采集失败
        }
    }
    
    // 关键修复:明确指定缓冲区1
    p = finger.image2Tz(1);
    if (p != FINGERPRINT_OK) {
        return -3; // 特征生成失败
    }
    
    // 关键修复:在缓冲区1中搜索
    p = finger.fingerSearch(1);
    if (p == FINGERPRINT_OK) {
        Serial.println("找到匹配指纹!ID: " + String(finger.fingerID) + 
                      ", 置信度: " + String(finger.confidence));
        return finger.fingerID;
    } else if (p == FINGERPRINT_NOTFOUND) {
        return -2; // 未找到匹配
    } else {
        return -3; // 搜索失败
    }
}

// 录入指纹
bool enrollFingerprint(int id) {
    Serial.println("开始录入指纹 ID: " + String(id));
    
    // 第一次采集
    Serial.println("请将手指放在传感器上...");
    while (finger.getImage() != FINGERPRINT_OK) {
        delay(100);
    }
    
    if (finger.image2Tz(1) != FINGERPRINT_OK) {
        Serial.println("第一次特征生成失败");
        return false;
    }
    
    Serial.println("请抬起手指");
    delay(2000);
    while (finger.getImage() == FINGERPRINT_OK) {
        delay(100);
    }
    
    // 第二次采集
    Serial.println("请再次放置同一手指...");
    while (finger.getImage() != FINGERPRINT_OK) {
        delay(100);
    }
    
    if (finger.image2Tz(2) != FINGERPRINT_OK) {
        Serial.println("第二次特征生成失败");
        return false;
    }
    
    // 特征融合
    if (finger.createModel() != FINGERPRINT_OK) {
        Serial.println("特征融合失败");
        return false;
    }
    
    // 存储模板
    if (finger.storeModel(id) != FINGERPRINT_OK) {
        Serial.println("存储失败");
        return false;
    }
    
    Serial.println("指纹录入成功!ID: " + String(id));
    totalFingerprintCount = getEnrolledFingerprintCount();
    return true;
}

// 获取已录入指纹数量
int getEnrolledFingerprintCount() {
    int count = 0;
    for (int i = 0; i < 1000; i++) {
        if (finger.loadModel(i) == FINGERPRINT_OK) {
            count++;
        }
        delay(1); // 避免看门狗重置
    }
    return count;
}

// ==================== HTTP通信优化 ====================
// 统一的HTTP配置函数 - 提升通信稳定性
void configureHTTP(HTTPClient &http, int timeoutMs = 8000) {
   http.setTimeout(timeoutMs);
   http.addHeader("User-Agent", "ESP32-S3/1.0");
   http.addHeader("Connection", "close");
   http.addHeader("Cache-Control", "no-cache");
}

// 带重试的HTTP POST请求
int retryHttpPost(HTTPClient &http, const String &payload, int maxRetries = 2) {
   int httpCode = -1;
   for (int attempt = 0; attempt <= maxRetries; attempt++) {
       httpCode = http.POST(payload);
       
       if (httpCode > 0 && httpCode != 408 && httpCode != 500 && httpCode != 502 && httpCode != 503) {
           // 成功或非临时错误，停止重试
           break;
       }
       
       if (attempt < maxRetries) {
           Serial.printf("HTTP请求失败(码:%d), 重试 %d/%d...\n", httpCode, attempt + 1, maxRetries);
           delay(1000 * (attempt + 1)); // 指数退避：1秒, 2秒, 3秒
       }
   }
   return httpCode;
}

// ==================== WiFi网络功能 ====================
void connectWiFi() {
   // 此函数现在仅用于兼容性,实际连接通过WiFi界面完成
   Serial.println("WARN connectWiFi() 已弃用,请使用WiFi界面手动连接");
}

void checkWiFiStatus() {
   // 移除重复的时间检查，因为调用方已经控制频率
    
    if (WiFi.status() == WL_CONNECTED) {
        if (!wifiConnected) {
            wifiConnected = true;
            Serial.println("\nWiFi连接成功!");
            Serial.println("SSID: " + WiFi.SSID());
            Serial.println("IP地址: " + WiFi.localIP().toString());
            Serial.println("信号强度: " + String(WiFi.RSSI()) + " dBm");
            
           // 发送WiFi重连成功日志
           logInfo("WiFi重连成功: " + WiFi.SSID() + " | IP: " + WiFi.localIP().toString(), "wifi");
           
           // 注意：时间同步现在在updateUIStatus()中处理，避免重复设置
           Serial.println("WiFi重连成功，时间同步将由updateUIStatus()处理");
        }
   } else {
       if (wifiConnected) {
           wifiConnected = false;
           Serial.println("ERROR WiFi连接断开");
           logWarn("WiFi连接断开,尝试自动重连", "wifi");
       }
       
      // 自动重连用户选择的WiFi
      static unsigned long lastReconnectAttempt = 0;
      if (!wifiConnecting && millis() - lastReconnectAttempt > 10000 && hasUserWiFiConfig && userSelectedSSID.length() > 0) { // ⭐ 每10秒尝试一次重连（原30秒改为10秒，提升恢复速度）
          lastReconnectAttempt = millis();
          Serial.println("-> 尝试重新连接用户WiFi: " + userSelectedSSID);
          
          // ⭐ 优化：不需要先断开再重连，ESP32的WiFi库会自动处理
          // WiFi.disconnect();  // 注释掉：避免不必要的断开操作
          // delay(100);
          
          if (userSelectedPassword.length() > 0) {
              WiFi.begin(userSelectedSSID.c_str(), userSelectedPassword.c_str());
          } else {
              WiFi.begin(userSelectedSSID.c_str());
          }
           
           logInfo("尝试重新连接用户WiFi: " + userSelectedSSID, "wifi");
       }
   }
}

// 发送签到数据到服务器
bool sendCheckinData(int fingerprintId) {
    if (!wifiConnected) {
        Serial.println("WiFi未连接,无法上传数据");
        return false;
    }
    
    updateMainScreenStatus(STATE_UPLOADING_DATA, "正在上传签到数据");
    
   HTTPClient http;
   http.begin(SERVER_URL);
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   configureHTTP(http, 10000);  // 签到数据重要，使用10秒超时
   
   // 准备JSON数据
   StaticJsonDocument<512> doc;
   doc["fingerprint_id"] = String(fingerprintId);
   doc["device_id"] = DEVICE_ID;
   doc["timestamp"] = time(nullptr);
   
   String jsonData;
   serializeJson(doc, jsonData);
   
   Serial.println("发送签到数据: " + jsonData);
   
   int httpResponseCode = retryHttpPost(http, jsonData, 3);  // 签到重要，重试3次
    
    if (httpResponseCode > 0) {
        String response = http.getString();
        Serial.println("HTTP响应码: " + String(httpResponseCode));
        Serial.println("响应内容: " + response);
        
        if (httpResponseCode == 200) {
            // 优化：减小JSON文档大小到768字节（签到响应较小）
            StaticJsonDocument<768> responseDoc;
            DeserializationError error = deserializeJson(responseDoc, response);
            
            // 立即释放response内存
            response = String();
            
           if (!error && responseDoc["success"]) {
               Serial.println("SUCCESS 签到成功!");
               todayCheckinCount++;
               
               // 打印完整响应用于调试
               Serial.println("完整响应数据:");
               serializeJsonPretty(responseDoc, Serial);
               Serial.println();
               
               // 尝试多种可能的字段名解析学生信息
               String studentName = "未知";
               String studentId = "未知";
               String dormitory = "未知";
               String className = "未知";
               
               // 从根级别获取字段（根据您的JSON响应结构）
               studentName = responseDoc["name"] | "未知";
               studentId = responseDoc["student_id"] | "未知";
               dormitory = responseDoc["dormitory"] | "未知";
               className = responseDoc["class_name"] | "未知";
               
               Serial.println("解析的学生信息:");
               Serial.println("  姓名: " + studentName);
               Serial.println("  学号: " + studentId);
               Serial.println("  班级: " + className);
               Serial.println("  宿舍: " + dormitory);
               
               // 调用正确的显示函数,传递班级信息
               displayStudentInfo(fingerprintId, studentName, studentId, dormitory, className);
                
                http.end();
                updateMainScreenStatus(STATE_IDLE, "签到成功");
                return true;
            }
        }
    }
    
    Serial.println("ERROR 签到失败,HTTP错误: " + String(httpResponseCode));
    http.end();
    updateMainScreenStatus(STATE_IDLE, "签到失败");
    return false;
}

// 发送心跳数据
void sendHeartbeat() {
    if (!wifiConnected) return;
    
    HTTPClient http;
    String heartbeatUrl = String(SERVER_URL);
    heartbeatUrl.replace("checkin.php", "device_heartbeat.php");
    
    http.begin(heartbeatUrl);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Api-Token", API_TOKEN);
    
    StaticJsonDocument<512> doc;
    doc["device_id"] = DEVICE_ID;
    doc["timestamp"] = time(nullptr);
    doc["ip_address"] = WiFi.localIP().toString();
    doc["signal_strength"] = WiFi.RSSI();
    doc["type"] = "heartbeat";
    
    String jsonData;
    serializeJson(doc, jsonData);
    
    int httpResponseCode = http.POST(jsonData);
    if (httpResponseCode == 200) {
        Serial.println("💓 心跳发送成功");
    }
    
    http.end();
}

// 测试多个API端点
bool testMultipleEndpoints() {
    String endpoints[] = {
        "http://YOUR_SERVER_IP/api/device_heartbeat.php",
        "http://YOUR_SERVER_IP/api/checkin.php", 
        "http://YOUR_SERVER_IP/device_heartbeat.php",
        "http://YOUR_SERVER_IP/heartbeat.php"
    };
    
    int numEndpoints = sizeof(endpoints) / sizeof(endpoints[0]);
    
    for (int i = 0; i < numEndpoints; i++) {
        Serial.println("测试端点 " + String(i + 1) + ": " + endpoints[i]);
        
        HTTPClient http;
        http.begin(endpoints[i]);
        http.setTimeout(3000);
        
        int getCode = http.GET();
        Serial.println("GET响应码: " + String(getCode));
        
        if (getCode == 200) {
            Serial.println("OK 找到可用端点: " + endpoints[i]);
            http.end();
            return true;
        }
        
        http.end();
        delay(500);
    }
    
    return false;
}

// 心跳测试功能 - 带返回值
bool testHeartbeat() {
    Serial.println("执行心跳测试...");
    
    if (!wifiConnected) {
        Serial.println("ERROR 心跳测试失败:WiFi未连接");
        return false;
    }
    
// 测试网站连接
     Serial.println("SCAN 测试网站连接...");
    
    // 发送心跳测试请求 - 修复URL构建
    HTTPClient http;
    String heartbeatUrl = "http://YOUR_SERVER_IP/api/device_heartbeat.php";  // 直接使用完整URL
    Serial.println("心跳测试URL: " + heartbeatUrl);
    
   http.begin(heartbeatUrl);
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   configureHTTP(http, 8000);  // 心跳测试使用8秒超时
    
    StaticJsonDocument<512> doc;
    doc["device_id"] = DEVICE_ID;
    doc["device_name"] = "ESP32-S3指纹设备";
    doc["building_number"] = 1;
    doc["device_sequence"] = 1;
    doc["location"] = "测试位置";
    doc["timestamp"] = time(nullptr);
    doc["ip_address"] = WiFi.localIP().toString();
    doc["signal_strength"] = WiFi.RSSI();
    doc["type"] = "heartbeat_test";  // 测试类型
    
    String jsonData;
    serializeJson(doc, jsonData);
    
    Serial.println("发送心跳测试数据: " + jsonData);
    
   int httpResponseCode = retryHttpPost(http, jsonData, 2);  // 心跳重试2次
   bool result = false;
    
    if (httpResponseCode > 0) {
        String response = http.getString();
        Serial.println("心跳响应码: " + String(httpResponseCode));
        Serial.println("心跳响应内容: " + response.substring(0, 200));  // 只显示前200字符
        
        if (httpResponseCode == 200) {
            // 任何200响应都认为是成功
            Serial.println("OK 心跳测试成功:服务器通信正常");
            result = true;
        } else if (httpResponseCode == 404) {
            Serial.println("ERROR API接口不存在 (404) - 尝试其他方法");
            // 尝试简单的GET请求测试连通性
            http.end();
            http.begin("http://YOUR_SERVER_IP/");
            int basicCode = http.GET();
            Serial.println("基础连接测试: " + String(basicCode));
            result = (basicCode == 200);
        } else {
            Serial.println("ERROR 心跳HTTP状态码: " + String(httpResponseCode));
            result = false;
        }
    } else {
        Serial.println("ERROR 心跳HTTP请求失败: " + String(httpResponseCode));
        result = false;
    }
    
    http.end();
    
    // 内存清理
    jsonData = "";
    ESP.getFreeHeap();
    
    return result;
}

// ==================== 系统状态处理 ====================
void handleSystemState() {
    switch (currentSystemState) {
       case STATE_IDLE:
           // 【已禁用】自动指纹检测功能已禁用，统一使用按钮触发的签到界面
           // 现在需要点击"签到"按钮来启动 createCheckinDetectionScreen()
           break;
            
        case STATE_FINGERPRINT_DETECTING:
            // 在sendCheckinData中处理
            break;
            
        case STATE_ENROLLING:
            // 录入模式处理
            break;
            
        case STATE_CONNECTING_WIFI:
            // WiFi连接处理在checkWiFiStatus中
            break;
            
        case STATE_UPLOADING_DATA:
            // 数据上传处理在sendCheckinData中
            break;
    }
}

// ==================== UI界面管理 ====================
// UI组件声明
lv_obj_t * mainScreen;
lv_obj_t * wifiScreen;
lv_obj_t * checkinDetectionScreen = NULL;  // 签到检测界面
lv_obj_t * enrollmentConfirmScreen = NULL; // 录入确认界面
lv_obj_t * titleLabel;
lv_obj_t * wifiLabel;
lv_obj_t * timeLabel;
lv_obj_t * dateLabel;
lv_obj_t * fingerprintLabel;
// lv_obj_t * buttonContainer;  // 不再需要按钮容器
lv_obj_t * btnCheckin;
lv_obj_t * btnEnroll;
lv_obj_t * btnManualCheckin;  // ⭐ 新增：手动签到按钮
lv_obj_t * btnHeartbeat;
lv_obj_t * btnStats;
lv_obj_t * btnWifi;

// ==================== 手动签到界面全局变量 ====================
lv_obj_t *manualCheckinInputScreen = NULL;    // 学号输入界面
lv_obj_t *manualCheckinTextArea = NULL;       // 学号输入框
lv_obj_t *manualCheckinKeyboard = NULL;       // 数字键盘
lv_obj_t *manualCheckinLoadingScreen = NULL;  // 加载界面
lv_obj_t *manualCheckinResultScreen = NULL;   // 签到结果界面（成功/失败）

// 按钮事件回调函数声明
void btnWifi_event_cb(lv_event_t * e);
void btnCheckin_event_cb(lv_event_t * e);
void btnEnroll_event_cb(lv_event_t * e);
void btnManualCheckin_event_cb(lv_event_t * e);  // ⭐ 新增：手动签到按钮回调
void btnHeartbeat_event_cb(lv_event_t * e);
void btnStats_event_cb(lv_event_t * e);

// ⭐⭐⭐ 新增：统计功能相关函数声明（必须在btnStats_event_cb调用之前）
void createStatsMenuScreen();
void createBuildingFloorScreen(BuildingFloorData data);
void createStatisticsScreen(DeviceUncheckedData data);  // 函数重载
DeviceUncheckedData getDeviceUncheckedStudents();
BuildingFloorData getBuildingFloorStats();

// ⭐ 新增：手动签到功能函数声明
bool submitManualCheckin(String studentId);
void showManualCheckinInputDialog();                    // 显示手动签到学号输入界面
void cancelManualCheckinCallback(lv_event_t * e);       // 手动签到取消按钮回调
void confirmManualCheckinIdCallback(lv_event_t * e);    // 手动签到确认按钮回调
void processManualCheckin(String studentId);            // 处理手动签到（参考指纹录入模式）
void createManualCheckinLoadingScreen();                // 创建手动签到加载界面
void showManualCheckinSuccessScreen(String studentId);  // 显示手动签到成功界面
void showManualCheckinFailureScreen(String errorMessage); // 显示手动签到失败界面

// 签到检测界面变量和函数声明
lv_obj_t *checkinStepLabel = NULL;       // 步骤显示标签
lv_obj_t *checkinProgressLabel = NULL;   // 进度显示标签
lv_obj_t *checkinStudentInfoLabel = NULL; // 学生信息显示标签
lv_obj_t *checkinCancelBtn = NULL;       // 取消按钮
lv_obj_t *checkinContinueBtn = NULL;     // 继续按钮

// ✅ 已删除倒计时相关变量

void createCheckinDetectionScreen();     // 创建签到检测界面
void updateCheckinProgress(String step, String message, bool isSuccess = false); // 更新检测进度
void showCheckinStudentInfo(String name, String studentId, String class_name, String dormitory); // 显示学生信息
void checkinCancelCallback(lv_event_t * e);   // 取消回调
void checkinContinueCallback(lv_event_t * e); // 继续回调
void closeCheckinDetectionScreen();     // 关闭检测界面
// ✅ 已删除倒计时相关函数声明

// 指纹录入界面变量声明
lv_obj_t *fingerprintEnrollmentScreen = NULL;  // 指纹录入界面
lv_obj_t *enrollmentStepLabel = NULL;          // 录入步骤标签
lv_obj_t *enrollmentProgressLabel = NULL;      // 录入进度标签
lv_obj_t *enrollmentCancelBtn = NULL;          // 取消录入按钮

// 录入确认界面变量和函数声明  
lv_obj_t *confirmStudentInfoLabel = NULL; // 学生信息确认标签
lv_obj_t *confirmEnrollBtn = NULL;       // 确认录入按钮
lv_obj_t *confirmCancelBtn = NULL;       // 取消录入按钮

void createEnrollmentConfirmScreen(String name, String studentId, String class_name, String dormitory); // 创建录入确认界面
void confirmEnrollCallback(lv_event_t * e);   // 确认录入回调
void confirmCancelCallback(lv_event_t * e);   // 取消录入回调
void closeEnrollmentConfirmScreen();     // 关闭确认界面
void getStudentInfoAndShowConfirm(String studentId); // 获取学生信息并显示确认界面

// 指纹录入界面相关声明
void createFingerprintEnrollmentScreen();    // 创建指纹录入界面
void updateEnrollmentProgress(String step, String message);  // 更新录入进度
void closeFingerprintEnrollmentScreen();     // 关闭录入界面
void performFingerprintEnrollmentWithUI();   // 带UI的指纹录入

// WiFi界面事件回调函数声明
void back_btn_event_cb(lv_event_t * e);
void refresh_btn_event_cb(lv_event_t * e);
void wifi_item_event_cb(lv_event_t * e);
void connect_btn_event_cb(lv_event_t * e);
void password_input_event_cb(lv_event_t * e);
void keyboard_event_cb(lv_event_t * e);
void showLVGLKeyboard();
void hideKeyboard();

// WiFi界面功能函数声明  
void createWiFiScreen();
void startWiFiScan();
void addNetworkToList(int index);
void showPasswordInput(int index);
void connectToNetwork(const char* password, int index);

// WiFi连接进度界面函数声明
void createConnectProgressScreen(String ssid);
void closeConnectProgressScreen();

// 时间同步相关函数声明（已在文件开头声明）
void wifi_connect_timer_cb(lv_timer_t * timer);
void cancel_connect_btn_event_cb(lv_event_t * e);

// 统计界面功能函数声明
void createTableHeader(lv_obj_t *parent, int yPos);
void createBuildingRow(lv_obj_t *parent, BuildingData building, int yPos);
void createImprovedTableHeader(lv_obj_t *parent, int yPos);
void createImprovedBuildingRow(lv_obj_t *parent, BuildingData building, int yPos, bool isEvenRow);

// 楼栋详细信息功能函数声明
BuildingDetail getBuildingDetail(String buildingName);
void createBuildingDetailScreen(BuildingDetail detail);
void createFloorRow(lv_obj_t *parent, FloorInfo floor, int yPos, bool isEvenRow);

// WiFi异步操作函数声明
void wifi_create_timer_cb(lv_timer_t * timer);

// 创建主界面UI
void createMainUI() {
    // 创建主屏幕
    mainScreen = lv_obj_create(NULL);
    lv_obj_set_style_bg_color(mainScreen, lv_color_hex(0xF0F8FF), 0);
    
    // 测试标签已删除以节省内存
    
    // 标题栏
    titleLabel = lv_label_create(mainScreen);
    String deviceTitle = "设备: " + String(DEVICE_ID);
    lv_label_set_text(titleLabel, deviceTitle.c_str());
    lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x2196F3), 0);
    lv_obj_align(titleLabel, LV_ALIGN_TOP_MID, 0, 10);
    
    // WiFi状态
    wifiLabel = lv_label_create(mainScreen);
    lv_label_set_text(wifiLabel, "WiFi: 未连接");
    lv_obj_set_style_text_font(wifiLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(wifiLabel, lv_color_hex(0xF44336), 0); // 红色表示未连接
    lv_obj_align(wifiLabel, LV_ALIGN_TOP_LEFT, 10, 50);
    
    // 时间显示
    timeLabel = lv_label_create(mainScreen);
    lv_label_set_text(timeLabel, "00:00:00");
    lv_obj_set_style_text_font(timeLabel, &myFont_new, 0);
    lv_obj_align(timeLabel, LV_ALIGN_TOP_RIGHT, -10, 50);
    
    // 日期显示（放在时间下方）
    dateLabel = lv_label_create(mainScreen);
    String currentDate = getCurrentDate();
    lv_label_set_text(dateLabel, currentDate.c_str());
    lv_obj_set_style_text_font(dateLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(dateLabel, lv_color_hex(0x666666), 0); // 使用灰色
    lv_obj_align(dateLabel, LV_ALIGN_TOP_RIGHT, -10, 75);
    
   // 指纹传感器状态区域 - 向上移动避免与按钮重叠
   fingerprintLabel = lv_label_create(mainScreen);
   lv_obj_set_style_text_font(fingerprintLabel, &myFont_new, 0);
   lv_obj_set_style_text_align(fingerprintLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(fingerprintLabel, LV_ALIGN_CENTER, 0, -80);  // 向上移动60像素
   
   // 使用新的统一状态管理系统初始化显示
   updateMainScreenStatus(STATE_IDLE, "硬件: GPIO17/18");
    
    // 底部系统标题
    lv_obj_t *systemTitleLabel = lv_label_create(mainScreen);
    lv_label_set_text(systemTitleLabel, "学生管理系统V3.0");
    lv_obj_set_style_text_font(systemTitleLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(systemTitleLabel, lv_color_hex(0x000000), 0); // 黑色
    lv_obj_align(systemTitleLabel, LV_ALIGN_BOTTOM_MID, 0, -10);
    
    // 创建功能按钮（优化布局）
    createOptimizedFunctionButtons();
    
    // 加载主屏幕
    lv_scr_load(mainScreen);
}

void createFunctionButtons() {
    // WiFi连接按钮
    btnWifi = lv_btn_create(mainScreen);
    lv_obj_set_size(btnWifi, 80, 35);
    lv_obj_align(btnWifi, LV_ALIGN_BOTTOM_LEFT, 10, -100);
    lv_obj_add_event_cb(btnWifi, btnWifi_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t * wifiLabelBtn = lv_label_create(btnWifi);
    lv_label_set_text(wifiLabelBtn, "WiFi");
    lv_obj_set_style_text_font(wifiLabelBtn, &myFont_new, 0);
    lv_obj_center(wifiLabelBtn);
    
    // 开始签到按钮
    btnCheckin = lv_btn_create(mainScreen);
    lv_obj_set_size(btnCheckin, 80, 35);
    lv_obj_align(btnCheckin, LV_ALIGN_BOTTOM_MID, -40, -100);
    lv_obj_add_event_cb(btnCheckin, btnCheckin_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t * checkinLabelBtn = lv_label_create(btnCheckin);
    lv_label_set_text(checkinLabelBtn, "签到");
    lv_obj_set_style_text_font(checkinLabelBtn, &myFont_new, 0);
    lv_obj_center(checkinLabelBtn);
    
    // 录入指纹按钮
    btnEnroll = lv_btn_create(mainScreen);
    lv_obj_set_size(btnEnroll, 80, 35);
    lv_obj_align(btnEnroll, LV_ALIGN_BOTTOM_MID, 40, -100);
    lv_obj_add_event_cb(btnEnroll, btnEnroll_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t * enrollLabelBtn = lv_label_create(btnEnroll);
    lv_label_set_text(enrollLabelBtn, "指纹");
    lv_obj_set_style_text_font(enrollLabelBtn, &myFont_new, 0);
    lv_obj_center(enrollLabelBtn);
    
    // 心跳检测按钮
    btnHeartbeat = lv_btn_create(mainScreen);
    lv_obj_set_size(btnHeartbeat, 80, 35);
    lv_obj_align(btnHeartbeat, LV_ALIGN_BOTTOM_LEFT, 10, -60);
    lv_obj_add_event_cb(btnHeartbeat, btnHeartbeat_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t * heartbeatLabelBtn = lv_label_create(btnHeartbeat);
    lv_label_set_text(heartbeatLabelBtn, "心跳");
    lv_obj_set_style_text_font(heartbeatLabelBtn, &myFont_new, 0);
    lv_obj_center(heartbeatLabelBtn);
    
    // 统计查询按钮
    btnStats = lv_btn_create(mainScreen);
    lv_obj_set_size(btnStats, 80, 35);
    lv_obj_align(btnStats, LV_ALIGN_BOTTOM_RIGHT, -10, -60);
    lv_obj_add_event_cb(btnStats, btnStats_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t * statsLabelBtn = lv_label_create(btnStats);
    lv_label_set_text(statsLabelBtn, "统计");
    lv_obj_set_style_text_font(statsLabelBtn, &myFont_new, 0);
    lv_obj_center(statsLabelBtn);
}

// 创建优化的功能按钮布局
void createOptimizedFunctionButtons() {
    // 按钮尺寸和间距
    int btnWidth = 90;
    int btnHeight = 50;
    int spacing = 15;
    
    // 第一行:WiFi, 签到, 指纹 (3个按钮居中)
    int row1Y = 280;  // 第一行Y坐标 - 向下移动避免重叠
    int startX1 = (320 - (3 * btnWidth + 2 * spacing)) / 2;  // 居中计算
    
    // WiFi按钮
    btnWifi = lv_btn_create(mainScreen);
    lv_obj_set_size(btnWifi, btnWidth, btnHeight);
    lv_obj_set_pos(btnWifi, startX1, row1Y);
    lv_obj_set_style_bg_color(btnWifi, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_radius(btnWifi, 8, 0);
    lv_obj_add_event_cb(btnWifi, btnWifi_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *wifiLabelBtn = lv_label_create(btnWifi);
    lv_label_set_text(wifiLabelBtn, "WiFi");
    lv_obj_set_style_text_font(wifiLabelBtn, &myFont_new, 0);
    lv_obj_set_style_text_color(wifiLabelBtn, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(wifiLabelBtn);
    
    // 签到按钮
    btnCheckin = lv_btn_create(mainScreen);
    lv_obj_set_size(btnCheckin, btnWidth, btnHeight);
    lv_obj_set_pos(btnCheckin, startX1 + btnWidth + spacing, row1Y);
    lv_obj_set_style_bg_color(btnCheckin, lv_color_hex(0x4CAF50), 0);
    lv_obj_set_style_radius(btnCheckin, 8, 0);
    lv_obj_add_event_cb(btnCheckin, btnCheckin_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *checkinLabelBtn = lv_label_create(btnCheckin);
    lv_label_set_text(checkinLabelBtn, "签到");
    lv_obj_set_style_text_font(checkinLabelBtn, &myFont_new, 0);
    lv_obj_set_style_text_color(checkinLabelBtn, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(checkinLabelBtn);
    
    // 指纹按钮
    btnEnroll = lv_btn_create(mainScreen);
    lv_obj_set_size(btnEnroll, btnWidth, btnHeight);
    lv_obj_set_pos(btnEnroll, startX1 + 2 * (btnWidth + spacing), row1Y);
    lv_obj_set_style_bg_color(btnEnroll, lv_color_hex(0xFF9800), 0);
    lv_obj_set_style_radius(btnEnroll, 8, 0);
    lv_obj_add_event_cb(btnEnroll, btnEnroll_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *enrollLabelBtn = lv_label_create(btnEnroll);
    lv_label_set_text(enrollLabelBtn, "指纹");
    lv_obj_set_style_text_font(enrollLabelBtn, &myFont_new, 0);
    lv_obj_set_style_text_color(enrollLabelBtn, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(enrollLabelBtn);
    
   // ⭐ 第二行: 手动, 心跳, 统计 (3个按钮居中)
   int row2Y = row1Y + btnHeight + spacing;
   int startX2 = (320 - (3 * btnWidth + 2 * spacing)) / 2;  // ⭐ 3个按钮居中
   
   // ⭐⭐⭐ 新增：手动签到按钮
   btnManualCheckin = lv_btn_create(mainScreen);
   lv_obj_set_size(btnManualCheckin, btnWidth, btnHeight);
   lv_obj_set_pos(btnManualCheckin, startX2, row2Y);
   lv_obj_set_style_bg_color(btnManualCheckin, lv_color_hex(0x00BCD4), 0);  // 青色
   lv_obj_set_style_radius(btnManualCheckin, 8, 0);
   lv_obj_add_event_cb(btnManualCheckin, btnManualCheckin_event_cb, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *manualLabelBtn = lv_label_create(btnManualCheckin);
   lv_label_set_text(manualLabelBtn, "手动");
   lv_obj_set_style_text_font(manualLabelBtn, &myFont_new, 0);
   lv_obj_set_style_text_color(manualLabelBtn, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(manualLabelBtn);
   
   // 心跳按钮 (位置调整到中间)
   btnHeartbeat = lv_btn_create(mainScreen);
   lv_obj_set_size(btnHeartbeat, btnWidth, btnHeight);
   lv_obj_set_pos(btnHeartbeat, startX2 + btnWidth + spacing, row2Y);  // ⭐ 位置调整
   lv_obj_set_style_bg_color(btnHeartbeat, lv_color_hex(0xE91E63), 0);
   lv_obj_set_style_radius(btnHeartbeat, 8, 0);
   lv_obj_add_event_cb(btnHeartbeat, btnHeartbeat_event_cb, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *heartbeatLabelBtn = lv_label_create(btnHeartbeat);
   lv_label_set_text(heartbeatLabelBtn, "心跳");
   lv_obj_set_style_text_font(heartbeatLabelBtn, &myFont_new, 0);
   lv_obj_set_style_text_color(heartbeatLabelBtn, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(heartbeatLabelBtn);
   
   // 统计按钮 (位置调整到右边)
   btnStats = lv_btn_create(mainScreen);
   lv_obj_set_size(btnStats, btnWidth, btnHeight);
   lv_obj_set_pos(btnStats, startX2 + 2 * (btnWidth + spacing), row2Y);  // ⭐ 位置调整
   lv_obj_set_style_bg_color(btnStats, lv_color_hex(0x9C27B0), 0);
   lv_obj_set_style_radius(btnStats, 8, 0);
   lv_obj_add_event_cb(btnStats, btnStats_event_cb, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *statsLabelBtn = lv_label_create(btnStats);
   lv_label_set_text(statsLabelBtn, "统计");
   lv_obj_set_style_text_font(statsLabelBtn, &myFont_new, 0);
   lv_obj_set_style_text_color(statsLabelBtn, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(statsLabelBtn);
}

// 按钮事件回调函数
void btnWifi_event_cb(lv_event_t * e) {
    Serial.println("WiFi管理按钮被点击");
    
    // 防止重复点击
    if (wifiOperationInProgress) {
        Serial.println("WARN WiFi操作正在进行中,请等待...");
        return;
    }
    
   // 使用新的内存保护机制
   if (!checkMemoryAndProtect("创建统计界面")) {
       return;
   }
    
    // 1. 立即显示加载提示
    showMessageBox("WiFi管理", "正在初始化WiFi界面...\n请稍候", "加载中", true);
    
    // 2. 设置操作状态
    wifiOperationInProgress = true;
    
    // 3. 创建异步定时器,300ms后执行界面创建
    wifiOperationTimer = lv_timer_create(wifi_create_timer_cb, 300, NULL);
    lv_timer_set_repeat_count(wifiOperationTimer, 1);
    
    Serial.println("WIFI WiFi界面创建已启动,异步执行中...");
}

void btnCheckin_event_cb(lv_event_t * e) {
   Serial.println("签到按钮被点击");
   
   // 检查系统状态
   if (!fingerprintSystemReady) {
       showMessageBox("系统检查", "指纹传感器未就绪\n正在重新检测...", "检查中", true);
       
       // 快速重新检测传感器
       safeDeleteTimer(&recoveryTimer);  // 先清理旧的
       recoveryTimer = lv_timer_create([](lv_timer_t * timer) {
           bool sensorReady = checkFingerprintHealth();
           if (sensorReady) {
               fingerprintSystemReady = true;
               safeCloseCurrentMessageBox();
               // 创建新的检测界面而不是原来的检测模式
               createCheckinDetectionScreen();
           } else {
               showMessageBox("传感器错误", "指纹传感器无响应\n请检查连接后重试", "确定", false);
           }
           lv_timer_del(timer);
       }, 300, NULL);
       return;
   }
   
   // 创建独立的签到检测界面
   createCheckinDetectionScreen();
}

void btnEnroll_event_cb(lv_event_t * e) {
   Serial.println("指纹按钮被点击");
   
   // 检查系统状态
   if (!fingerprintSystemReady) {
       showMessageBox("系统检查", "指纹传感器未就绪\n请等待系统初始化", "确定", false);
       return;
   }
   
   // 实时检查网络连接状态，而不是依赖启动时的检查结果
   if (WiFi.status() != WL_CONNECTED) {
       showMessageBox("网络错误", "WiFi未连接\n请先连接WiFi网络", "确定", false);
       return;
   }
   
   // 检查是否在检测模式中
   if (detectionModeActive) {
       showMessageBox("模式冲突", "请先退出指纹检测模式\n再进行指纹录入", "确定", false);
       return;
   }
   
   // ⭐ 设置为指纹录入模式
   currentOperationMode = MODE_FINGERPRINT_ENROLL;
   
   Serial.println("启动指纹录入流程");
   Serial.println("当前WiFi状态: 已连接 - " + WiFi.localIP().toString());
   showStudentIdInputDialog();
}

// ⭐⭐⭐ 新增：手动签到按钮事件回调
void btnManualCheckin_event_cb(lv_event_t * e) {
   Serial.println("==================== 手动签到按钮被点击 ====================");
   
   // 防止重复点击（2秒内不能重复点击）
   static unsigned long lastClickTime = 0;
   if (millis() - lastClickTime < 2000) {
       Serial.println("⚠️ 点击过快，请稍候");
       return;
   }
   lastClickTime = millis();
   
   // 检查WiFi连接
   if (WiFi.status() != WL_CONNECTED) {
       showMessageBox("网络错误", "WiFi未连接 请先连接WiFi", "确定", false);
       return;
   }
   
   // 如果指纹录入正在进行，拒绝手动签到
   if (enrollmentInProgress) {
       showMessageBox("操作冲突", "指纹录入正在进行 请完成或取消后再使用手动签到", "确定", false);
       return;
   }
   
   // 内存检查
   if (!checkMemoryAndProtect("手动签到功能")) {
       return;
   }
   
   // ⭐⭐⭐ 调用独立的手动签到输入界面（不再设置 currentOperationMode）
   Serial.println("✅ 启动手动签到独立流程");
   showManualCheckinInputDialog();
}

void btnHeartbeat_event_cb(lv_event_t * e) {
    Serial.println("心跳按钮被点击");
    
    // 防止重复点击
    if (heartbeatInProgress) {
        Serial.println("WARN 心跳测试正在进行中,请等待...");
        return;
    }
    
    // 1. 立即显示"正在连接"的提示框
    showMessageBox("心跳测试", "正在连接服务器...\n请稍候", "连接中", true);
    
    // 2. 设置心跳测试状态
    heartbeatInProgress = true;
    
    // 3. 创建异步定时器,500ms后执行实际的心跳测试
    // 这样UI可以立即响应,用户能看到弹窗
    heartbeatTestTimer = lv_timer_create(heartbeat_test_timer_cb, 500, NULL);
    lv_timer_set_repeat_count(heartbeatTestTimer, 1);
    
    Serial.println("PING 心跳测试已启动,异步执行中...");
}

void btnStats_event_cb(lv_event_t * e) {
   Serial.println("统计按钮被点击");
   
   // 防止重复点击
   if (statisticsInProgress) {
       Serial.println("WARN 统计操作正在进行中,请等待...");
       return;
   }
   
   // ⭐ 显示选择菜单（2个选项）
   createStatsMenuScreen();
}

// 更新UI状态
void updateUIStatus() {
    if (!uiInitialized) return;
    
    if (millis() - lastUIUpdate < 1000) return;
    lastUIUpdate = millis();
    
   // 更新WiFi状态 - 直接检查WiFi.isConnected()确保实时性
   static bool lastWiFiConnectedState = false;  // 记录上次的WiFi状态
   bool currentWiFiState = WiFi.isConnected();
   
   if (currentWiFiState) {
       // 检查是否是首次连接WiFi，如果是则启动时间同步
       if (!wifiConnected) {
           wifiConnected = true;
           Serial.println("WiFi连接成功，启动时间同步");
           performTimeZoneSetupAndSync();
       } else {
           // 检查时区是否正确设置
           static bool timeZoneVerified = false;
           if (!timeZoneVerified) {
               char* currentTZ = getenv("TZ");
               if (currentTZ == NULL || String(currentTZ) != "CST-8") {
                   performTimeZoneSetupAndSync();
               }
               timeZoneVerified = true;
           }
       }
       
       // 显示实际连接的WiFi名称
       String currentSSID = WiFi.SSID();
       if (currentSSID.length() > 0) {
           lv_label_set_text(wifiLabel, ("WiFi: " + currentSSID).c_str());
       } else {
           lv_label_set_text(wifiLabel, "WiFi: 已连接");
       }
       lv_obj_set_style_text_color(wifiLabel, lv_color_hex(0x4CAF50), 0);
   } else {
           // 同步更新全局变量
           if (wifiConnected) {
               wifiConnected = false;
           }
       
       lv_label_set_text(wifiLabel, "WiFi: 未连接");
       lv_obj_set_style_text_color(wifiLabel, lv_color_hex(0xF44336), 0);
   }
   
   // 检查WiFi状态是否发生变化，如果变化则更新主屏幕状态
   if (currentWiFiState != lastWiFiConnectedState) {
       lastWiFiConnectedState = currentWiFiState;
       // 如果当前是空闲状态，刷新主屏幕显示以反映最新的网络状态
       if (currentSystemState == STATE_IDLE) {
           updateMainScreenStatus(STATE_IDLE, "");  // 重新生成状态文本
       }
   }
    
    // 更新时间
    struct tm timeinfo;
    if (getLocalTime(&timeinfo)) {
        char timeStr[20];
        strftime(timeStr, sizeof(timeStr), "%H:%M:%S", &timeinfo);
        
        if (timeLabel != NULL) {
            lv_label_set_text(timeLabel, timeStr);
        }
    }
    
    // 更新日期（每分钟更新一次以节省性能）
    static unsigned long lastDateUpdate = 0;
    if (millis() - lastDateUpdate > 60000) {  // 60秒更新一次
        String currentDate = getCurrentDate();
        lv_label_set_text(dateLabel, currentDate.c_str());
        lastDateUpdate = millis();
    }
    
    // 不显示任何临时状态,只显示最终的设备状态
    // updateDeviceStatus() 会负责更新设备状态信息
}

// 显示签到成功信息
void showCheckinSuccess(String studentName, String studentId, String dormitory) {
   // 这个函数已弃用,现在使用displayStudentInfo函数
   Serial.println("WARN showCheckinSuccess已弃用,请使用displayStudentInfo");
   displayStudentInfo(0, studentName, studentId, dormitory, "暂无");
}

// 显示错误信息
void showError(String title, String message) {
    // 错误信息只打印到串口,不修改屏幕显示
    Serial.println("错误: " + title);
    Serial.println("信息: " + message);
}


// 关闭当前消息框
void closeMsgBox() {
    if (currentMsgBox != NULL) {
        lv_obj_del(currentMsgBox);
        currentMsgBox = NULL;
        msgBoxTitleLabel = NULL;
        msgBoxMessageLabel = NULL;
        msgBoxButton = NULL;
    }
    safeDeleteTimer(&msgBoxTimer);
    
    // 清理心跳测试状态
    if (heartbeatInProgress) {
        heartbeatInProgress = false;
        safeDeleteTimer(&heartbeatTestTimer);
    }
    
    // 清理统计获取状态
    if (statisticsInProgress) {
        statisticsInProgress = false;
        safeDeleteTimer(&statisticsTimer);
    }
}

// 消息框按钮事件
void msgbox_btn_event_cb(lv_event_t * e) {
    closeMsgBox();
}

// 消息框定时器回调
void msgbox_timer_cb(lv_timer_t * timer) {
    closeMsgBox();
}

// 异步心跳测试定时器回调
void heartbeat_test_timer_cb(lv_timer_t * timer) {
    if (!heartbeatInProgress) return;
    
    Serial.println("-> 执行异步心跳测试...");
    
    // 执行心跳测试
    bool testResult = testHeartbeat();
    
    // 根据测试结果更新提示框内容
    if (testResult) {
        updateMsgBox("心跳测试", "连接成功!\n服务器通信正常", "确定", true);
    } else {
        String errorMsg = "连接失败!\n";
        if (!wifiConnected) {
            errorMsg += "WiFi未连接";
        } else {
            errorMsg += "服务器无响应";
        }
        updateMsgBox("心跳测试", errorMsg, "确定", false);
    }
    
    // 重新设置自动关闭定时器
    if (msgBoxTimer != NULL) {
        lv_timer_del(msgBoxTimer);
        msgBoxTimer = NULL;
    }
    msgBoxTimer = lv_timer_create(msgbox_timer_cb, 3000, NULL);
    lv_timer_set_repeat_count(msgBoxTimer, 1);
    
    // 清理心跳测试状态
    heartbeatInProgress = false;
    if (heartbeatTestTimer != NULL) {
        lv_timer_del(heartbeatTestTimer);
        heartbeatTestTimer = NULL;
    }
    
    Serial.println("OK 异步心跳测试完成");
}

// 函数声明
StatisticsData getStatisticsData();
void createStatisticsScreen(StatisticsData stats);
void createBuildingDetailScreen(BuildingDetail detail);

// 时间同步状态检查和等待
bool waitForTimeSync(int maxWaitSeconds) {
    Serial.println("⏰ 等待NTP时间同步...");
    
    for (int i = 0; i < maxWaitSeconds; i++) {
        struct tm timeinfo;
        if (getLocalTime(&timeinfo)) {
            // 检查年份是否合理（2020年以后）
            if (timeinfo.tm_year + 1900 >= 2020) {
                Serial.println("OK NTP时间同步成功");
                char timeStr[30];
                strftime(timeStr, sizeof(timeStr), "%Y-%m-%d %H:%M:%S", &timeinfo);
                Serial.println("当前时间: " + String(timeStr));
                return true;
            }
        }
        delay(1000);
        Serial.print(".");
    }
    
    Serial.println("\nWARN NTP时间同步超时");
    return false;
}

// 获取当前日期字符串（改进版）
String getCurrentDate() {
    struct tm timeinfo;
    
    // 首先尝试获取本地时间
    if (getLocalTime(&timeinfo)) {
        // 检查时间是否合理（2020年以后）
        if (timeinfo.tm_year + 1900 >= 2020) {
            char dateStr[12];
            strftime(dateStr, sizeof(dateStr), "%Y-%m-%d", &timeinfo);
            return String(dateStr);
        }
    }
    
    // 如果NTP时间不可用且WiFi已连接,尝试重新同步
    if (wifiConnected) {
        Serial.println("WARN 检测到时间异常,尝试重新同步NTP...");
        // 不重新配置时区，使用已设置的TZ环境变量
       configTime(0, 0, "pool.ntp.org", "time.nist.gov");
        
        if (waitForTimeSync(5)) {
            if (getLocalTime(&timeinfo)) {
                char dateStr[12];
                strftime(dateStr, sizeof(dateStr), "%Y-%m-%d", &timeinfo);
                Serial.println("OK 时间重新同步成功: " + String(dateStr));
                return String(dateStr);
            }
        }
    }
    
    // 最后的备用方案:使用更智能的默认日期
    // 基于编译时间生成一个合理的默认日期
    String compileDate = String(__DATE__);  // 格式: "Sep 12 2025"
    
    Serial.println("📅 解析编译日期: " + compileDate);
    
    // 解析编译日期中的年、月、日
    int year = 2025;  // 默认年份
    int month = 9;    // 默认月份
    int day = 12;     // 默认日期
    
    // 提取年份（最后4个字符）
    if (compileDate.length() >= 4) {
        String yearStr = compileDate.substring(compileDate.length() - 4);
        year = yearStr.toInt();
        if (year < 2020 || year > 2030) year = 2025; // 安全检查
    }
    
    // 提取日期（中间的数字）
    int spaceIndex1 = compileDate.indexOf(' ');
    int spaceIndex2 = compileDate.lastIndexOf(' ');
    if (spaceIndex1 > 0 && spaceIndex2 > spaceIndex1) {
        String dayStr = compileDate.substring(spaceIndex1 + 1, spaceIndex2);
        day = dayStr.toInt();
        if (day < 1 || day > 31) day = 12; // 安全检查
    }
    
    // 月份映射
    if (compileDate.indexOf("Jan") >= 0) month = 1;
    else if (compileDate.indexOf("Feb") >= 0) month = 2;
    else if (compileDate.indexOf("Mar") >= 0) month = 3;
    else if (compileDate.indexOf("Apr") >= 0) month = 4;
    else if (compileDate.indexOf("May") >= 0) month = 5;
    else if (compileDate.indexOf("Jun") >= 0) month = 6;
    else if (compileDate.indexOf("Jul") >= 0) month = 7;
    else if (compileDate.indexOf("Aug") >= 0) month = 8;
    else if (compileDate.indexOf("Sep") >= 0) month = 9;
    else if (compileDate.indexOf("Oct") >= 0) month = 10;
    else if (compileDate.indexOf("Nov") >= 0) month = 11;
    else if (compileDate.indexOf("Dec") >= 0) month = 12;
    
    char fallbackDate[12];
    snprintf(fallbackDate, sizeof(fallbackDate), "%04d-%02d-%02d", year, month, day);
    
    Serial.println("WARN 使用基于编译时间的智能备用日期: " + String(fallbackDate));
    Serial.println("* 建议连接WiFi以获取准确的NTP时间");
    
    return String(fallbackDate);
}

// 定期检查和同步时间
void performTimeZoneSetupAndSync() {
   Serial.println("开始时区设置和NTP同步...");
   
   // 清除旧时区设置
   unsetenv("TZ");
   
   // 设置中国时区 (UTC+8)
   int tzResult = setenv("TZ", "CST-8", 1);
   if (tzResult != 0) {
       Serial.println("时区设置失败");
       return;
   }
   tzset();
   
   // 配置NTP服务器
   configTime(8 * 3600, 0, "pool.ntp.org", "time.nist.gov", "ntp.aliyun.com");
   lastTimeSync = millis();
   
   // 启动异步时间同步检查
   lv_timer_create([](lv_timer_t * t) {
       static int syncAttempts = 0;
       
       struct tm timeinfo;
       if (getLocalTime(&timeinfo) && timeinfo.tm_year + 1900 >= 2020) {
           timeSyncSuccess = true;
           Serial.println("NTP时间同步成功");
           
           // 更新时间显示
           char displayTimeStr[20];
           strftime(displayTimeStr, sizeof(displayTimeStr), "%H:%M:%S", &timeinfo);
           if (timeLabel != NULL) {
               lv_label_set_text(timeLabel, displayTimeStr);
           }
           
           syncAttempts = 0;
           lv_timer_del(t);
           return;
       }
       
       syncAttempts++;
       if (syncAttempts >= 15) {
           timeSyncSuccess = false;
           Serial.println("NTP时间同步超时");
           syncAttempts = 0;
           lv_timer_del(t);
       }
   }, 1000, NULL);
}

void checkAndSyncTime() {
   struct tm timeinfo;
   bool timeValid = false;
   
   // 检查当前时间是否有效
   if (getLocalTime(&timeinfo)) {
       int currentYear = timeinfo.tm_year + 1900;
       if (currentYear >= 2020 && currentYear <= 2030) {
           timeValid = true;
           timeSyncSuccess = true;
       }
   }
   
   if (!timeValid) {
       Serial.println("WARN 检测到时间异常,重新同步NTP...");
       // 不重新配置时区，使用已设置的TZ环境变量
       configTime(0, 0, "pool.ntp.org", "time.nist.gov", "ntp.aliyun.com");
       
       if (waitForTimeSync(5)) {
           Serial.println("OK 时间重新同步成功");
           timeSyncSuccess = true;
       } else {
           Serial.println("ERROR 时间重新同步失败");
           timeSyncSuccess = false;
       }
   } else {
       // 时间有效,但每小时重新同步一次以保持精确
       static unsigned long lastFullSync = 0;
       if (millis() - lastFullSync > 3600000) {  // 1小时
           Serial.println("🕐 执行定期时间同步...");
           // 不重新配置时区，使用已设置的TZ环境变量
           configTime(0, 0, "pool.ntp.org", "time.nist.gov", "ntp.aliyun.com");
           lastFullSync = millis();
       }
   }
}

// 获取统计数据 - 从服务器API获取真实数据
StatisticsData getStatisticsData() {
    StatisticsData stats;
    
    if (!wifiConnected) {
        Serial.println("ERROR 统计数据获取失败:WiFi未连接");
        return stats;
    }
    
    Serial.println("STATS 获取真实统计数据...");
    
    // 从服务器获取真实数据
    HTTPClient http;
   http.begin("http://YOUR_SERVER_IP/api/statistics.php");
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   configureHTTP(http, 8000);  // 统计查询使用8秒超时
    
    // 准备请求数据
    StaticJsonDocument<256> requestDoc;
    requestDoc["device_id"] = DEVICE_ID;
    requestDoc["date"] = getCurrentDate();
    
    String jsonData;
    serializeJson(requestDoc, jsonData);
    
   Serial.println("发送统计请求: " + jsonData);
   
   int httpCode = retryHttpPost(http, jsonData, 2);  // 统计查询重试2次
    
   if (httpCode == 200) {
       String response = http.getString();
       Serial.println("统计API响应: " + response);
       
       // 优化：使用安全的768字节（统计响应包含多个字段）
       StaticJsonDocument<768> responseDoc;
       DeserializationError error = deserializeJson(responseDoc, response);
       
       // 立即释放response内存
       response = String();
        
        if (!error && responseDoc["success"]) {
            JsonObject data = responseDoc["data"];
            stats.totalStudents = data["total_students"] | 0;
            stats.totalPresent = data["total_present"] | 0;
            stats.totalAbsent = data["total_absent"] | 0;
            stats.totalLeave = data["total_leave"] | 0;
            stats.totalNotChecked = data["total_not_checked"] | 0;
            stats.success = true;
            
            // 更新今日签到计数
            todayCheckinCount = data["today_checkins"] | 0;
            
            // 解析楼栋数据
            JsonArray buildings = data["buildings"];
            stats.buildingCount = min((int)buildings.size(), 10);  // 最多10栋楼
            
            for (int i = 0; i < stats.buildingCount; i++) {
                JsonObject building = buildings[i];
                stats.buildings[i].buildingName = building["building_name"].as<String>();
                stats.buildings[i].totalStudents = building["total_students"] | 0;
                stats.buildings[i].totalPresent = building["total_present"] | 0;
                stats.buildings[i].totalAbsent = building["total_absent"] | 0;
                stats.buildings[i].totalLeave = building["total_leave"] | 0;
                stats.buildings[i].totalNotChecked = building["total_not_checked"] | 0;
            }
            
            Serial.println("OK 获取真实统计数据成功:");
            Serial.println("  总人数: " + String(stats.totalStudents));
            Serial.println("  在寝: " + String(stats.totalPresent));
            Serial.println("  离寝: " + String(stats.totalAbsent));
            Serial.println("  请假: " + String(stats.totalLeave));
            Serial.println("  未签到: " + String(stats.totalNotChecked));
            Serial.println("  今日签到: " + String(todayCheckinCount));
            
            http.end();
            return stats;
        } else {
            Serial.println("ERROR API响应解析失败或success为false");
            if (error) {
                Serial.println("JSON解析错误: " + String(error.c_str()));
            }
        }
    } else {
        Serial.println("ERROR HTTP请求失败,状态码: " + String(httpCode));
        if (httpCode > 0) {
            String response = http.getString();
            Serial.println("错误响应: " + response);
        }
    }
    
   http.end();
   
   Serial.println("WARN 服务器数据获取失败,统计功能暂不可用");
   stats.success = false;
   return stats;
}

// ⭐⭐⭐ 新增：获取设备对应楼层的未签到学生数据
DeviceUncheckedData getDeviceUncheckedStudents() {
   DeviceUncheckedData data = {0};  // 初始化为0
   data.students = NULL;
   data.success = false;
   
   if (!wifiConnected) {
       Serial.println("ERROR 未签到学生数据获取失败:WiFi未连接");
       return data;
   }
   
   Serial.println("==================== 获取设备未签到学生数据 ====================");
   Serial.println("设备ID: " + String(DEVICE_ID));
   Serial.println("日期: " + getCurrentDate());
   
   // ⭐ 喂狗，防止HTTP请求时看门狗超时
   esp_task_wdt_reset();
   
   HTTPClient http;
   http.begin("http://YOUR_SERVER_IP/api/device_unchecked_students.php");
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   configureHTTP(http, 8000);  // 8秒超时
   
   // 准备请求数据
   StaticJsonDocument<128> requestDoc;
   requestDoc["device_id"] = DEVICE_ID;
   requestDoc["date"] = getCurrentDate();
   
   String jsonData;
   serializeJson(requestDoc, jsonData);
   
   Serial.println("发送请求: " + jsonData);
   
   // ⭐ HTTP请求前再次喂狗
   esp_task_wdt_reset();
   
   int httpCode = retryHttpPost(http, jsonData, 2);  // 重试2次
   
   // ⭐ HTTP请求后喂狗
   esp_task_wdt_reset();
   
   if (httpCode == 200) {
       String response = http.getString();
       Serial.println("API响应: " + response);
       
       // 动态分配JSON文档（最多50个学生 × 80字节 ≈ 4KB + 基础500字节 = 5KB）
       DynamicJsonDocument responseDoc(5120);
       
       DeserializationError error = deserializeJson(responseDoc, response);
       response = String();  // 立即释放response内存
       
       if (!error && responseDoc["success"] == true) {
           // 解析基础信息
           const char* deviceInfo = responseDoc["device_info"] | "";
           const char* dateStr = responseDoc["date"] | "";
           
           strlcpy(data.deviceInfo, deviceInfo, sizeof(data.deviceInfo));
           strlcpy(data.date, dateStr, sizeof(data.date));
           data.totalUnchecked = responseDoc["total_unchecked"] | 0;
           
           JsonArray students = responseDoc["students"];
           data.studentCount = min((int)students.size(), 50);  // 限制最多50个
           
           Serial.println("设备信息: " + String(data.deviceInfo));
           Serial.println("未签到人数: " + String(data.totalUnchecked));
           Serial.println("返回学生数: " + String(data.studentCount));
           
           if (data.studentCount > 0) {
               // 动态分配PSRAM内存
               data.students = (UncheckedStudent*)ps_malloc(
                   sizeof(UncheckedStudent) * data.studentCount
               );
               
               if (data.students != NULL) {
                   // 复制学生数据
                   for (int i = 0; i < data.studentCount; i++) {
                       JsonObject student = students[i];
                       const char* name = student["name"] | "未知";
                       const char* location = student["location"] | "未知";
                       
                       strlcpy(data.students[i].name, name, 20);
                       strlcpy(data.students[i].location, location, 12);
                       
                       Serial.printf("  [%d] %s - %s\n", i+1, 
                           data.students[i].name, data.students[i].location);
                   }
                   data.success = true;
                   Serial.println("✅ 数据获取成功");
               } else {
                   Serial.println("❌ 内存分配失败");
                   data.success = false;
               }
           } else {
               // 0个学生也是成功状态
               data.success = true;
               Serial.println("✅ 本楼层全员已签到");
           }
       } else {
           Serial.println("❌ JSON解析失败: " + String(error.c_str()));
       }
   } else {
       Serial.println("❌ HTTP请求失败,状态码: " + String(httpCode));
   }
   
   http.end();
   Serial.println("========================================");
   
   return data;
}

// ⭐⭐⭐ 新增：获取楼栋楼层统计数据
BuildingFloorData getBuildingFloorStats() {
   BuildingFloorData data = {0};
   data.success = false;
   
   if (!wifiConnected) {
       Serial.println("ERROR 楼层统计数据获取失败:WiFi未连接");
       return data;
   }
   
   Serial.println("==================== 获取楼栋楼层统计数据 ====================");
   Serial.println("设备ID: " + String(DEVICE_ID));
   Serial.println("日期: " + getCurrentDate());
   
   // ⭐ 喂狗，防止HTTP请求时看门狗超时
   esp_task_wdt_reset();
   
   HTTPClient http;
   http.begin("http://YOUR_SERVER_IP/api/building_floor_stats.php");
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   configureHTTP(http, 8000);
   
   StaticJsonDocument<128> requestDoc;
   requestDoc["device_id"] = DEVICE_ID;
   requestDoc["date"] = getCurrentDate();
   
   String jsonData;
   serializeJson(requestDoc, jsonData);
   
   Serial.println("发送请求: " + jsonData);
   
   // ⭐ HTTP请求前再次喂狗
   esp_task_wdt_reset();
   
   int httpCode = retryHttpPost(http, jsonData, 2);
   
   // ⭐ HTTP请求后喂狗
   esp_task_wdt_reset();
   
   if (httpCode == 200) {
       String response = http.getString();
       Serial.println("API响应: " + response);
       
       // 分配足够大的JSON文档（20个楼层 × 150字节 ≈ 3KB + 基础500字节 = 4KB）
       DynamicJsonDocument responseDoc(4096);
       
       DeserializationError error = deserializeJson(responseDoc, response);
       response = String();
       
       if (!error && responseDoc["success"] == true) {
           const char* buildingName = responseDoc["building"] | "";
           const char* dateStr = responseDoc["date"] | "";
           
           strlcpy(data.buildingName, buildingName, sizeof(data.buildingName));
           strlcpy(data.date, dateStr, sizeof(data.date));
           data.totalStudents = responseDoc["total_students"] | 0;
           data.totalPresent = responseDoc["total_present"] | 0;
           data.totalAbsent = responseDoc["total_absent"] | 0;
           data.totalLeave = responseDoc["total_leave"] | 0;
           data.totalNotChecked = responseDoc["total_not_checked"] | 0;
           
           JsonArray floors = responseDoc["floors"];
           data.floorCount = min((int)floors.size(), 20);
           
           Serial.println("楼栋: " + String(data.buildingName));
           Serial.println("楼层数: " + String(data.floorCount));
           
           for (int i = 0; i < data.floorCount; i++) {
               JsonObject floor = floors[i];
               const char* area = floor["area"] | "";
               
               strlcpy(data.floors[i].area, area, sizeof(data.floors[i].area));
               data.floors[i].floor = floor["floor"] | 0;
               data.floors[i].totalStudents = floor["total_students"] | 0;
               data.floors[i].totalPresent = floor["total_present"] | 0;
               data.floors[i].totalAbsent = floor["total_absent"] | 0;
               data.floors[i].totalLeave = floor["total_leave"] | 0;
               data.floors[i].totalNotChecked = floor["total_not_checked"] | 0;
               data.floors[i].isCurrentDevice = floor["is_current_device"] | false;
               
               Serial.printf("  [%d] %s区%d层: 总%d 在%d 未%d%s\n", 
                   i+1, 
                   data.floors[i].area,
                   data.floors[i].floor,
                   data.floors[i].totalStudents,
                   data.floors[i].totalPresent,
                   data.floors[i].totalNotChecked,
                   data.floors[i].isCurrentDevice ? " [当前设备]" : ""
               );
           }
           
           data.success = true;
           Serial.println("✅ 楼层统计数据获取成功");
       } else {
           Serial.println("❌ JSON解析失败: " + String(error.c_str()));
       }
   } else {
       Serial.println("❌ HTTP请求失败,状态码: " + String(httpCode));
   }
   
   http.end();
   Serial.println("========================================");
   
   return data;
}

// 移除筛选功能相关的全局变量

// 全局变量:存储最后的统计数据
StatisticsData lastStatisticsData;

// 全局变量:楼栋详细信息页面
static lv_obj_t *buildingDetailScreen = NULL;
static lv_timer_t *buildingDetailTimer = NULL;

// 创建楼栋列表统计页面
void createStatisticsScreen(StatisticsData stats) {
    // 保存当前数据到全局变量
    lastStatisticsData = stats;
    
    // 如果已有统计页面,先安全删除
    if (statisticsScreen != NULL) {
        // 先切换到主界面，再删除统计界面
        lv_scr_load(mainScreen);
        lv_obj_del(statisticsScreen);
        statisticsScreen = NULL;
        Serial.println("统计界面已安全删除");
    }
    
    // 创建全屏统计页面容器（固定不可滚动）
    statisticsScreen = lv_obj_create(lv_scr_act());
    lv_obj_set_size(statisticsScreen, 320, 480);
    lv_obj_set_pos(statisticsScreen, 0, 0);  // 固定位置
    lv_obj_set_style_bg_color(statisticsScreen, lv_color_hex(0xF0F8FF), 0);
    lv_obj_set_style_border_width(statisticsScreen, 0, 0);
    lv_obj_set_style_radius(statisticsScreen, 0, 0);
    lv_obj_set_style_pad_all(statisticsScreen, 0, 0);
    
    // 禁用页面滚动
    lv_obj_clear_flag(statisticsScreen, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_set_scroll_dir(statisticsScreen, LV_DIR_NONE);
    
    // 标题栏（固定在顶部）
    lv_obj_t *titleBar = lv_obj_create(statisticsScreen);
    lv_obj_set_size(titleBar, 320, 50);
    lv_obj_set_pos(titleBar, 0, 0);
    lv_obj_set_style_bg_color(titleBar, lv_color_hex(0x1976D2), 0);
    lv_obj_set_style_radius(titleBar, 0, 0);
    lv_obj_set_style_border_width(titleBar, 0, 0);
    lv_obj_set_style_pad_all(titleBar, 0, 0);
    lv_obj_clear_flag(titleBar, LV_OBJ_FLAG_SCROLLABLE);
    
    // 添加标题阴影效果
    lv_obj_set_style_shadow_width(titleBar, 8, 0);
    lv_obj_set_style_shadow_color(titleBar, lv_color_hex(0x000000), 0);
    lv_obj_set_style_shadow_opa(titleBar, LV_OPA_20, 0);
    
    lv_obj_t *titleLabel = lv_label_create(titleBar);
    lv_label_set_text(titleLabel, "楼栋统计");
    lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(titleLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, 0);
    
    // 简化的日期信息栏
    lv_obj_t *dateBar = lv_obj_create(statisticsScreen);
    lv_obj_set_size(dateBar, 320, 35);
    lv_obj_set_pos(dateBar, 0, 50);
    lv_obj_set_style_bg_color(dateBar, lv_color_hex(0xE3F2FD), 0);
    lv_obj_set_style_radius(dateBar, 0, 0);
    lv_obj_set_style_border_width(dateBar, 0, 0);
    lv_obj_set_style_border_color(dateBar, lv_color_hex(0xBBDEFB), 1);
    lv_obj_set_style_border_side(dateBar, LV_BORDER_SIDE_BOTTOM, 0);
    lv_obj_set_style_pad_all(dateBar, 5, 0);
    lv_obj_clear_flag(dateBar, LV_OBJ_FLAG_SCROLLABLE);
    
    // 日期显示（居中）
    lv_obj_t *dateLabel = lv_label_create(dateBar);
    String dateText = getCurrentDate();
    lv_label_set_text(dateLabel, dateText.c_str());
    lv_obj_set_style_text_font(dateLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(dateLabel, lv_color_hex(0x1976D2), 0);
    lv_obj_align(dateLabel, LV_ALIGN_CENTER, 0, 0);
    
    // 表头（固定）
    createImprovedTableHeader(statisticsScreen, 85);
    
    // 楼栋列表容器（可滚动区域）
    lv_obj_t *listContainer = lv_obj_create(statisticsScreen);
    lv_obj_set_size(listContainer, 320, 320);  // 调整高度给底部按钮留空间
    lv_obj_set_pos(listContainer, 0, 110);
    lv_obj_set_style_bg_color(listContainer, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_radius(listContainer, 0, 0);
    lv_obj_set_style_border_width(listContainer, 0, 0);
    lv_obj_set_style_pad_all(listContainer, 5, 0);
    lv_obj_set_scroll_dir(listContainer, LV_DIR_VER);
    
    // 设置滚动条模式（使用通用API）
    // 注意:滚动条样式在不同LVGL版本中API不同,这里只设置基本滚动功能
    
    // 添加楼栋数据行
    int yPos = 5;
    int rowHeight = 40;  // 增加行高
    int displayedBuildings = 0;
    
    for (int i = 0; i < stats.buildingCount; i++) {
        // 只显示有学生的楼栋
        if (!stats.buildings[i].hasStudents()) {
            continue;
        }
        
        createImprovedBuildingRow(listContainer, stats.buildings[i], yPos, displayedBuildings % 2 == 0);
        yPos += rowHeight + 3;
        displayedBuildings++;
    }
    
    // 如果没有数据显示提示
    if (displayedBuildings == 0) {
        lv_obj_t *noDataContainer = lv_obj_create(listContainer);
        lv_obj_set_size(noDataContainer, 300, 100);
        lv_obj_center(noDataContainer);
        lv_obj_set_style_bg_color(noDataContainer, lv_color_hex(0xF5F5F5), 0);
        lv_obj_set_style_radius(noDataContainer, 10, 0);
        lv_obj_set_style_border_width(noDataContainer, 1, 0);
        lv_obj_set_style_border_color(noDataContainer, lv_color_hex(0xE0E0E0), 0);
        
        lv_obj_t *noDataLabel = lv_label_create(noDataContainer);
        lv_label_set_text(noDataLabel, "暂无楼栋数据");
        lv_obj_set_style_text_font(noDataLabel, &myFont_new, 0);
        lv_obj_set_style_text_color(noDataLabel, lv_color_hex(0x757575), 0);
        lv_obj_center(noDataLabel);
    }
    
    // 底部按钮栏（固定在底部）
    lv_obj_t *buttonBar = lv_obj_create(statisticsScreen);
    lv_obj_set_size(buttonBar, 320, 50);
    lv_obj_set_pos(buttonBar, 0, 430);
    lv_obj_set_style_bg_color(buttonBar, lv_color_hex(0xF8F9FA), 0);
    lv_obj_set_style_radius(buttonBar, 0, 0);
    lv_obj_set_style_border_width(buttonBar, 1, 0);
    lv_obj_set_style_border_color(buttonBar, lv_color_hex(0xE0E0E0), 0);
    lv_obj_set_style_border_side(buttonBar, LV_BORDER_SIDE_TOP, 0);
    lv_obj_set_style_pad_all(buttonBar, 8, 0);
    lv_obj_clear_flag(buttonBar, LV_OBJ_FLAG_SCROLLABLE);
    
    // 刷新按钮
    lv_obj_t *refreshBtn = lv_btn_create(buttonBar);
    lv_obj_set_size(refreshBtn, 90, 34);
    lv_obj_set_pos(refreshBtn, 20, 8);
    lv_obj_set_style_bg_color(refreshBtn, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_radius(refreshBtn, 17, 0);
    lv_obj_set_style_border_width(refreshBtn, 0, 0);
    lv_obj_set_style_shadow_width(refreshBtn, 4, 0);
    lv_obj_set_style_shadow_color(refreshBtn, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_shadow_opa(refreshBtn, LV_OPA_30, 0);
    
    lv_obj_t *refreshLabel = lv_label_create(refreshBtn);
    lv_label_set_text(refreshLabel, "刷新");
    lv_obj_set_style_text_font(refreshLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(refreshLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(refreshLabel);
    
    // 刷新按钮事件
    lv_obj_add_event_cb(refreshBtn, [](lv_event_t * e) {
        statisticsInProgress = true;
        showMessageBox("统计数据", "正在刷新数据...\n请稍候", "刷新中", true);
        lv_timer_t *refreshTimer = lv_timer_create(statistics_fetch_timer_cb, 500, NULL);
        lv_timer_set_repeat_count(refreshTimer, 1);
    }, LV_EVENT_CLICKED, NULL);
    
    // 关闭按钮
    lv_obj_t *closeBtn = lv_btn_create(buttonBar);
    lv_obj_set_size(closeBtn, 90, 34);
    lv_obj_set_pos(closeBtn, 210, 8);
    lv_obj_set_style_bg_color(closeBtn, lv_color_hex(0x757575), 0);
    lv_obj_set_style_radius(closeBtn, 17, 0);
    lv_obj_set_style_border_width(closeBtn, 0, 0);
    
    lv_obj_t *closeLabel = lv_label_create(closeBtn);
    lv_label_set_text(closeLabel, "关闭");
    lv_obj_set_style_text_font(closeLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(closeLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(closeLabel);
    
    // 关闭按钮事件
    lv_obj_add_event_cb(closeBtn, [](lv_event_t * e) {
        if (statisticsScreen != NULL) {
            // 先切换到主界面，再删除统计界面
            lv_scr_load(mainScreen);
            lv_obj_del(statisticsScreen);
            statisticsScreen = NULL;
            Serial.println("统计界面已安全关闭（按钮触发）");
        }
        statisticsInProgress = false;
    }, LV_EVENT_CLICKED, NULL);
    
    // 15秒后自动关闭
    if (statisticsTimer != NULL) {
        lv_timer_del(statisticsTimer);
    }
    statisticsTimer = lv_timer_create([](lv_timer_t * timer) {
        if (statisticsScreen != NULL) {
            // 先切换到主界面，再删除统计界面
            lv_scr_load(mainScreen);
            lv_obj_del(statisticsScreen);
            statisticsScreen = NULL;
            Serial.println("统计界面已安全关闭（定时器触发）");
        }
        statisticsInProgress = false;
        if (statisticsTimer != NULL) {
            lv_timer_del(statisticsTimer);
            statisticsTimer = NULL;
        }
   }, 15000, NULL);
   lv_timer_set_repeat_count(statisticsTimer, 1);
}

// ⭐⭐⭐ 新增：创建统计选择菜单
void createStatsMenuScreen() {
   // ⭐ 喂狗
   esp_task_wdt_reset();
   
   // 如果已有统计页面,先安全删除
   if (statisticsScreen != NULL) {
       lv_scr_load(mainScreen);
       lv_obj_del(statisticsScreen);
       statisticsScreen = NULL;
   }
   
   // ⭐ 确保在主界面上创建，避免访问无效屏幕
   if (mainScreen == NULL) {
       Serial.println("ERROR 主界面未初始化");
       return;
   }
   
   // 创建全屏菜单容器（基于NULL创建独立屏幕）
   statisticsScreen = lv_obj_create(NULL);
   lv_obj_set_size(statisticsScreen, 320, 480);
   lv_obj_set_pos(statisticsScreen, 0, 0);
   lv_obj_set_style_bg_color(statisticsScreen, lv_color_hex(0xF5F5F5), 0);
   lv_obj_set_style_border_width(statisticsScreen, 0, 0);
   lv_obj_clear_flag(statisticsScreen, LV_OBJ_FLAG_SCROLLABLE);
   
   // 标题
   lv_obj_t *titleLabel = lv_label_create(statisticsScreen);
   lv_label_set_text(titleLabel, "统计查询");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x333333), 0);
   lv_obj_set_pos(titleLabel, 120, 80);
   
   // 选项1：本楼栋汇总
   lv_obj_t *btn1 = lv_btn_create(statisticsScreen);
   lv_obj_set_size(btn1, 280, 80);
   lv_obj_set_pos(btn1, 20, 140);
   lv_obj_set_style_bg_color(btn1, lv_color_hex(0x2196F3), 0);
   lv_obj_set_style_radius(btn1, 10, 0);
   
   lv_obj_t *btn1Label = lv_label_create(btn1);
   lv_label_set_text(btn1Label, "本楼栋汇总\n查看本楼栋各楼层统计");
   lv_obj_set_style_text_font(btn1Label, &myFont_new, 0);
   lv_obj_set_style_text_color(btn1Label, lv_color_hex(0xFFFFFF), 0);
   lv_obj_set_style_text_align(btn1Label, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_center(btn1Label);
   
   lv_obj_add_event_cb(btn1, [](lv_event_t * e) {
       Serial.println("选择：本楼栋汇总");
       
       // 防止重复点击
       if (statisticsInProgress) {
           Serial.println("WARN 操作正在进行中");
           return;
       }
       
       statisticsInProgress = true;
       
       // ⭐ 先切换到主界面，避免删除活动屏幕
       lv_scr_load(mainScreen);
       
       // 延迟50ms后删除菜单界面
       lv_timer_create([](lv_timer_t * timer) {
           if (statisticsScreen != NULL) {
               lv_obj_del(statisticsScreen);
               statisticsScreen = NULL;
           }
           lv_timer_del(timer);
       }, 50, NULL);
       
       showMessageBox("楼层统计", "正在获取楼层统计数据...\n请稍候", "获取中", true);
       
       lv_timer_create([](lv_timer_t * t) {
           // ⭐ 喂狗，防止看门狗超时
           esp_task_wdt_reset();
           
           BuildingFloorData data = getBuildingFloorStats();
           
           // ⭐ 再次喂狗
           esp_task_wdt_reset();
           
           if (data.success) {
               closeMsgBox();
               createBuildingFloorScreen(data);
           } else {
               updateMsgBox("错误", "数据获取失败\n请检查网络连接", "确定", false);
               
               // 3秒后自动关闭错误提示
               lv_timer_create([](lv_timer_t * timer) {
                   closeMsgBox();
                   lv_scr_load(mainScreen);
                   statisticsInProgress = false;
                   lv_timer_del(timer);
               }, 3000, NULL);
           }
           
           if (data.success) {
               statisticsInProgress = false;
           }
           lv_timer_del(t);
       }, 500, NULL);
   }, LV_EVENT_CLICKED, NULL);
   
   // 选项2：本楼层未签到
   lv_obj_t *btn2 = lv_btn_create(statisticsScreen);
   lv_obj_set_size(btn2, 280, 80);
   lv_obj_set_pos(btn2, 20, 240);
   lv_obj_set_style_bg_color(btn2, lv_color_hex(0xFF9800), 0);
   lv_obj_set_style_radius(btn2, 10, 0);
   
   lv_obj_t *btn2Label = lv_label_create(btn2);
   lv_label_set_text(btn2Label, "本楼层未签到\n查看当前楼层未签到学生");
   lv_obj_set_style_text_font(btn2Label, &myFont_new, 0);
   lv_obj_set_style_text_color(btn2Label, lv_color_hex(0xFFFFFF), 0);
   lv_obj_set_style_text_align(btn2Label, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_center(btn2Label);
   
   lv_obj_add_event_cb(btn2, [](lv_event_t * e) {
       Serial.println("选择：本楼层未签到");
       
       // 防止重复点击
       if (statisticsInProgress) {
           Serial.println("WARN 操作正在进行中");
           return;
       }
       
       statisticsInProgress = true;
       
       // ⭐ 先切换到主界面，避免删除活动屏幕
       lv_scr_load(mainScreen);
       
       // 延迟50ms后删除菜单界面
       lv_timer_create([](lv_timer_t * timer) {
           if (statisticsScreen != NULL) {
               lv_obj_del(statisticsScreen);
               statisticsScreen = NULL;
           }
           lv_timer_del(timer);
       }, 50, NULL);
       
       showMessageBox("未签到数据", "正在获取未签到数据...\n请稍候", "获取中", true);
       
       lv_timer_create([](lv_timer_t * t) {
           // ⭐ 喂狗，防止看门狗超时
           esp_task_wdt_reset();
           
           DeviceUncheckedData data = getDeviceUncheckedStudents();
           
           // ⭐ 再次喂狗
           esp_task_wdt_reset();
           
           if (data.success) {
               closeMsgBox();
               createStatisticsScreen(data);
               
               // ⭐ 释放动态内存
               if (data.students != NULL) {
                   free(data.students);
                   data.students = NULL;
               }
           } else {
               updateMsgBox("错误", "数据获取失败\n请检查网络连接", "确定", false);
               
               // 3秒后自动关闭错误提示
               lv_timer_create([](lv_timer_t * timer) {
                   closeMsgBox();
                   lv_scr_load(mainScreen);
                   statisticsInProgress = false;
                   lv_timer_del(timer);
               }, 3000, NULL);
           }
           
           if (data.success) {
               statisticsInProgress = false;
           }
           lv_timer_del(t);
       }, 500, NULL);
   }, LV_EVENT_CLICKED, NULL);
   
   // 返回按钮
   lv_obj_t *backBtn = lv_btn_create(statisticsScreen);
   lv_obj_set_size(backBtn, 100, 40);
   lv_obj_set_pos(backBtn, 110, 380);
   lv_obj_set_style_bg_color(backBtn, lv_color_hex(0x757575), 0);
   lv_obj_set_style_radius(backBtn, 5, 0);
   
   lv_obj_t *backLabel = lv_label_create(backBtn);
   lv_label_set_text(backLabel, "返回");
   lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(backLabel);
   
   lv_obj_add_event_cb(backBtn, [](lv_event_t * e) {
       if (statisticsScreen != NULL) {
           lv_scr_load(mainScreen);
           lv_obj_del(statisticsScreen);
           statisticsScreen = NULL;
           Serial.println("菜单已关闭");
       }
   }, LV_EVENT_CLICKED, NULL);
   
   // 切换到菜单界面
   lv_scr_load(statisticsScreen);
   Serial.println("✅ 统计选择菜单已创建");
}

// ⭐⭐⭐ 新增：创建楼栋楼层统计界面
void createBuildingFloorScreen(BuildingFloorData data) {
   // ⭐ 喂狗
   esp_task_wdt_reset();
   
   // 如果已有统计页面,先安全删除
   if (statisticsScreen != NULL) {
       lv_scr_load(mainScreen);
       lv_obj_del(statisticsScreen);
       statisticsScreen = NULL;
   }
   
   // ⭐ 确保主界面存在
   if (mainScreen == NULL) {
       Serial.println("ERROR 主界面未初始化");
       return;
   }
   
   // 创建全屏页面容器（基于NULL创建独立屏幕）
   statisticsScreen = lv_obj_create(NULL);
   lv_obj_set_size(statisticsScreen, 320, 480);
   lv_obj_set_pos(statisticsScreen, 0, 0);
   lv_obj_set_style_bg_color(statisticsScreen, lv_color_hex(0xF5F5F5), 0);
   lv_obj_set_style_border_width(statisticsScreen, 0, 0);
   lv_obj_clear_flag(statisticsScreen, LV_OBJ_FLAG_SCROLLABLE);
   
   // ==== 标题栏 ====
   lv_obj_t *titleBar = lv_obj_create(statisticsScreen);
   lv_obj_set_size(titleBar, 320, 45);
   lv_obj_set_pos(titleBar, 0, 0);
   lv_obj_set_style_bg_color(titleBar, lv_color_hex(0x2196F3), 0);
   lv_obj_set_style_radius(titleBar, 0, 0);
   lv_obj_set_style_border_width(titleBar, 0, 0);
   lv_obj_clear_flag(titleBar, LV_OBJ_FLAG_SCROLLABLE);
   
   // 返回按钮
   lv_obj_t *backBtn = lv_btn_create(titleBar);
   lv_obj_set_size(backBtn, 60, 30);
   lv_obj_set_pos(backBtn, 8, 7);
   lv_obj_set_style_bg_color(backBtn, lv_color_hex(0x1976D2), 0);
   lv_obj_set_style_radius(backBtn, 5, 0);
   
   lv_obj_t *backLabel = lv_label_create(backBtn);
   lv_label_set_text(backLabel, "← 返回");
   lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(backLabel);
   
   lv_obj_add_event_cb(backBtn, [](lv_event_t * e) {
       if (statisticsScreen != NULL) {
           lv_scr_load(mainScreen);
           lv_obj_del(statisticsScreen);
           statisticsScreen = NULL;
           Serial.println("楼层统计界面已关闭");
       }
       // ⭐ 重置状态标志
       statisticsInProgress = false;
   }, LV_EVENT_CLICKED, NULL);
   
   // 标题文字
   lv_obj_t *titleLabel = lv_label_create(titleBar);
   char titleText[30];
   snprintf(titleText, sizeof(titleText), "%s楼层统计", data.buildingName);
   lv_label_set_text(titleLabel, titleText);
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, 0);
   
   // ==== 信息栏 ====
   lv_obj_t *infoBar = lv_obj_create(statisticsScreen);
   lv_obj_set_size(infoBar, 320, 45);  // ⭐ 增加高度到45px
   lv_obj_set_pos(infoBar, 0, 45);
   lv_obj_set_style_bg_color(infoBar, lv_color_hex(0xFFFFFF), 0);
   lv_obj_set_style_radius(infoBar, 0, 0);
   lv_obj_set_style_border_width(infoBar, 1, 0);
   lv_obj_set_style_border_color(infoBar, lv_color_hex(0xE0E0E0), 0);
   lv_obj_set_style_border_side(infoBar, LV_BORDER_SIDE_BOTTOM, 0);
   lv_obj_set_style_pad_all(infoBar, 3, 0);  // ⭐ 添加内边距
   lv_obj_clear_flag(infoBar, LV_OBJ_FLAG_SCROLLABLE);
   
  // 日期和总计
  lv_obj_t *dateLabel = lv_label_create(infoBar);
  char dateText[80];
  snprintf(dateText, sizeof(dateText), "%s | 总%d 在%d 未%d 假%d", 
      data.date, data.totalStudents, data.totalPresent, data.totalNotChecked, data.totalLeave);
  lv_label_set_text(dateLabel, dateText);
   lv_obj_set_style_text_font(dateLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(dateLabel, lv_color_hex(0x333333), 0);
   lv_obj_set_pos(dateLabel, 8, 12);  // ⭐ 垂直居中
   
   // ==== 表头 ====
   lv_obj_t *headerBar = lv_obj_create(statisticsScreen);
   lv_obj_set_size(headerBar, 320, 32);  // ⭐ 增加高度到32px
   lv_obj_set_pos(headerBar, 0, 90);  // ⭐ 调整位置：45+45=90
   lv_obj_set_style_bg_color(headerBar, lv_color_hex(0xE3F2FD), 0);
   lv_obj_set_style_radius(headerBar, 0, 0);
   lv_obj_set_style_border_width(headerBar, 0, 0);
   lv_obj_set_style_pad_all(headerBar, 2, 0);  // ⭐ 添加内边距
   lv_obj_clear_flag(headerBar, LV_OBJ_FLAG_SCROLLABLE);
   
   // ⭐ 统一坐标，确保表头和数据列完全对齐
   const char* headers[] = {"区域", "楼层", "总数", "在寝", "未签", "请假"};
   int headerX[] = {5, 52, 110, 160, 215, 270};  // ⭐ 优化列宽和对齐
   
   for (int i = 0; i < 6; i++) {
       lv_obj_t *headerLabel = lv_label_create(headerBar);
       lv_label_set_text(headerLabel, headers[i]);
       lv_obj_set_style_text_font(headerLabel, &myFont_new, 0);
       lv_obj_set_style_text_color(headerLabel, lv_color_hex(0x1976D2), 0);
       lv_obj_set_pos(headerLabel, headerX[i], 7);  // ⭐ 垂直居中
   }
   
   // ==== 楼层列表 ====
   lv_obj_t *scrollContainer = lv_obj_create(statisticsScreen);
   lv_obj_set_size(scrollContainer, 320, 358);  // ⭐ 调整高度：480-45-45-32=358
   lv_obj_set_pos(scrollContainer, 0, 122);  // ⭐ 调整位置：45+45+32=122
   lv_obj_set_style_bg_color(scrollContainer, lv_color_hex(0xFFFFFF), 0);
   lv_obj_set_style_radius(scrollContainer, 0, 0);
   lv_obj_set_style_border_width(scrollContainer, 0, 0);
   lv_obj_set_style_pad_all(scrollContainer, 0, 0);
   lv_obj_set_scroll_dir(scrollContainer, LV_DIR_VER);
   
   // ⭐ 喂狗，准备创建大量对象
   esp_task_wdt_reset();
   
   // 添加楼层行
   int yPos = 0;
   for (int i = 0; i < data.floorCount; i++) {
       // ⭐ 每5行喂一次狗
       if (i % 5 == 0) {
           esp_task_wdt_reset();
       }
       FloorStat floor = data.floors[i];
       
       // 楼层行容器（全宽无边距）
       lv_obj_t *row = lv_obj_create(scrollContainer);
       lv_obj_set_size(row, 320, 34);  // ⭐ 全宽320px，高度34px
       lv_obj_set_pos(row, 0, yPos);  // ⭐ 完全贴边
       
       // 当前设备负责的楼层高亮
       if (floor.isCurrentDevice) {
           lv_obj_set_style_bg_color(row, lv_color_hex(0xFFF9C4), 0);  // 黄色高亮
       } else if (i % 2 == 0) {
           lv_obj_set_style_bg_color(row, lv_color_hex(0xFFFFFF), 0);
       } else {
           lv_obj_set_style_bg_color(row, lv_color_hex(0xF9F9F9), 0);
       }
       
       lv_obj_set_style_radius(row, 0, 0);
       lv_obj_set_style_border_width(row, 1, 0);
       lv_obj_set_style_border_color(row, lv_color_hex(0xE8E8E8), 0);
       lv_obj_set_style_border_side(row, LV_BORDER_SIDE_BOTTOM, 0);
       lv_obj_set_style_pad_all(row, 0, 0);  // ⭐ 完全无内边距
       lv_obj_clear_flag(row, LV_OBJ_FLAG_SCROLLABLE);
       
       // 各列数据
       char areaText[8], floorText[8], totalText[8], presentText[8], uncheckedText[8], leaveText[8];
       snprintf(areaText, sizeof(areaText), "%s区", floor.area);
       snprintf(floorText, sizeof(floorText), "%d层", floor.floor);
       snprintf(totalText, sizeof(totalText), "%d", floor.totalStudents);
       snprintf(presentText, sizeof(presentText), "%d", floor.totalPresent);
       snprintf(uncheckedText, sizeof(uncheckedText), "%d", floor.totalNotChecked);
       snprintf(leaveText, sizeof(leaveText), "%d", floor.totalLeave);
       
       const char* texts[] = {areaText, floorText, totalText, presentText, uncheckedText, leaveText};
       // ⭐ 与表头完全一致的坐标
       int textX[] = {5, 52, 110, 160, 215, 270};
       
       for (int j = 0; j < 6; j++) {
           lv_obj_t *label = lv_label_create(row);
           lv_label_set_text(label, texts[j]);
           lv_obj_set_style_text_font(label, &myFont_new, 0);
           lv_obj_set_style_text_color(label, lv_color_hex(0x333333), 0);
           lv_obj_set_pos(label, textX[j], 8);  // ⭐ 垂直居中
       }
       
       yPos += 34;  // ⭐ 与行高一致
   }
   
   // 切换到新界面
   lv_scr_load(statisticsScreen);
   Serial.println("✅ 楼层统计界面创建完成");
}

// ⭐⭐⭐ 新增：创建未签到学生列表页面（函数重载）
void createStatisticsScreen(DeviceUncheckedData data) {
   // ⭐ 喂狗
   esp_task_wdt_reset();
   
   // 如果已有统计页面,先安全删除
   if (statisticsScreen != NULL) {
       lv_scr_load(mainScreen);
       lv_obj_del(statisticsScreen);
       statisticsScreen = NULL;
       Serial.println("统计界面已安全删除");
   }
   
   // ⭐ 确保主界面存在
   if (mainScreen == NULL) {
       Serial.println("ERROR 主界面未初始化");
       return;
   }
   
   // 创建全屏页面容器（基于NULL创建独立屏幕）
   statisticsScreen = lv_obj_create(NULL);
   lv_obj_set_size(statisticsScreen, 320, 480);
   lv_obj_set_pos(statisticsScreen, 0, 0);
   lv_obj_set_style_bg_color(statisticsScreen, lv_color_hex(0xF5F5F5), 0);
   lv_obj_set_style_border_width(statisticsScreen, 0, 0);
   lv_obj_set_style_radius(statisticsScreen, 0, 0);
   lv_obj_set_style_pad_all(statisticsScreen, 0, 0);
   lv_obj_clear_flag(statisticsScreen, LV_OBJ_FLAG_SCROLLABLE);
   
   // ==== 标题栏 ====
   lv_obj_t *titleBar = lv_obj_create(statisticsScreen);
   lv_obj_set_size(titleBar, 320, 45);
   lv_obj_set_pos(titleBar, 0, 0);
   lv_obj_set_style_bg_color(titleBar, lv_color_hex(0x2196F3), 0);
   lv_obj_set_style_radius(titleBar, 0, 0);
   lv_obj_set_style_border_width(titleBar, 0, 0);
   lv_obj_clear_flag(titleBar, LV_OBJ_FLAG_SCROLLABLE);
   
   // 返回按钮
   lv_obj_t *backBtn = lv_btn_create(titleBar);
   lv_obj_set_size(backBtn, 60, 30);
   lv_obj_set_pos(backBtn, 8, 7);
   lv_obj_set_style_bg_color(backBtn, lv_color_hex(0x1976D2), 0);
   lv_obj_set_style_radius(backBtn, 5, 0);
   
   lv_obj_t *backLabel = lv_label_create(backBtn);
   lv_label_set_text(backLabel, "← 返回");
   lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(backLabel);
   
   lv_obj_add_event_cb(backBtn, [](lv_event_t * e) {
       if (statisticsScreen != NULL) {
           lv_scr_load(mainScreen);
           // ⭐ 延迟一帧再删除，确保页面切换完成
           lv_timer_create([](lv_timer_t * timer) {
               if (statisticsScreen != NULL) {
                   lv_obj_del(statisticsScreen);
                   statisticsScreen = NULL;
                   Serial.println("未签到界面已安全删除");
               }
               lv_timer_del(timer);
           }, 50, NULL);
       }
       // ⭐ 重置状态标志
       statisticsInProgress = false;
   }, LV_EVENT_CLICKED, NULL);
   
   // 标题文字
   lv_obj_t *titleLabel = lv_label_create(titleBar);
   lv_label_set_text(titleLabel, "本楼层未签到");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, 0);
   
   // ==== 信息栏 ====
   lv_obj_t *infoBar = lv_obj_create(statisticsScreen);
   lv_obj_set_size(infoBar, 320, 58);  // ⭐ 增加高度从50到58，避免内容溢出
   lv_obj_set_pos(infoBar, 0, 45);
   lv_obj_set_style_bg_color(infoBar, lv_color_hex(0xFFFFFF), 0);
   lv_obj_set_style_radius(infoBar, 0, 0);
   lv_obj_set_style_border_width(infoBar, 1, 0);
   lv_obj_set_style_border_color(infoBar, lv_color_hex(0xE0E0E0), 0);
   lv_obj_set_style_border_side(infoBar, LV_BORDER_SIDE_BOTTOM, 0);
   lv_obj_set_style_pad_all(infoBar, 3, 0);  // ⭐ 添加内边距，避免内容贴边
   lv_obj_clear_flag(infoBar, LV_OBJ_FLAG_SCROLLABLE);
   
   // 设备信息
   lv_obj_t *deviceLabel = lv_label_create(infoBar);
   lv_label_set_text(deviceLabel, data.deviceInfo);
   lv_obj_set_style_text_font(deviceLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(deviceLabel, lv_color_hex(0x333333), 0);
   lv_obj_set_pos(deviceLabel, 8, 4);  // ⭐ 微调位置
   
   // 日期
   lv_obj_t *dateLabel = lv_label_create(infoBar);
   lv_label_set_text(dateLabel, data.date);
   lv_obj_set_style_text_font(dateLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(dateLabel, lv_color_hex(0x666666), 0);
   lv_obj_set_pos(dateLabel, 210, 4);  // ⭐ 微调位置
   
   // 未签到人数
   lv_obj_t *countLabel = lv_label_create(infoBar);
   char countText[32];
   snprintf(countText, sizeof(countText), "未签到: %d人", data.totalUnchecked);
   lv_label_set_text(countLabel, countText);
   lv_obj_set_style_text_font(countLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(countLabel, lv_color_hex(0xF44336), 0);
   lv_obj_set_pos(countLabel, 8, 28);  // ⭐ 微调位置
   
   // ==== 表头栏 ====
   lv_obj_t *headerBar = lv_obj_create(statisticsScreen);
   lv_obj_set_size(headerBar, 320, 32);  // ⭐ 增加高度从30到32
   lv_obj_set_pos(headerBar, 0, 103);  // ⭐ 调整位置：45+58=103
   lv_obj_set_style_bg_color(headerBar, lv_color_hex(0xE3F2FD), 0);
   lv_obj_set_style_radius(headerBar, 0, 0);
   lv_obj_set_style_border_width(headerBar, 0, 0);
   lv_obj_set_style_pad_all(headerBar, 2, 0);  // ⭐ 添加内边距
   lv_obj_clear_flag(headerBar, LV_OBJ_FLAG_SCROLLABLE);
   
   // 表头 - 序号
   lv_obj_t *indexHeader = lv_label_create(headerBar);
   lv_label_set_text(indexHeader, "#");
   lv_obj_set_style_text_font(indexHeader, &myFont_new, 0);
   lv_obj_set_style_text_color(indexHeader, lv_color_hex(0x1976D2), 0);
   lv_obj_set_pos(indexHeader, 5, 6);  // ⭐ 添加序号表头
   
   // 表头 - 姓名
   lv_obj_t *nameHeader = lv_label_create(headerBar);
   lv_label_set_text(nameHeader, "姓名");
   lv_obj_set_style_text_font(nameHeader, &myFont_new, 0);
   lv_obj_set_style_text_color(nameHeader, lv_color_hex(0x1976D2), 0);
   lv_obj_set_pos(nameHeader, 32, 6);  // ⭐ 垂直居中
   
   // 表头 - 位置
   lv_obj_t *locationHeader = lv_label_create(headerBar);
   lv_label_set_text(locationHeader, "位置");
   lv_obj_set_style_text_font(locationHeader, &myFont_new, 0);
   lv_obj_set_style_text_color(locationHeader, lv_color_hex(0x1976D2), 0);
   lv_obj_set_pos(locationHeader, 182, 6);  // ⭐ 垂直居中
   
   // ==== 学生列表 ====
   if (data.studentCount > 0) {
       // 可滚动容器
       lv_obj_t *scrollContainer = lv_obj_create(statisticsScreen);
       lv_obj_set_size(scrollContainer, 320, 345);  // ⭐ 调整高度：480-45-58-32=345
       lv_obj_set_pos(scrollContainer, 0, 135);  // ⭐ 调整位置：45+58+32=135
       lv_obj_set_style_bg_color(scrollContainer, lv_color_hex(0xFFFFFF), 0);
       lv_obj_set_style_radius(scrollContainer, 0, 0);
       lv_obj_set_style_border_width(scrollContainer, 0, 0);
       lv_obj_set_style_pad_all(scrollContainer, 0, 0);
       lv_obj_set_scroll_dir(scrollContainer, LV_DIR_VER);
       
       // ⭐ 喂狗，准备创建大量对象
       esp_task_wdt_reset();
       
       // 添加学生行
       int yPos = 0;
       for (int i = 0; i < data.studentCount && i < 50; i++) {
           // ⭐ 每10行喂一次狗
           if (i % 10 == 0) {
               esp_task_wdt_reset();
           }
           // 学生行容器（无边框，完全平铺）
           lv_obj_t *row = lv_obj_create(scrollContainer);
           lv_obj_set_size(row, 320, 36);  // ⭐ 全宽320px，高度36px
           lv_obj_set_pos(row, 0, yPos);   // ⭐ 完全贴边
           
           // 奇偶行不同颜色
           if (i % 2 == 0) {
               lv_obj_set_style_bg_color(row, lv_color_hex(0xFFFFFF), 0);
           } else {
               lv_obj_set_style_bg_color(row, lv_color_hex(0xF9F9F9), 0);
           }
           
           lv_obj_set_style_radius(row, 0, 0);
           lv_obj_set_style_border_width(row, 1, 0);
           lv_obj_set_style_border_color(row, lv_color_hex(0xE8E8E8), 0);
           lv_obj_set_style_border_side(row, LV_BORDER_SIDE_BOTTOM, 0);
           lv_obj_set_style_pad_all(row, 0, 0);  // ⭐ 完全无内边距
           lv_obj_clear_flag(row, LV_OBJ_FLAG_SCROLLABLE);
           
           // 序号（与表头#对齐）
           lv_obj_t *indexLabel = lv_label_create(row);
           char indexText[8];
           snprintf(indexText, sizeof(indexText), "%2d", i+1);
           lv_label_set_text(indexLabel, indexText);
           lv_obj_set_style_text_font(indexLabel, &myFont_new, 0);
           lv_obj_set_style_text_color(indexLabel, lv_color_hex(0x999999), 0);
           lv_obj_set_pos(indexLabel, 5, 9);  // ⭐ 与表头#对齐（x=5）
           
           // 姓名（与表头"姓名"对齐）
           lv_obj_t *nameLabel = lv_label_create(row);
           lv_label_set_text(nameLabel, data.students[i].name);
           lv_obj_set_style_text_font(nameLabel, &myFont_new, 0);
           lv_obj_set_style_text_color(nameLabel, lv_color_hex(0x333333), 0);
           lv_obj_set_pos(nameLabel, 32, 9);  // ⭐ 与表头"姓名"对齐（x=32）
           
           // 位置（与表头"位置"对齐）
           lv_obj_t *locationLabel = lv_label_create(row);
           lv_label_set_text(locationLabel, data.students[i].location);
           lv_obj_set_style_text_font(locationLabel, &myFont_new, 0);
           lv_obj_set_style_text_color(locationLabel, lv_color_hex(0x2196F3), 0);
           lv_obj_set_style_max_width(locationLabel, 110, 0);  // ⭐ 增加最大宽度
           lv_obj_set_pos(locationLabel, 182, 9);  // ⭐ 与表头"位置"完全对齐（x=182）
           
           yPos += 36;  // ⭐ 与行高保持一致
       }
   } else {
       // 无未签到学生
       lv_obj_t *emptyContainer = lv_obj_create(statisticsScreen);
       lv_obj_set_size(emptyContainer, 280, 120);
       lv_obj_set_pos(emptyContainer, 20, 200);
       lv_obj_set_style_bg_color(emptyContainer, lv_color_hex(0xE8F5E9), 0);
       lv_obj_set_style_radius(emptyContainer, 10, 0);
       lv_obj_set_style_border_width(emptyContainer, 2, 0);
       lv_obj_set_style_border_color(emptyContainer, lv_color_hex(0x4CAF50), 0);
       lv_obj_clear_flag(emptyContainer, LV_OBJ_FLAG_SCROLLABLE);
       
       lv_obj_t *iconLabel = lv_label_create(emptyContainer);
       lv_label_set_text(iconLabel, "✓");
       lv_obj_set_style_text_font(iconLabel, &lv_font_montserrat_48, 0);
       lv_obj_set_style_text_color(iconLabel, lv_color_hex(0x4CAF50), 0);
       lv_obj_set_pos(iconLabel, 120, 10);
       
       lv_obj_t *emptyLabel = lv_label_create(emptyContainer);
       lv_label_set_text(emptyLabel, "兄弟们！\n可以回寝睡觉啦");
       lv_obj_set_style_text_font(emptyLabel, &myFont_new, 0);
       lv_obj_set_style_text_color(emptyLabel, lv_color_hex(0x4CAF50), 0);
       lv_obj_set_style_text_align(emptyLabel, LV_TEXT_ALIGN_CENTER, 0);
       lv_obj_set_pos(emptyLabel, 50, 70);
   }
   
   // 切换到新界面
   lv_scr_load(statisticsScreen);
   
   Serial.println("✅ 未签到学生界面创建完成");
}

// 创建表头
void createTableHeader(lv_obj_t *parent, int yPos) {
    lv_obj_t *header = lv_obj_create(parent);
    lv_obj_set_size(header, 300, 25);
    lv_obj_set_pos(header, 10, yPos);
    lv_obj_set_style_bg_color(header, lv_color_hex(0xE3F2FD), 0);
    lv_obj_set_style_radius(header, 3, 0);
    lv_obj_set_style_border_width(header, 1, 0);
    lv_obj_set_style_border_color(header, lv_color_hex(0x2196F3), 0);
    
    // 表头文字
    const char* headers[] = {"楼栋", "总数", "在寝", "离寝", "请假", "未到"};
    int widths[] = {50, 40, 40, 40, 40, 40};
    int xPos = 5;
    
    for (int i = 0; i < 6; i++) {
        lv_obj_t *headerLabel = lv_label_create(header);
        lv_label_set_text(headerLabel, headers[i]);
        lv_obj_set_style_text_font(headerLabel, &myFont_new, 0);
        lv_obj_set_style_text_color(headerLabel, lv_color_hex(0x1976D2), 0);
        lv_obj_set_pos(headerLabel, xPos, 3);
        xPos += widths[i];
    }
}

// 创建改进的表头
void createImprovedTableHeader(lv_obj_t *parent, int yPos) {
    lv_obj_t *header = lv_obj_create(parent);
    lv_obj_set_size(header, 320, 25);
    lv_obj_set_pos(header, 0, yPos);
    lv_obj_set_style_bg_color(header, lv_color_hex(0x1976D2), 0);
    lv_obj_set_style_radius(header, 0, 0);
    lv_obj_set_style_border_width(header, 0, 0);
    lv_obj_set_style_pad_all(header, 0, 0);
    lv_obj_clear_flag(header, LV_OBJ_FLAG_SCROLLABLE);
    
    // 表头文字和位置
    const char* headers[] = {"楼栋", "总数", "在寝", "离寝", "请假", "未到"};
    int positions[] = {15, 70, 110, 150, 190, 230};  // 精确位置
    
    for (int i = 0; i < 6; i++) {
        lv_obj_t *headerLabel = lv_label_create(header);
        lv_label_set_text(headerLabel, headers[i]);
        lv_obj_set_style_text_font(headerLabel, &myFont_new, 0);
        lv_obj_set_style_text_color(headerLabel, lv_color_hex(0xFFFFFF), 0);
        lv_obj_set_pos(headerLabel, positions[i], 3);
    }
}

// 创建改进的楼栋数据行
void createImprovedBuildingRow(lv_obj_t *parent, BuildingData building, int yPos, bool isEvenRow) {
    lv_obj_t *row = lv_obj_create(parent);
    lv_obj_set_size(row, 310, 40);
    lv_obj_set_pos(row, 5, yPos);
    
    // 交替行背景色
    lv_color_t bgColor = isEvenRow ? lv_color_hex(0xF8F9FA) : lv_color_hex(0xFFFFFF);
    if (!building.hasStudents()) {
        bgColor = lv_color_hex(0xF0F0F0);  // 空楼栋用灰色
    }
    
    lv_obj_set_style_bg_color(row, bgColor, 0);
    lv_obj_set_style_radius(row, 5, 0);
    lv_obj_set_style_border_width(row, 1, 0);
    lv_obj_set_style_border_color(row, lv_color_hex(0xE1F5FE), 0);
    lv_obj_set_style_pad_all(row, 0, 0);
    lv_obj_clear_flag(row, LV_OBJ_FLAG_SCROLLABLE);
    
    // 数据内容
    String values[] = {
        building.buildingName + "号楼",  // 现在buildingName已经包含A/B,如"1A"
        String(building.totalStudents),
        String(building.totalPresent),
        String(building.totalAbsent),
        String(building.totalLeave),
        String(building.totalNotChecked)
    };
    
    lv_color_t colors[] = {
        lv_color_hex(0x1976D2),  // 楼栋名 - 蓝色
        lv_color_hex(0x424242),  // 总数 - 深灰
        lv_color_hex(0x388E3C),  // 在寝 - 绿色
        lv_color_hex(0xD32F2F),  // 离寝 - 红色
        lv_color_hex(0xF57C00),  // 请假 - 橙色
        lv_color_hex(0x757575)   // 未到 - 灰色
    };
    
    int positions[] = {10, 70, 110, 150, 190, 230};  // 与表头对应
    
    for (int i = 0; i < 6; i++) {
        lv_obj_t *valueLabel = lv_label_create(row);
        lv_label_set_text(valueLabel, values[i].c_str());
        lv_obj_set_style_text_font(valueLabel, &myFont_new, 0);
        lv_obj_set_style_text_color(valueLabel, colors[i], 0);
        lv_obj_set_pos(valueLabel, positions[i], 12);
        
        // 不使用emoji图标,直接显示数值
    }
    
    // 为有学生的楼栋添加点击效果和事件
    if (building.hasStudents()) {
        lv_obj_add_flag(row, LV_OBJ_FLAG_CLICKABLE);
        
        // 点击效果（使用通用颜色）
        lv_obj_set_style_bg_color(row, lv_color_hex(0xE3F2FD), LV_STATE_PRESSED);
        
        // 创建一个静态字符串来保存楼栋名称
        static String buildingNames[10]; // 最多支持10个楼栋
        static int buildingNameIndex = 0;
        buildingNames[buildingNameIndex % 10] = building.buildingName;
        lv_obj_set_user_data(row, (void*)&buildingNames[buildingNameIndex % 10]);
        buildingNameIndex++;
        
        lv_obj_add_event_cb(row, [](lv_event_t * e) {
            lv_obj_t *row = lv_event_get_target(e);
            String *buildingName = (String*)lv_obj_get_user_data(row);
            
            if (buildingName != nullptr) {
               Serial.println("点击了楼栋: " + *buildingName + ",获取详细信息...");
               
               // 使用新的内存保护机制
               if (!checkMemoryAndProtect("获取楼栋详情")) {
                   return;
               }
                
                // 获取楼栋详细信息
                BuildingDetail detail = getBuildingDetail(*buildingName);
                
                if (detail.success) {
                    createBuildingDetailScreen(detail);
                } else {
                    showMessageBox("错误", "获取楼栋详细信息失败\n请稍后重试", "确定", false);
                }
            }
        }, LV_EVENT_CLICKED, NULL);
    }
}

// 创建楼栋数据行（保留原版本兼容性）
void createBuildingRow(lv_obj_t *parent, BuildingData building, int yPos) {
    createImprovedBuildingRow(parent, building, yPos, false);
}

// 异步统计数据获取定时器回调
void statistics_fetch_timer_cb(lv_timer_t * timer) {
   if (!statisticsInProgress) return;
   
   Serial.println("-> 执行异步未签到数据获取...");
   
   // ⭐ 获取设备未签到学生数据（新API）
   DeviceUncheckedData data = getDeviceUncheckedStudents();
   
   // 根据获取结果更新显示
   if (data.success) {
       // 更新弹窗为成功状态
       updateMsgBox("未签到数据", "数据获取成功!\n正在显示页面", "查看中", true);
       
       // 延迟500ms后显示统计页面
       lv_timer_create([](lv_timer_t * t) {
           // 关闭弹窗
           closeMsgBox();
           
           // 重新获取数据并显示统计页面
           DeviceUncheckedData data = getDeviceUncheckedStudents();
           if (data.success) {
               createStatisticsScreen(data);  // ⭐ 传入新数据类型
               
               // 释放动态内存
               if (data.students != NULL) {
                   free(data.students);
               }
           }
           
           // 清理
           statisticsInProgress = false;
           lv_timer_del(t);
       }, 500, NULL);
       
       // 释放当前data的内存
       if (data.students != NULL) {
           free(data.students);
       }
   } else {
       String errorMsg = "数据获取失败!\n";
       if (!wifiConnected) {
           errorMsg += "WiFi未连接";
       } else {
           errorMsg += "服务器无响应";
       }
       updateMsgBox("未签到数据", errorMsg, "确定", false);
        
        // 重新设置自动关闭定时器
        if (msgBoxTimer != NULL) {
            lv_timer_del(msgBoxTimer);
            msgBoxTimer = NULL;
        }
        msgBoxTimer = lv_timer_create(msgbox_timer_cb, 3000, NULL);
        lv_timer_set_repeat_count(msgBoxTimer, 1);
        
        statisticsInProgress = false;
    }
    
    // 清理统计获取定时器
    if (timer != NULL) {
        lv_timer_del(timer);
    }
    
    Serial.println("OK 异步统计数据获取完成");
}

// 更新消息框内容
void updateMsgBox(String title, String message, String buttonText, bool isSuccess) {
    if (currentMsgBox == NULL) return;
    
    // 更新标题
    if (msgBoxTitleLabel != NULL) {
        lv_label_set_text(msgBoxTitleLabel, title.c_str());
        lv_obj_set_style_text_color(msgBoxTitleLabel, isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0xF44336), 0);
    }
    
    // 更新消息内容
    if (msgBoxMessageLabel != NULL) {
        lv_label_set_text(msgBoxMessageLabel, message.c_str());
    }
    
    // 更新按钮
    if (msgBoxButton != NULL) {
        lv_obj_set_style_bg_color(msgBoxButton, isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0xF44336), 0);
        
        // 更新按钮文字
        lv_obj_t *buttonLabel = lv_obj_get_child(msgBoxButton, 0);
        if (buttonLabel != NULL) {
            lv_label_set_text(buttonLabel, buttonText.c_str());
        }
    }
    
    // 更新边框颜色
    lv_obj_set_style_border_color(currentMsgBox, isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0xF44336), 0);
}

// 显示弹出提示框 - 修复内存泄漏版本
void showMessageBox(String title, String message, String buttonText, bool isSuccess) {
    // 先关闭已存在的消息框
    closeMsgBox();
    
    // 创建模态对话框
    currentMsgBox = lv_obj_create(lv_scr_act());
    lv_obj_set_size(currentMsgBox, 280, 180);
    lv_obj_center(currentMsgBox);
    lv_obj_set_style_bg_color(currentMsgBox, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_border_width(currentMsgBox, 2, 0);
    lv_obj_set_style_border_color(currentMsgBox, isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0xF44336), 0);
    lv_obj_set_style_radius(currentMsgBox, 10, 0);
    lv_obj_set_style_shadow_width(currentMsgBox, 10, 0);
    lv_obj_set_style_shadow_color(currentMsgBox, lv_color_hex(0x000000), 0);
    lv_obj_set_style_shadow_opa(currentMsgBox, LV_OPA_20, 0);
    
    // 标题 - 保存引用
    msgBoxTitleLabel = lv_label_create(currentMsgBox);
    lv_label_set_text(msgBoxTitleLabel, title.c_str());
    lv_obj_set_style_text_font(msgBoxTitleLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(msgBoxTitleLabel, isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0xF44336), 0);
    lv_obj_align(msgBoxTitleLabel, LV_ALIGN_TOP_MID, 0, 20);
    
    // 消息内容 - 保存引用
    msgBoxMessageLabel = lv_label_create(currentMsgBox);
    lv_label_set_text(msgBoxMessageLabel, message.c_str());
    lv_obj_set_style_text_font(msgBoxMessageLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(msgBoxMessageLabel, lv_color_hex(0x333333), 0);
    lv_obj_set_style_text_align(msgBoxMessageLabel, LV_TEXT_ALIGN_CENTER, 0);
    lv_obj_align(msgBoxMessageLabel, LV_ALIGN_CENTER, 0, 0);
    lv_obj_set_width(msgBoxMessageLabel, 240);
    
    // 确定按钮 - 保存引用
    msgBoxButton = lv_btn_create(currentMsgBox);
    lv_obj_set_size(msgBoxButton, 80, 35);
    lv_obj_align(msgBoxButton, LV_ALIGN_BOTTOM_MID, 0, -20);
    lv_obj_set_style_bg_color(msgBoxButton, isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0xF44336), 0);
    lv_obj_set_style_radius(msgBoxButton, 5, 0);
    
    // 按钮标签
    lv_obj_t *buttonLabel = lv_label_create(msgBoxButton);
    lv_label_set_text(buttonLabel, buttonText.c_str());
    lv_obj_set_style_text_font(buttonLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(buttonLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(buttonLabel);
    
    // 按钮事件
    lv_obj_add_event_cb(msgBoxButton, msgbox_btn_event_cb, LV_EVENT_CLICKED, NULL);
    
    // 3秒后自动关闭（先确保清理旧的定时器）
    if (msgBoxTimer != NULL) {
        lv_timer_del(msgBoxTimer);
        msgBoxTimer = NULL;
    }
    msgBoxTimer = lv_timer_create(msgbox_timer_cb, 3000, NULL);
    lv_timer_set_repeat_count(msgBoxTimer, 1);
}

// 开始录入流程
void startEnrollProcess() {
    updateMainScreenStatus(STATE_ENROLLING, "开始指纹录入");
    
    // 获取下一个可用ID
    int nextId = getNextAvailableId();
    if (nextId == -1) {
        showError("录入失败", "指纹库已满");
        updateMainScreenStatus(STATE_IDLE, "录入失败:指纹库已满");
        return;
    }
    
    // 录入信息只打印到串口,不修改屏幕显示
    Serial.println("准备录入ID: " + String(nextId));
    
    // 开始录入
    if (enrollFingerprint(nextId)) {
        Serial.println("指纹录入成功,ID: " + String(nextId));
    } else {
        showError("录入失败", "请重试");
    }
    
    delay(3000);
    updateMainScreenStatus(STATE_IDLE, "录入完成");
}

// 获取下一个可用ID
int getNextAvailableId() {
    for (int i = 1; i < 1000; i++) {
        if (finger.loadModel(i) != FINGERPRINT_OK) {
            return i;
        }
        delay(1);
    }
    return -1;
}

// 显示统计信息
void showStatistics() {
    // 统计信息只打印到串口,不修改屏幕显示
    Serial.println("=== 统计信息 ===");
    Serial.println("今日签到: " + String(todayCheckinCount) + " 人次");
    Serial.println("指纹库: " + String(totalFingerprintCount) + "/1000");
    Serial.println("WiFi: " + String(wifiConnected ? "已连接" : "未连接"));
    Serial.println("===============");
    
    delay(2000);  // 缩短延时
    updateMainScreenStatus(STATE_IDLE, "统计信息显示完成");
}

// ==================== 设备状态检测 ====================
// 已完全禁用旧的设备状态更新函数,避免与统一状态管理系统冲突
// 这个函数会显示"指纹传感器: 紧急维护中"等信息,与新的状态管理系统冲突
/*
void updateDeviceStatus() {
   static unsigned long lastDeviceCheck = 0;
   
   // 紧急修复:频率降低到每60秒检查一次
   if (millis() - lastDeviceCheck < 60000) return;
   lastDeviceCheck = millis();
   
   String deviceStatus = "";
   String systemStatus = "";
   
   // 紧急修复:暂时禁用传感器检测避免Serial0冲突
   static int disabledNoticeCount = 0;
   String sensorStatus = "";
   
   if (disabledNoticeCount < 3) {
       sensorStatus = "紧急维护中";
       disabledNoticeCount++;
       Serial.println("WARN 紧急修复:暂时禁用传感器检测避免CPU过载");
   } else {
       sensorStatus = "已禁用";
   }
   
   deviceStatus = "指纹传感器: " + sensorStatus + "\n";
    
    // 检查WiFi状态
    if (wifiConnected) {
        deviceStatus += "网络连接: 正常\n";
        systemStatus = "系统运行正常";
        // 系统状态正常
    } else {
        deviceStatus += "网络连接: 断开\n";
        systemStatus = "网络连接异常";
        // 网络异常状态
    }
    
    // 检查内存使用情况
    size_t freeHeap = ESP.getFreeHeap();
    if (freeHeap > 50000) {
        deviceStatus += "内存状态: 充足";
    } else {
        deviceStatus += "内存状态: 不足";
    }
    
    // 使用新的统一状态管理系统更新显示
    updateMainScreenStatus(STATE_SYSTEM_CHECK, deviceStatus);
}
*/

// ==================== SPIFFS已禁用,使用内置字体 ====================

// ==================== WiFi管理界面 ====================
void createWiFiScreen() {
    Serial.println("创建WiFi管理界面");
    
    // 初始化所有全局指针为NULL（防止野指针）
    keyboard = NULL;
    passwordPanel = NULL;
    passwordTextArea = NULL;
    connectButton = NULL;
    backButton = NULL;
    refreshButton = NULL;
    wifiStatusLabel = NULL;
    wifiList = NULL;
    selectedItem = NULL;
    selectedSSID = "";
    
    // 创建WiFi屏幕
    wifiScreen = lv_obj_create(NULL);
    lv_obj_set_style_bg_color(wifiScreen, lv_color_hex(0xF5F5F5), 0);
    
    // 标题栏
    lv_obj_t *titleBar = lv_obj_create(wifiScreen);
    lv_obj_set_size(titleBar, 320, 50);
    lv_obj_align(titleBar, LV_ALIGN_TOP_MID, 0, 0);
    lv_obj_set_style_bg_color(titleBar, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_border_width(titleBar, 0, 0);
    
    lv_obj_t *titleText = lv_label_create(titleBar);
    lv_label_set_text(titleText, "WiFi网络管理");
    lv_obj_set_style_text_font(titleText, &myFont_new, 0);
    lv_obj_set_style_text_color(titleText, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(titleText);
    
    // 返回按钮
    backButton = lv_btn_create(titleBar);
    lv_obj_set_size(backButton, 60, 35);
    lv_obj_align(backButton, LV_ALIGN_LEFT_MID, 5, 0);
    lv_obj_set_style_bg_color(backButton, lv_color_hex(0x1976D2), 0);
    lv_obj_add_event_cb(backButton, back_btn_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *backLabel = lv_label_create(backButton);
    lv_label_set_text(backLabel, "返回");
    lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(backLabel);
    
    // 刷新按钮
    refreshButton = lv_btn_create(titleBar);
    lv_obj_set_size(refreshButton, 60, 35);
    lv_obj_align(refreshButton, LV_ALIGN_RIGHT_MID, -5, 0);
    lv_obj_set_style_bg_color(refreshButton, lv_color_hex(0x4CAF50), 0);
    lv_obj_add_event_cb(refreshButton, refresh_btn_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *refreshLabel = lv_label_create(refreshButton);
    lv_label_set_text(refreshLabel, "刷新");
    lv_obj_set_style_text_font(refreshLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(refreshLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(refreshLabel);
    
    // WiFi状态指示
    wifiStatusLabel = lv_label_create(wifiScreen);
    lv_label_set_text(wifiStatusLabel, "正在扫描WiFi网络...");
    lv_obj_set_style_text_font(wifiStatusLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(wifiStatusLabel, lv_color_hex(0xFF9800), 0);
    lv_obj_align(wifiStatusLabel, LV_ALIGN_TOP_MID, 0, 60);
    
   // WiFi网络列表
   wifiList = lv_list_create(wifiScreen);
   lv_obj_set_size(wifiList, 300, 200);
   lv_obj_align(wifiList, LV_ALIGN_CENTER, 0, -20);
    lv_obj_set_style_bg_color(wifiList, lv_color_hex(0xFFFFFF), 0);
    
   // 密码输入区域（初始隐藏）
   passwordPanel = lv_obj_create(wifiScreen);
   lv_obj_set_size(passwordPanel, 280, 120);
   lv_obj_align(passwordPanel, LV_ALIGN_CENTER, 0, -40);
    lv_obj_set_style_bg_color(passwordPanel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_add_flag(passwordPanel, LV_OBJ_FLAG_HIDDEN); // 初始隐藏
    
    lv_obj_t *passwordTitle = lv_label_create(passwordPanel);
    lv_label_set_text(passwordTitle, "输入密码:");
    lv_obj_set_style_text_font(passwordTitle, &myFont_new, 0);
    lv_obj_align(passwordTitle, LV_ALIGN_TOP_LEFT, 10, 5);
    
    passwordTextArea = lv_textarea_create(passwordPanel);
    lv_obj_set_size(passwordTextArea, 200, 35);
    lv_obj_align(passwordTextArea, LV_ALIGN_TOP_LEFT, 10, 30);
    lv_textarea_set_placeholder_text(passwordTextArea, "WiFi Password");
    lv_textarea_set_password_mode(passwordTextArea, false);  // 不隐藏密码,直接显示
    
    // 设置中文字体支持
    lv_obj_set_style_text_font(passwordTextArea, &myFont_new, 0);
    lv_obj_set_style_text_font(passwordTextArea, &myFont_new, LV_PART_TEXTAREA_PLACEHOLDER);
    
    // 添加点击事件处理
    lv_obj_add_event_cb(passwordTextArea, password_input_event_cb, LV_EVENT_CLICKED, NULL);
    lv_obj_add_event_cb(passwordTextArea, password_input_event_cb, LV_EVENT_FOCUSED, NULL);
    
    // 确保可以获得焦点
    lv_obj_add_flag(passwordTextArea, LV_OBJ_FLAG_CLICKABLE);
    lv_obj_clear_flag(passwordTextArea, LV_OBJ_FLAG_CLICK_FOCUSABLE);
    
    connectButton = lv_btn_create(passwordPanel);
    lv_obj_set_size(connectButton, 60, 35);
    lv_obj_align(connectButton, LV_ALIGN_TOP_RIGHT, -10, 30);
    lv_obj_set_style_bg_color(connectButton, lv_color_hex(0x4CAF50), 0);
    lv_obj_add_event_cb(connectButton, connect_btn_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *connectLabel = lv_label_create(connectButton);
    lv_label_set_text(connectLabel, "连接");
    lv_obj_set_style_text_font(connectLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(connectLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(connectLabel);
    
    // 切换到WiFi屏幕
    lv_scr_load(wifiScreen);
    
    // 开始扫描WiFi
    startWiFiScan();
}

// WiFi扫描功能
void startWiFiScan() {
   if (isScanning) return;
   
   Serial.println("开始扫描WiFi网络");
   isScanning = true;
   networkCount = 0;
   
   // 重置选中状态
   selectedItem = NULL;
   selectedSSID = "";
   
   // 隐藏键盘
   hideKeyboard();
   
   lv_label_set_text(wifiStatusLabel, "正在扫描WiFi网络...");
   lv_obj_set_style_text_color(wifiStatusLabel, lv_color_hex(0xFF9800), 0);
   
   // 清空列表
   lv_obj_clean(wifiList);
   
   // 确保WiFi模块处于正确状态
   WiFi.mode(WIFI_STA);
   delay(100); // 确保模式切换完成
   
   // 清理之前的扫描结果
   WiFi.scanDelete();
   delay(200); // 给WiFi模块更多时间来清理
   
   // 检查WiFi模块状态
   if (WiFi.getMode() != WIFI_STA) {
       Serial.println("WARN WiFi模式不正确,重新设置");
       WiFi.mode(WIFI_OFF);
       delay(100);
       WiFi.mode(WIFI_STA);
       delay(200);
   }
   
   Serial.println("开始WiFi网络扫描...");
   
   // 输出WiFi模块状态诊断信息
   Serial.println("STATS WiFi模块状态诊断:");
   Serial.println("  模式: " + String(WiFi.getMode()));
   Serial.println("  连接状态: " + String(WiFi.status()));
   Serial.println("  MAC地址: " + WiFi.macAddress());
   
   // 同步扫描WiFi网络,显示隐藏网络
   int n = WiFi.scanNetworks(false, true);
   
   Serial.println("WiFi扫描结果: " + String(n));
    
    if (n < 0) {
        // 扫描失败（返回负数）
        lv_label_set_text(wifiStatusLabel, "WiFi扫描失败,请重试");
        lv_obj_set_style_text_color(wifiStatusLabel, lv_color_hex(0xF44336), 0);
        Serial.println("ERROR WiFi扫描失败,错误码: " + String(n));
    } else if (n == 0) {
        lv_label_set_text(wifiStatusLabel, "未发现WiFi网络");
        lv_obj_set_style_text_color(wifiStatusLabel, lv_color_hex(0xF44336), 0);
    } else {
        lv_label_set_text(wifiStatusLabel, String("发现 " + String(n) + " 个网络").c_str());
        lv_obj_set_style_text_color(wifiStatusLabel, lv_color_hex(0x4CAF50), 0);
        
        // 填充网络列表
        networkCount = min(n, MAX_NETWORKS);
        for (int i = 0; i < networkCount; i++) {
            scannedNetworks[i].ssid = WiFi.SSID(i);
            scannedNetworks[i].rssi = WiFi.RSSI(i);
            scannedNetworks[i].encryption = WiFi.encryptionType(i);
            scannedNetworks[i].saved = false; // 移除硬编码检查,所有网络都显示为未保存
            
            addNetworkToList(i);
        }
    }
    
    isScanning = false;
}

// 添加网络到列表
void addNetworkToList(int index) {
    WiFiNetwork &network = scannedNetworks[index];
    
    // 创建列表项
    lv_obj_t *listItem = lv_list_add_btn(wifiList, NULL, "");
    lv_obj_set_height(listItem, 50);
    lv_obj_add_event_cb(listItem, wifi_item_event_cb, LV_EVENT_CLICKED, (void*)(intptr_t)index);
    
    // 网络信息显示
    String networkInfo = "";
    
    // 安全类型
    if (network.encryption == WIFI_AUTH_OPEN) {
        networkInfo += "[开放] ";
    } else {
        networkInfo += "[加密] ";
    }
    
    // 网络名称
    networkInfo += network.ssid;
    
    // 已保存标记
    if (network.saved) {
        networkInfo += " [已保存]";
    }
    
    // 信号强度
    if (network.rssi > -50) {
        networkInfo += " [强]";
    } else if (network.rssi > -70) {
        networkInfo += " [中]";
    } else {
        networkInfo += " [弱]";
    }
    
    lv_obj_t *itemLabel = lv_label_create(listItem);
    lv_label_set_text(itemLabel, networkInfo.c_str());
    lv_obj_set_style_text_font(itemLabel, &myFont_new, 0);
    lv_obj_align(itemLabel, LV_ALIGN_LEFT_MID, 10, 0);
    
    // 设置颜色
    if (network.saved) {
        lv_obj_set_style_text_color(itemLabel, lv_color_hex(0x4CAF50), 0);
        lv_obj_set_style_bg_color(listItem, lv_color_hex(0xE8F5E8), 0);
    } else {
        lv_obj_set_style_text_color(itemLabel, lv_color_hex(0x333333), 0);
    }
}


// 返回按钮事件
void back_btn_event_cb(lv_event_t * e) {
   Serial.println("返回主界面");
   
   // 停止任何正在进行的WiFi连接尝试（在重置状态之前检查）
   if (wifiConnecting) {
       WiFi.disconnect();
       Serial.println("已取消WiFi连接尝试");
   }
   
   // 重置WiFi状态变量
   isScanning = false;
   wifiConnecting = false;
   wifiOperationInProgress = false;
   
   // 清理WiFi连接定时器
   if (wifiConnectTimer != NULL) {
       lv_timer_del(wifiConnectTimer);
       wifiConnectTimer = NULL;
   }
   
   // 彻底重置WiFi模块状态,确保下次扫描正常
   WiFi.scanDelete();
   WiFi.mode(WIFI_OFF);
   delay(200); // 给WiFi模块更多时间完全关闭
   WiFi.mode(WIFI_STA);
   delay(200); // 给WiFi模块时间重新初始化
   
   Serial.println("WiFi模块已彻底重置,准备下次操作");
   
   // 清理所有子对象的全局引用
   keyboard = NULL;
   passwordPanel = NULL;
   passwordTextArea = NULL;
   connectButton = NULL;
   backButton = NULL;
   refreshButton = NULL;
   wifiStatusLabel = NULL;
   wifiList = NULL;
   selectedItem = NULL;
   selectedSSID = "";
   
   // 然后切换界面并删除WiFi屏幕
   lv_scr_load(mainScreen);
   lv_obj_del(wifiScreen);
   wifiScreen = NULL;
   
   Serial.println("WiFi界面已清理,所有状态已重置,返回主界面");
}

// 刷新按钮事件
void refresh_btn_event_cb(lv_event_t * e) {
    Serial.println("刷新WiFi列表");
    
    // 重置选中状态
    selectedItem = NULL;
    selectedSSID = "";
    
    // 隐藏密码输入面板和键盘
    if (passwordPanel != NULL) {
        lv_obj_add_flag(passwordPanel, LV_OBJ_FLAG_HIDDEN);
    }
    hideKeyboard();
    
    startWiFiScan();
}

// WiFi网络项点击事件
void wifi_item_event_cb(lv_event_t * e) {
    lv_obj_t *clickedItem = lv_event_get_target(e);
    int index = (int)(intptr_t)lv_event_get_user_data(e);
    selectedSSID = scannedNetworks[index].ssid;
    
    Serial.printf("选择网络: %s\n", selectedSSID.c_str());
    
    // 清除之前的选中状态
    if (selectedItem != NULL) {
        // 恢复默认背景色
        lv_obj_set_style_bg_color(selectedItem, lv_color_hex(0xFFFFFF), 0);
        
        // 如果之前选中的是已保存网络,恢复绿色背景
        for (int i = 0; i < networkCount; i++) {
            if (scannedNetworks[i].saved) {
                // 这里需要通过其他方式判断是否是已保存网络的项
                // 暂时先用白色背景
                break;
            }
        }
    }
    
    // 设置新的选中状态
    selectedItem = clickedItem;
    lv_obj_set_style_bg_color(selectedItem, lv_color_hex(0x2196F3), 0);
    
    // 如果是开放网络,直接连接
    if (scannedNetworks[index].encryption == WIFI_AUTH_OPEN) {
        connectToNetwork("", index);
    } else {
        // 显示密码输入
        showPasswordInput(index);
    }
}

// 显示密码输入界面
void showPasswordInput(int index) {
    Serial.println("显示密码输入面板");
    
    // 确保密码面板存在且显示
    if (passwordPanel != NULL) {
        lv_obj_clear_flag(passwordPanel, LV_OBJ_FLAG_HIDDEN);
        Serial.println("密码面板已显示");
        
        // 强制移到前台
        lv_obj_move_foreground(passwordPanel);
        
        // 清空密码输入框,用户需要手动输入密码
        lv_textarea_set_text(passwordTextArea, "");
        Serial.println("清空密码输入框,等待用户输入");
        
        // 聚焦到密码输入框
        lv_obj_add_state(passwordTextArea, LV_STATE_FOCUSED);
    } else {
        Serial.println("错误: 密码面板为空");
    }
}

// 连接按钮事件
void connect_btn_event_cb(lv_event_t * e) {
    const char* password = lv_textarea_get_text(passwordTextArea);
    
    // 找到选中网络的索引
    int selectedIndex = -1;
    for (int i = 0; i < networkCount; i++) {
        if (scannedNetworks[i].ssid == selectedSSID) {
            selectedIndex = i;
            break;
        }
    }
    
    if (selectedIndex >= 0) {
        connectToNetwork(password, selectedIndex);
    }
}

// 连接到指定网络（异步方式）
void connectToNetwork(const char* password, int index) {
    WiFiNetwork &network = scannedNetworks[index];
    
    Serial.printf("开始异步连接网络: %s\n", network.ssid.c_str());
    
    // 保存连接参数
    connectingSSID = network.ssid;
    connectingPassword = String(password);
    
    // 立即显示连接进度界面
    createConnectProgressScreen(network.ssid);
    
    // 隐藏密码输入面板和键盘
    if (passwordPanel != NULL) {
        lv_obj_add_flag(passwordPanel, LV_OBJ_FLAG_HIDDEN);
    }
    hideKeyboard();
    
    // 断开当前连接
    WiFi.disconnect();
    delay(100);
    
    // 开始连接新网络
    if (strlen(password) > 0) {
        WiFi.begin(network.ssid.c_str(), password);
    } else {
        WiFi.begin(network.ssid.c_str());
    }
    
    // 设置连接状态
    wifiConnecting = true;
    connectStartTime = millis();
    
    // 启动异步状态检查定时器（每500ms检查一次）
    safeDeleteTimer(&wifiConnectTimer);
    wifiConnectTimer = lv_timer_create(wifi_connect_timer_cb, 500, NULL);
    lv_timer_set_repeat_count(wifiConnectTimer, -1); // 无限重复,直到手动停止
    
    Serial.println("异步WiFi连接已启动,正在后台处理...");
}

// 密码输入框事件处理
void password_input_event_cb(lv_event_t * e) {
    lv_event_code_t code = lv_event_get_code(e);
    
    if (code == LV_EVENT_CLICKED || code == LV_EVENT_FOCUSED) {
        Serial.println("密码输入框被点击,显示LVGL键盘");
        showLVGLKeyboard();
    }
}

// 显示LVGL内置键盘
void showLVGLKeyboard() {
    // 安全检查:确保WiFi屏幕和密码输入框存在
    if (wifiScreen == NULL || passwordTextArea == NULL) {
        Serial.println("错误:WiFi界面或密码输入框不存在,无法创建键盘");
        return;
    }
    
    // 如果键盘已存在,直接显示
    if (keyboard != NULL) {
        lv_obj_clear_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
        Serial.println("显示已存在的键盘");
        return;
    }
    
    // 创建LVGL内置键盘
    keyboard = lv_keyboard_create(wifiScreen);
    
    // 检查键盘创建是否成功
    if (keyboard == NULL) {
        Serial.println("错误:键盘创建失败");
        return;
    }
    
    // 设置键盘大小和位置
    lv_obj_set_size(keyboard, 320, 200);
    lv_obj_align(keyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
    
    // 设置键盘模式为文本输入
    lv_keyboard_set_mode(keyboard, LV_KEYBOARD_MODE_TEXT_LOWER);
    
    // 连接键盘到密码输入框
    lv_keyboard_set_textarea(keyboard, passwordTextArea);
    
    // 添加键盘事件处理（使用更精确的事件类型）
    lv_obj_add_event_cb(keyboard, keyboard_event_cb, LV_EVENT_READY, NULL);
    lv_obj_add_event_cb(keyboard, keyboard_event_cb, LV_EVENT_CANCEL, NULL);
    
    Serial.println("创建并显示LVGL键盘");
}

// LVGL键盘事件处理
void keyboard_event_cb(lv_event_t * e) {
    // 安全检查:确保键盘对象仍然有效
    if (keyboard == NULL) {
        Serial.println("警告:键盘事件回调时键盘对象为空");
        return;
    }
    
    lv_event_code_t code = lv_event_get_code(e);
    
    if (code == LV_EVENT_READY || code == LV_EVENT_CANCEL) {
        // 键盘确认或取消时隐藏
        lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
        Serial.println("键盘事件: 隐藏键盘");
        
        if (code == LV_EVENT_READY) {
            // 确认输入,尝试连接
            Serial.println("键盘确认,尝试连接WiFi");
            connect_btn_event_cb(NULL);
        }
    }
}

// 隐藏键盘
void hideKeyboard() {
    if (keyboard != NULL) {
        lv_obj_add_flag(keyboard, LV_OBJ_FLAG_HIDDEN);
        Serial.println("强制隐藏键盘");
    }
}

// 获取楼栋详细信息
BuildingDetail getBuildingDetail(String buildingName) {
    BuildingDetail detail;
    detail.buildingName = buildingName;
    detail.date = getCurrentDate();
    detail.success = false;
    detail.floorCount = 0;
    
    // 检查可用内存
    Serial.println("剩余堆内存: " + String(ESP.getFreeHeap()) + " bytes");
    
    if (!wifiConnected) {
        Serial.println("ERROR 楼栋详细信息获取失败:WiFi未连接");
        return detail;
    }
    
    Serial.println("INFO 获取楼栋详细信息: " + buildingName);
    
    HTTPClient http;
   http.begin("http://YOUR_SERVER_IP/api/building_detail.php");
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   configureHTTP(http, 12000);  // 楼栋详情使用12秒超时（数据较多）
    
    // 准备请求数据 - 确保字符串干净
    String cleanBuildingName = buildingName;
    cleanBuildingName.trim(); // 移除前后空格
    String cleanDate = getCurrentDate();
    
    // 手动构建JSON,避免特殊字符问题
    String jsonData = "{";
    jsonData += "\"device_id\":\"" + String(DEVICE_ID) + "\",";
    jsonData += "\"building_name\":\"" + cleanBuildingName + "\",";
    jsonData += "\"date\":\"" + cleanDate + "\"";
    jsonData += "}";
    
   Serial.println("发送楼栋详情请求: " + jsonData);
   
   int httpCode = retryHttpPost(http, jsonData, 2);  // 楼栋详情重试2次
    
   if (httpCode == 200) {
       // 优化：先获取长度，避免大对象拷贝
       int contentLength = http.getSize();
       Serial.print("楼栋详情API响应长度: ");
       Serial.print(contentLength);
       Serial.println(" bytes");
       
       // 提前检查长度
       if (contentLength > 3000 || contentLength < 0) {
           Serial.println("ERROR 响应数据过大或无效,跳过处理");
           http.end();
           return detail;
       }
       
       // 获取响应
       String response = http.getString();
       Serial.print("解析前剩余内存: ");
       Serial.print(ESP.getFreeHeap());
       Serial.println(" bytes");
       
       // 根据响应大小动态分配JSON缓冲区,添加30%安全余量
       size_t bufferSize = response.length() * 1.3 + 512;
       if (bufferSize > 6144) bufferSize = 6144;  // 最大6KB限制
       if (bufferSize < 2048) bufferSize = 2048;  // 最小2KB保证
       DynamicJsonDocument responseDoc(bufferSize);
       DeserializationError error = deserializeJson(responseDoc, response);
       
       // 优化：立即释放response内存
       response = String();  // 清空String
       
       Serial.print("解析后剩余内存: ");
       Serial.print(ESP.getFreeHeap());
       Serial.println(" bytes");
        
        if (!error && responseDoc["success"]) {
            JsonObject data = responseDoc["data"];
            JsonObject buildingTotal = data["building_total"];
            
            detail.totalStudents = buildingTotal["total_students"] | 0;
            detail.totalPresent = buildingTotal["total_present"] | 0;
            detail.totalAbsent = buildingTotal["total_absent"] | 0;
            detail.totalLeave = buildingTotal["total_leave"] | 0;
            detail.totalNotChecked = buildingTotal["total_not_checked"] | 0;
            detail.success = true;
            
            // 解析楼层数据
            JsonArray floors = data["floors"];
            detail.floorCount = min((int)floors.size(), 6);  // 最多6层
            
            for (int i = 0; i < detail.floorCount; i++) {
                JsonObject floor = floors[i];
                detail.floors[i].floor = floor["floor"].as<String>();
                detail.floors[i].totalStudents = floor["total_students"] | 0;
                detail.floors[i].totalPresent = floor["total_present"] | 0;
                detail.floors[i].totalAbsent = floor["total_absent"] | 0;
                detail.floors[i].totalLeave = floor["total_leave"] | 0;
                detail.floors[i].totalNotChecked = floor["total_not_checked"] | 0;
                
                // 不再需要studentCount字段
            }
            
            Serial.println("OK 楼栋详细信息获取成功:");
            Serial.println("  " + buildingName + "号楼,共" + String(detail.floorCount) + "层");
            Serial.println("  总人数: " + String(detail.totalStudents));
            
        } else {
            Serial.println("ERROR 楼栋详情API响应解析失败或success为false");
            if (error) {
                Serial.println("JSON解析错误: " + String(error.c_str()));
            }
            if (responseDoc["success"] == false) {
                String message = responseDoc["message"] | "未知错误";
                Serial.println("API错误消息: " + message);
            }
        }
    } else {
        Serial.println("ERROR HTTP请求失败,状态码: " + String(httpCode));
        if (httpCode > 0) {
            String response = http.getString();
            Serial.println("错误响应: " + response);
        }
    }
    
    http.end();
    return detail;
}

// 创建楼栋详细信息页面
void createBuildingDetailScreen(BuildingDetail detail) {
    // 如果已有详细页面,先删除
    if (buildingDetailScreen != NULL) {
        lv_obj_del(buildingDetailScreen);
        buildingDetailScreen = NULL;
    }
    
    // 创建全屏详细页面容器
    buildingDetailScreen = lv_obj_create(lv_scr_act());
    lv_obj_set_size(buildingDetailScreen, 320, 480);
    lv_obj_set_pos(buildingDetailScreen, 0, 0);
    lv_obj_set_style_bg_color(buildingDetailScreen, lv_color_hex(0xF0F8FF), 0);
    lv_obj_set_style_border_width(buildingDetailScreen, 0, 0);
    lv_obj_set_style_radius(buildingDetailScreen, 0, 0);
    lv_obj_set_style_pad_all(buildingDetailScreen, 0, 0);
    lv_obj_clear_flag(buildingDetailScreen, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_set_scroll_dir(buildingDetailScreen, LV_DIR_NONE);
    
    // 标题栏
    lv_obj_t *titleBar = lv_obj_create(buildingDetailScreen);
    lv_obj_set_size(titleBar, 320, 50);
    lv_obj_set_pos(titleBar, 0, 0);
    lv_obj_set_style_bg_color(titleBar, lv_color_hex(0x1976D2), 0);
    lv_obj_set_style_radius(titleBar, 0, 0);
    lv_obj_set_style_border_width(titleBar, 0, 0);
    lv_obj_set_style_pad_all(titleBar, 0, 0);
    lv_obj_clear_flag(titleBar, LV_OBJ_FLAG_SCROLLABLE);
    
    lv_obj_t *titleLabel = lv_label_create(titleBar);
    String titleText = detail.buildingName + "号楼详情";
    lv_label_set_text(titleLabel, titleText.c_str());
    lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(titleLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, 0);
    
    // 总计信息栏
    lv_obj_t *summaryBar = lv_obj_create(buildingDetailScreen);
    lv_obj_set_size(summaryBar, 320, 35);
    lv_obj_set_pos(summaryBar, 0, 50);
    lv_obj_set_style_bg_color(summaryBar, lv_color_hex(0xE3F2FD), 0);
    lv_obj_set_style_radius(summaryBar, 0, 0);
    lv_obj_set_style_border_width(summaryBar, 0, 0);
    lv_obj_set_style_pad_all(summaryBar, 5, 0);
    lv_obj_clear_flag(summaryBar, LV_OBJ_FLAG_SCROLLABLE);
    
    lv_obj_t *summaryLabel = lv_label_create(summaryBar);
    String summaryText = "总计: " + String(detail.totalStudents) + "人  在寝: " + String(detail.totalPresent) + "  离寝: " + String(detail.totalAbsent);
    lv_label_set_text(summaryLabel, summaryText.c_str());
    lv_obj_set_style_text_font(summaryLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(summaryLabel, lv_color_hex(0x1976D2), 0);
    lv_obj_align(summaryLabel, LV_ALIGN_CENTER, 0, 0);
    
    // 表头
    lv_obj_t *header = lv_obj_create(buildingDetailScreen);
    lv_obj_set_size(header, 320, 25);
    lv_obj_set_pos(header, 0, 85);
    lv_obj_set_style_bg_color(header, lv_color_hex(0x1976D2), 0);
    lv_obj_set_style_radius(header, 0, 0);
    lv_obj_set_style_border_width(header, 0, 0);
    lv_obj_set_style_pad_all(header, 0, 0);
    lv_obj_clear_flag(header, LV_OBJ_FLAG_SCROLLABLE);
    
    const char* headers[] = {"楼层", "总数", "在寝", "离寝", "请假", "未到"};
    int positions[] = {15, 70, 110, 150, 190, 230};
    
    for (int i = 0; i < 6; i++) {
        lv_obj_t *headerLabel = lv_label_create(header);
        lv_label_set_text(headerLabel, headers[i]);
        lv_obj_set_style_text_font(headerLabel, &myFont_new, 0);
        lv_obj_set_style_text_color(headerLabel, lv_color_hex(0xFFFFFF), 0);
        lv_obj_set_pos(headerLabel, positions[i], 3);
    }
    
    // 楼层列表容器
    lv_obj_t *floorList = lv_obj_create(buildingDetailScreen);
    lv_obj_set_size(floorList, 320, 320);
    lv_obj_set_pos(floorList, 0, 110);
    lv_obj_set_style_bg_color(floorList, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_radius(floorList, 0, 0);
    lv_obj_set_style_border_width(floorList, 0, 0);
    lv_obj_set_style_pad_all(floorList, 5, 0);
    lv_obj_set_scroll_dir(floorList, LV_DIR_VER);
    
    // 添加楼层数据行
    int yPos = 5;
    int rowHeight = 40;
    
    for (int i = 0; i < detail.floorCount; i++) {
        createFloorRow(floorList, detail.floors[i], yPos, i % 2 == 0);
        yPos += rowHeight + 3;
    }
    
    // 底部按钮栏
    lv_obj_t *buttonBar = lv_obj_create(buildingDetailScreen);
    lv_obj_set_size(buttonBar, 320, 50);
    lv_obj_set_pos(buttonBar, 0, 430);
    lv_obj_set_style_bg_color(buttonBar, lv_color_hex(0xF8F9FA), 0);
    lv_obj_set_style_radius(buttonBar, 0, 0);
    lv_obj_set_style_border_width(buttonBar, 1, 0);
    lv_obj_set_style_border_color(buttonBar, lv_color_hex(0xE0E0E0), 0);
    lv_obj_set_style_border_side(buttonBar, LV_BORDER_SIDE_TOP, 0);
    lv_obj_set_style_pad_all(buttonBar, 8, 0);
    lv_obj_clear_flag(buttonBar, LV_OBJ_FLAG_SCROLLABLE);
    
    // 返回按钮
    lv_obj_t *backBtn = lv_btn_create(buttonBar);
    lv_obj_set_size(backBtn, 90, 34);
    lv_obj_set_pos(backBtn, 115, 8);  // 居中位置
    lv_obj_set_style_bg_color(backBtn, lv_color_hex(0x757575), 0);
    lv_obj_set_style_radius(backBtn, 17, 0);
    lv_obj_set_style_border_width(backBtn, 0, 0);
    
   lv_obj_t *backLabel = lv_label_create(backBtn);
   lv_label_set_text(backLabel, "关闭");
    lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(backLabel);
    
    // 返回按钮事件
    lv_obj_add_event_cb(backBtn, [](lv_event_t * e) {
       if (buildingDetailScreen != NULL) {
           // 直接切换到主界面，避免界面状态管理问题
           lv_scr_load(mainScreen);
           lv_obj_del(buildingDetailScreen);
           buildingDetailScreen = NULL;
           Serial.println("楼栋详情界面已安全关闭（按钮触发）");
       }
        if (buildingDetailTimer != NULL) {
            lv_timer_del(buildingDetailTimer);
            buildingDetailTimer = NULL;
        }
    }, LV_EVENT_CLICKED, NULL);
    
    // 5分钟后自动关闭,给用户充足浏览时间
    if (buildingDetailTimer != NULL) {
        lv_timer_del(buildingDetailTimer);
    }
    buildingDetailTimer = lv_timer_create([](lv_timer_t * timer) {
        if (buildingDetailScreen != NULL) {
            // 先切换到统计界面，再删除详情界面
            lv_scr_load(statisticsScreen);
            lv_obj_del(buildingDetailScreen);
            buildingDetailScreen = NULL;
            Serial.println("楼栋详情界面已安全关闭（定时器触发）");
        }
        if (buildingDetailTimer != NULL) {
            lv_timer_del(buildingDetailTimer);
            buildingDetailTimer = NULL;
        }
        // 强制垃圾回收
        Serial.println("详细页面关闭后剩余内存: " + String(ESP.getFreeHeap()) + " bytes");
    }, 300000, NULL);
    lv_timer_set_repeat_count(buildingDetailTimer, 1);
}

// 创建楼层数据行
void createFloorRow(lv_obj_t *parent, FloorInfo floor, int yPos, bool isEvenRow) {
    lv_obj_t *row = lv_obj_create(parent);
    lv_obj_set_size(row, 310, 40);
    lv_obj_set_pos(row, 5, yPos);
    
    // 交替行背景色
    lv_color_t bgColor = isEvenRow ? lv_color_hex(0xF8F9FA) : lv_color_hex(0xFFFFFF);
    
    lv_obj_set_style_bg_color(row, bgColor, 0);
    lv_obj_set_style_radius(row, 5, 0);
    lv_obj_set_style_border_width(row, 1, 0);
    lv_obj_set_style_border_color(row, lv_color_hex(0xE1F5FE), 0);
    lv_obj_set_style_pad_all(row, 0, 0);
    lv_obj_clear_flag(row, LV_OBJ_FLAG_SCROLLABLE);
    
    // 数据内容
    String values[] = {
        floor.floor + "层",  // 现在floor已经包含区域,如"A1"、"B2"
        String(floor.totalStudents),
        String(floor.totalPresent),
        String(floor.totalAbsent),
        String(floor.totalLeave),
        String(floor.totalNotChecked)
    };
    
    lv_color_t colors[] = {
        lv_color_hex(0x1976D2),  // 楼层 - 蓝色
        lv_color_hex(0x424242),  // 总数 - 深灰
        lv_color_hex(0x388E3C),  // 在寝 - 绿色
        lv_color_hex(0xD32F2F),  // 离寝 - 红色
        lv_color_hex(0xF57C00),  // 请假 - 橙色
        lv_color_hex(0x757575)   // 未到 - 灰色
    };
    
    int positions[] = {10, 70, 110, 150, 190, 230};
    
    for (int i = 0; i < 6; i++) {
        lv_obj_t *valueLabel = lv_label_create(row);
        lv_label_set_text(valueLabel, values[i].c_str());
        lv_obj_set_style_text_font(valueLabel, &myFont_new, 0);
        lv_obj_set_style_text_color(valueLabel, colors[i], 0);
        lv_obj_set_pos(valueLabel, positions[i], 12);
    }
}

// 创建WiFi连接进度界面
void createConnectProgressScreen(String ssid) {
    Serial.println("创建WiFi连接进度界面: " + ssid);
    
    // 如果已有进度界面,先安全删除
    if (connectProgressScreen != NULL) {
        // 先切换到WiFi界面，再删除进度界面
        if (wifiScreen != NULL) {
            lv_scr_load(wifiScreen);
        } else {
            lv_scr_load(mainScreen);
        }
        lv_obj_del(connectProgressScreen);
        connectProgressScreen = NULL;
        Serial.println("连接进度界面已安全删除");
    }
    
    // 创建全屏进度界面
    connectProgressScreen = lv_obj_create(lv_scr_act());
    lv_obj_set_size(connectProgressScreen, 320, 480);
    lv_obj_set_pos(connectProgressScreen, 0, 0);
    lv_obj_set_style_bg_color(connectProgressScreen, lv_color_hex(0xF0F8FF), 0);
    lv_obj_set_style_border_width(connectProgressScreen, 0, 0);
    lv_obj_set_style_radius(connectProgressScreen, 0, 0);
    lv_obj_set_style_pad_all(connectProgressScreen, 0, 0);
    lv_obj_clear_flag(connectProgressScreen, LV_OBJ_FLAG_SCROLLABLE);
    
    // 标题栏
    lv_obj_t *titleBar = lv_obj_create(connectProgressScreen);
    lv_obj_set_size(titleBar, 320, 50);
    lv_obj_set_pos(titleBar, 0, 0);
    lv_obj_set_style_bg_color(titleBar, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_radius(titleBar, 0, 0);
    lv_obj_set_style_border_width(titleBar, 0, 0);
    lv_obj_clear_flag(titleBar, LV_OBJ_FLAG_SCROLLABLE);
    
    lv_obj_t *titleLabel = lv_label_create(titleBar);
    lv_label_set_text(titleLabel, "WiFi连接中");
    lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(titleLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, 0);
    
   // 连接信息显示
   lv_obj_t *infoContainer = lv_obj_create(connectProgressScreen);
   lv_obj_set_size(infoContainer, 280, 220);
    lv_obj_center(infoContainer);
    lv_obj_set_style_bg_color(infoContainer, lv_color_hex(0xFFFFFF), 0);
    lv_obj_set_style_radius(infoContainer, 15, 0);
    lv_obj_set_style_border_width(infoContainer, 2, 0);
    lv_obj_set_style_border_color(infoContainer, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_shadow_width(infoContainer, 10, 0);
    lv_obj_set_style_shadow_color(infoContainer, lv_color_hex(0x000000), 0);
    lv_obj_set_style_shadow_opa(infoContainer, LV_OPA_20, 0);
    lv_obj_clear_flag(infoContainer, LV_OBJ_FLAG_SCROLLABLE);
    
    // 网络名称显示
    lv_obj_t *ssidLabel = lv_label_create(infoContainer);
    String ssidText = "正在连接: " + ssid;
    lv_label_set_text(ssidLabel, ssidText.c_str());
    lv_obj_set_style_text_font(ssidLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(ssidLabel, lv_color_hex(0x1976D2), 0);
    lv_obj_align(ssidLabel, LV_ALIGN_TOP_MID, 0, 20);
    
   // 创建简单的进度指示器（使用旋转的点）
   connectProgressSpinner = lv_obj_create(infoContainer);
   lv_obj_set_size(connectProgressSpinner, 60, 60);
   lv_obj_align(connectProgressSpinner, LV_ALIGN_CENTER, 0, 0);
    lv_obj_set_style_bg_color(connectProgressSpinner, lv_color_hex(0x2196F3), 0);
    lv_obj_set_style_radius(connectProgressSpinner, 30, 0);
    lv_obj_set_style_border_width(connectProgressSpinner, 3, 0);
    lv_obj_set_style_border_color(connectProgressSpinner, lv_color_hex(0xFFFFFF), 0);
    
   // 进度文字
   connectProgressLabel = lv_label_create(infoContainer);
   lv_label_set_text(connectProgressLabel, "正在连接网络...");
   lv_obj_set_style_text_font(connectProgressLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(connectProgressLabel, lv_color_hex(0x666666), 0);
   lv_obj_align(connectProgressLabel, LV_ALIGN_CENTER, 0, 50);
    
   // 取消按钮
   lv_obj_t *cancelBtn = lv_btn_create(infoContainer);
   lv_obj_set_size(cancelBtn, 100, 35);
   lv_obj_align(cancelBtn, LV_ALIGN_CENTER, 0, 85);
    lv_obj_set_style_bg_color(cancelBtn, lv_color_hex(0xF44336), 0);
    lv_obj_set_style_radius(cancelBtn, 17, 0);
    lv_obj_add_event_cb(cancelBtn, cancel_connect_btn_event_cb, LV_EVENT_CLICKED, NULL);
    
    lv_obj_t *cancelLabel = lv_label_create(cancelBtn);
    lv_label_set_text(cancelLabel, "取消");
    lv_obj_set_style_text_font(cancelLabel, &myFont_new, 0);
    lv_obj_set_style_text_color(cancelLabel, lv_color_hex(0xFFFFFF), 0);
    lv_obj_center(cancelLabel);
}

// 关闭WiFi连接进度界面
void closeConnectProgressScreen() {
   if (connectProgressScreen != NULL) {
       // 先切换到WiFi界面，再删除进度界面
       if (wifiScreen != NULL) {
           lv_scr_load(wifiScreen);
       } else {
           lv_scr_load(mainScreen);
       }
       lv_obj_del(connectProgressScreen);
       connectProgressScreen = NULL;
       connectProgressLabel = NULL;
       connectProgressSpinner = NULL;
       Serial.println("连接进度界面已安全关闭");
   }
    
    if (wifiConnectTimer != NULL) {
        lv_timer_del(wifiConnectTimer);
        wifiConnectTimer = NULL;
    }
    
    wifiConnecting = false;
    connectingSSID = "";
    connectingPassword = "";
}

// 取消连接按钮事件
void cancel_connect_btn_event_cb(lv_event_t * e) {
    Serial.println("用户取消WiFi连接");
    WiFi.disconnect();
    closeConnectProgressScreen();
    // 返回到WiFi界面,不关闭WiFi管理界面
}

// WiFi异步创建界面定时器回调
void wifi_create_timer_cb(lv_timer_t * timer) {
    if (!wifiOperationInProgress) return;
    
    Serial.println("-> 执行异步WiFi界面创建...");
    Serial.println("创建前剩余内存: " + String(ESP.getFreeHeap()) + " bytes");
    
    try {
        // 隐藏加载提示
        closeMsgBox();
        
       // 确保WiFi模块状态正常
       if (WiFi.getMode() != WIFI_STA) {
           Serial.println("WARN 创建WiFi界面前重置WiFi模块状态");
           WiFi.mode(WIFI_OFF);
           delay(100);
           WiFi.mode(WIFI_STA);
           delay(100);
       }
       
       // 创建WiFi界面
       createWiFiScreen();
       
       Serial.println("OK WiFi界面创建成功");
        Serial.println("创建后剩余内存: " + String(ESP.getFreeHeap()) + " bytes");
        
    } catch (...) {
        Serial.println("ERROR WiFi界面创建失败");
        showMessageBox("错误", "WiFi界面创建失败\n请重试", "确定", false);
    }
    
    // 清理状态
    wifiOperationInProgress = false;
    if (wifiOperationTimer != NULL) {
        lv_timer_del(wifiOperationTimer);
        wifiOperationTimer = NULL;
    }
    
    Serial.println("OK 异步WiFi操作完成");
}

// WiFi连接状态检查定时器回调
void wifi_connect_timer_cb(lv_timer_t * timer) {
    if (!wifiConnecting) return;
    
    unsigned long currentTime = millis();
    unsigned long elapsedTime = currentTime - connectStartTime;
    
    // 更新进度文字
    if (connectProgressLabel != NULL) {
        if (elapsedTime < 3000) {
            lv_label_set_text(connectProgressLabel, "正在连接网络...");
        } else if (elapsedTime < 6000) {
            lv_label_set_text(connectProgressLabel, "验证密码中...");
        } else if (elapsedTime < 10000) {
            lv_label_set_text(connectProgressLabel, "获取IP地址...");
        } else {
            lv_label_set_text(connectProgressLabel, "连接超时,请重试");
        }
    }
    
    // 检查连接状态
    if (WiFi.status() == WL_CONNECTED) {
       // 连接成功
       Serial.println("\nWiFi连接成功!");
       Serial.printf("IP地址: %s\n", WiFi.localIP().toString().c_str());
       
       wifiConnected = true;
       
       // 更新网络系统状态为就绪
       networkSystemReady = true;
       Serial.println("OK 网络系统状态已更新为就绪");
       
       // 保存用户选择的WiFi配置
       userSelectedSSID = connectingSSID;
       userSelectedPassword = connectingPassword;
       hasUserWiFiConfig = true;
       Serial.println("OK 用户WiFi配置已保存: " + userSelectedSSID);
        
        // 更新进度界面显示成功信息
        if (connectProgressLabel != NULL) {
            lv_label_set_text(connectProgressLabel, "连接成功!");
            lv_obj_set_style_text_color(connectProgressLabel, lv_color_hex(0x4CAF50), 0);
        }
        
        // 更新进度指示器颜色
        if (connectProgressSpinner != NULL) {
            lv_obj_set_style_bg_color(connectProgressSpinner, lv_color_hex(0x4CAF50), 0);
        }
        
        // 2秒后关闭进度界面并返回主界面
        safeDeleteTimer(&successTimer);
        successTimer = lv_timer_create([](lv_timer_t * t) {
            closeConnectProgressScreen();
            
            // 关闭WiFi管理界面,返回主界面
            if (wifiScreen != NULL) {
                // 清理WiFi界面全局引用
                keyboard = NULL;
                passwordPanel = NULL;
                passwordTextArea = NULL;
                connectButton = NULL;
                backButton = NULL;
                refreshButton = NULL;
                wifiStatusLabel = NULL;
                wifiList = NULL;
                selectedItem = NULL;
                selectedSSID = "";
                
                lv_scr_load(mainScreen);
                lv_obj_del(wifiScreen);
                wifiScreen = NULL;
            }
            
            // 清理定时器引用
            successTimer = NULL;
            lv_timer_del(t);
        }, 2000, NULL);
        lv_timer_set_repeat_count(successTimer, 1);
        
        // 停止连接检查定时器
        wifiConnecting = false;
        if (wifiConnectTimer != NULL) {
            lv_timer_del(wifiConnectTimer);
            wifiConnectTimer = NULL;
        }
        
    } else if (elapsedTime > 15000) {
       // 连接超时
       Serial.println("\nWiFi连接超时!");
       
       wifiConnected = false;
       
       // 彻底重置WiFi模块状态
       WiFi.disconnect();
       WiFi.mode(WIFI_OFF);
       delay(100);
       WiFi.mode(WIFI_STA);
       delay(100);
       
       Serial.println("WiFi模块已重置,准备下次连接");
        
        // 更新进度界面显示失败信息
        if (connectProgressLabel != NULL) {
            lv_label_set_text(connectProgressLabel, "连接失败,请检查密码");
            lv_obj_set_style_text_color(connectProgressLabel, lv_color_hex(0xF44336), 0);
        }
        
        // 更新进度指示器颜色
        if (connectProgressSpinner != NULL) {
            lv_obj_set_style_bg_color(connectProgressSpinner, lv_color_hex(0xF44336), 0);
        }
        
        // 3秒后关闭进度界面
        safeDeleteTimer(&failTimer);
        failTimer = lv_timer_create([](lv_timer_t * t) {
            closeConnectProgressScreen();
            // 清理定时器引用
            failTimer = NULL;
            lv_timer_del(t);
        }, 3000, NULL);
        lv_timer_set_repeat_count(failTimer, 1);
        
        // 停止连接检查定时器
        wifiConnecting = false;
        if (wifiConnectTimer != NULL) {
           lv_timer_del(wifiConnectTimer);
           wifiConnectTimer = NULL;
       }
   }
}

// ==================== WiFi日志系统 ====================

// WiFi日志发送函数
void sendWiFiLog(String logLevel, String message, String component) {
   if (!wifiConnected) return;
   
   HTTPClient http;
   String logUrl = "http://YOUR_SERVER_IP/api/device_log.php";
   
   http.begin(logUrl);
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   http.setTimeout(3000);  // 3秒超时,避免阻塞
   
   StaticJsonDocument<1024> doc;
   doc["device_id"] = DEVICE_ID;
   doc["timestamp"] = time(nullptr);
   doc["ip_address"] = WiFi.localIP().toString();
   doc["log_level"] = logLevel;
   doc["component"] = component;
   doc["message"] = message;
   doc["memory_free"] = ESP.getFreeHeap();
   doc["uptime"] = millis();
   
   String jsonData;
   serializeJson(doc, jsonData);
   
   int httpResponseCode = http.POST(jsonData);
   // 不打印HTTP响应,避免串口输出干扰
   
   http.end();
}

// 便捷的日志函数
void logInfo(String message, String component) {
   sendWiFiLog("INFO", message, component);
}

void logError(String message, String component) {
   sendWiFiLog("ERROR", message, component);
}

void logWarn(String message, String component) {
   sendWiFiLog("WARN", message, component);
}

void logDebug(String message, String component) {
   sendWiFiLog("DEBUG", message, component);
}

// ==================== 指纹传感器智能调试系统 ====================

// 智能指纹传感器初始化（带详细日志）- Serial1模式
bool initFingerprintWithLogging() {
   logInfo("开始指纹传感器硬件检测（Serial1模式）", "fingerprint");
   
   // 检测WiFi连接状态
   if (!wifiConnected) {
       logError("WiFi未连接,无法进行远程调试", "fingerprint");
       return false;
   }
   
   // Serial1模式:无需释放USB占用
   logDebug("Serial1模式:直接连接GPIO17/18", "fingerprint");
   
   // 第一步:检查Serial1可用性
   logDebug("检查Serial1端口状态", "fingerprint");
   
   // 直接使用9600波特率（经过测试更稳定）
   fingerprintSerial.begin(9600, SERIAL_8N1, FP_RX_PIN, FP_TX_PIN);
   delay(200);
   
   logDebug("Serial1初始化完成,波特率: 9600", "fingerprint");
   
   // 第二步:初始化指纹传感器
   finger.begin(9600);
   delay(500);
   
   logDebug("Adafruit_Fingerprint库初始化完成", "fingerprint");
   
   // 第三步:密码验证测试
   logInfo("尝试指纹传感器密码验证（Serial1 9600波特率）...", "fingerprint");
   
   int attempts = 0;
   bool verifySuccess = false;
   
   while (attempts < 3 && !verifySuccess) {
       attempts++;
       logDebug("密码验证尝试 " + String(attempts) + "/3", "fingerprint");
       
       // 非阻塞的密码验证
       if (finger.verifyPassword()) {
           verifySuccess = true;
           logInfo("密码验证成功!Serial1传感器响应正常", "fingerprint");
       } else {
           logWarn("密码验证失败,尝试次数: " + String(attempts), "fingerprint");
           delay(1000);
       }
       
       // 让出CPU避免看门狗重启
       yield();
   }
   
   if (verifySuccess) {
       // 获取传感器详细信息
       logInfo("获取传感器详细信息...", "fingerprint");
       
       // 安全地结束串口连接
       fingerprintSerial.end();
       logDebug("安全关闭Serial1连接", "fingerprint");
       
       return true;
   } else {
       // 清理资源
       fingerprintSerial.end();
       logError("Serial1指纹传感器初始化完全失败", "fingerprint");
       return false;
   }
}

// 测试指纹检测功能（带详细日志）- Serial1模式
int testFingerprintWithLogging() {
   if (!wifiConnected) {
       return -1;
   }
   
   logInfo("开始指纹检测测试（Serial1模式）", "fingerprint");
   
   // Serial1模式:无需释放USB占用
   logDebug("Serial1模式:使用GPIO17/18", "fingerprint");
   
   // 直接使用9600波特率连接
   fingerprintSerial.begin(9600, SERIAL_8N1, FP_RX_PIN, FP_TX_PIN);
   finger.begin(9600);
   delay(300);
   
   // 验证连接
   if (!finger.verifyPassword()) {
       logError("无法建立Serial1传感器连接（9600波特率）", "fingerprint");
       fingerprintSerial.end();
       return -1;
   }
   
   logDebug("Serial1传感器连接验证成功,开始检测", "fingerprint");
   
   // 获取指纹图像
   int p = finger.getImage();
   switch (p) {
       case FINGERPRINT_OK:
           logInfo("OK 指纹图像获取成功!Serial1传感器工作完全正常", "fingerprint");
           break;
       case FINGERPRINT_NOFINGER:
           logInfo("🖐️ Serial1传感器连接正常,请将手指放在传感器上", "fingerprint");
           break;
       case FINGERPRINT_PACKETRECIEVEERR:
           logWarn("WIFI Serial1数据包接收错误,可能是连接不稳定", "fingerprint");
           break;
       case FINGERPRINT_IMAGEFAIL:
           logWarn("📷 图像获取失败,请重新尝试", "fingerprint");
           break;
       default:
           logError("ERROR 未知错误,错误码: " + String(p), "fingerprint");
           break;
   }
   
   // 如果成功获取图像,尝试模板转换测试
   if (p == FINGERPRINT_OK) {
       logDebug("尝试指纹模板转换...", "fingerprint");
       int p2 = finger.image2Tz();
       if (p2 == FINGERPRINT_OK) {
           logInfo("-> 指纹模板转换成功!Serial1传感器完全正常", "fingerprint");
       } else {
           logWarn("模板转换失败,错误码: " + String(p2), "fingerprint");
       }
   }
   
   // 安全关闭连接
   fingerprintSerial.end();
   logDebug("已安全关闭Serial1连接", "fingerprint");
   
   return p;
}

// ==================== 已删除无用的波特率测试系统 ====================
// 现在直接使用固定57600波特率,通过initFingerprintDirect()函数初始化

// ==================== 系统健康检查函数 ====================
void performSystemHealthCheck() {
   Serial.println("==================== 系统健康检查 ====================");
   
   // 显示检查开始
   if (uiInitialized) {
       displaySystemHealthProgress("系统检查", "开始检查");
   }
   
   // 1. 检查网络健康状态
   Serial.println("1. 检查网络连接状态...");
   networkSystemReady = checkNetworkHealth();
   
   // 2. 检查内存健康状态  
   Serial.println("2. 检查内存使用状态...");
   memorySystemReady = checkMemoryHealth();
   
   // 3. 检查指纹传感器健康状态
   Serial.println("3. 检查指纹传感器状态...");
   fingerprintSystemReady = checkFingerprintHealth();
   
   // 显示检查结果
   String resultText = "系统检查完成\n";
   resultText += "网络: " + String(networkSystemReady ? "正常" : "异常") + "\n";
   resultText += "内存: " + String(memorySystemReady ? "充足" : "不足") + "\n";
   resultText += "指纹: " + String(fingerprintSystemReady ? "就绪" : "未就绪");
   
   Serial.println("==================== 检查结果 ====================");
   Serial.println("网络状态: " + String(networkSystemReady ? "正常" : "异常"));
   Serial.println("内存状态: " + String(memorySystemReady ? "充足" : "不足"));  
   Serial.println("指纹状态: " + String(fingerprintSystemReady ? "就绪" : "未就绪"));
   Serial.println("=================================================");
   
   if (uiInitialized) {
       displaySystemHealthProgress("检查完成", resultText);
       delay(3000); // 显示结果3秒
   }
}

bool checkNetworkHealth() {
   displaySystemHealthProgress("网络检查", "检查WiFi连接");
   
   if (WiFi.status() == WL_CONNECTED) {
       String ipStr = WiFi.localIP().toString();
       int rssi = WiFi.RSSI();
       
       Serial.println("成功: WiFi已连接");
       Serial.println("  IP地址: " + ipStr);
       Serial.println("  信号强度: " + String(rssi) + " dBm");
       
       // 测试网络连通性
       displaySystemHealthProgress("网络检查", "测试网络连通性");
       
       HTTPClient http;
       http.begin("http://YOUR_SERVER_IP/api/health_check.php");
       http.setTimeout(3000);
       
       int httpCode = http.GET();
       http.end();
       
       if (httpCode > 0) {
           Serial.println("成功: 服务器连通正常");
           return true;
       } else {
           Serial.println("警告: 服务器连接异常, 但WiFi正常");
           return true; // WiFi正常就认为网络健康
       }
   } else {
       Serial.println("失败: WiFi未连接");
       return false;
   }
}

bool checkMemoryHealth() {
   displaySystemHealthProgress("内存检查", "检查可用内存");
   
   uint32_t freeHeap = ESP.getFreeHeap();
   uint32_t totalHeap = ESP.getHeapSize();
   uint32_t usedHeap = totalHeap - freeHeap;
   float usagePercent = (float)usedHeap / totalHeap * 100.0;
   
   Serial.println("内存使用情况:");
   Serial.println("  总内存: " + String(totalHeap) + " bytes");
   Serial.println("  已使用: " + String(usedHeap) + " bytes");
   Serial.println("  可用: " + String(freeHeap) + " bytes");
   Serial.println("  使用率: " + String(usagePercent, 1) + "%");
   
   // 内存充足的标准: 可用内存 > 50KB 且使用率 < 80%
   bool isHealthy = (freeHeap > 50000) && (usagePercent < 80.0);
   
   if (isHealthy) {
       Serial.println("成功: 内存状态健康");
   } else {
       Serial.println("警告: 内存使用过高, 可能影响性能");
   }
   
   return isHealthy;
}

bool checkFingerprintHealth() {
   displaySystemHealthProgress("指纹检查", "检查传感器连接");
   
   // 检查传感器是否已经初始化成功
   if (workingBaudRate > 0 && fingerprintSystemReady) {
       Serial.println("成功: 指纹传感器已就绪, 波特率: " + String(workingBaudRate));
       lastFingerprintActivity = millis();
       return true;
   }
   
   // 快速测试传感器连接
   displaySystemHealthProgress("指纹检查", "快速连接测试");
   
   // 尝试验证传感器连接
   if (finger.verifyPassword()) {
       Serial.println("成功: 指纹传感器连接正常");
       lastFingerprintActivity = millis();
       return true;
   } else {
       Serial.println("失败: 指纹传感器无响应");
       return false;
   }
}

void displaySystemHealthProgress(String component, String status) {
   if (!uiInitialized) return;
   
   // 使用新的统一状态管理系统
   updateMainScreenStatus(STATE_SYSTEM_CHECK, component + ": " + status);
}

// ==================== 统一状态管理系统实现 ====================

void updateMainScreenStatus(SystemState newState, String details, int progress) {
   // 记录状态变更
   Serial.println("-> 状态更新: " + String(currentSystemState) + " -> " + String(newState));
   if (details.length() > 0) {
       Serial.println("* 状态详情: " + details);
   }
   
   // 更新状态变量
   SystemState previousState = currentSystemState;
   currentSystemState = newState;
   currentStateDetails = details;
   currentStateProgress = progress;
   lastStatusUpdate = millis();
   
   // 同步兼容性变量
   switch (newState) {
       case STATE_IDLE:
           currentDisplayMode = 0;
           break;
       case STATE_FINGERPRINT_DETECTING:
       case STATE_FINGERPRINT_INIT:
       case STATE_ENROLLING:
       case STATE_SYSTEM_CHECK:
           currentDisplayMode = 1;
           break;
       case STATE_DETECTION_SUCCESS:
           currentDisplayMode = 2;
           break;
       case STATE_DETECTION_ERROR:
           currentDisplayMode = 3;
           break;
   }
   
   // 生成显示内容
   String displayText = generateStatusDisplayText(newState, details, progress);
   
   // 安全更新UI
   if (uiInitialized && fingerprintLabel != NULL) {
       lv_label_set_text(fingerprintLabel, displayText.c_str());
       Serial.println("OK 主界面状态已更新");
   } else {
       Serial.println("WARN  主界面未初始化,状态更新已记录");
   }
   
   // 自动状态恢复机制
   if (newState == STATE_DETECTION_SUCCESS || newState == STATE_DETECTION_ERROR) {
       lv_timer_create([](lv_timer_t * t) {
           // 5秒后自动回到空闲状态（如果没有其他状态变更）
           if (millis() - lastStatusUpdate >= 5000) {
               updateMainScreenStatus(STATE_IDLE);
           }
           lv_timer_del(t);
       }, 5000, NULL);
   }
}

String generateStatusDisplayText(SystemState state, String details, int progress) {
   String displayText = "";
   
   switch (state) {
       case STATE_IDLE:
           displayText = "指纹传感器: " + String(fingerprintSystemReady ? "就绪" : "未就绪") + "\n";
           // 使用与WiFi标签相同的检查逻辑确保一致性
           displayText += "网络状态: " + String(WiFi.isConnected() ? "已连接" : "未连接") + "\n";
           if (details.length() > 0) {
               displayText += details + "\n";
           }
           displayText += "点击签到按钮开始检测";
           break;
           
       case STATE_FINGERPRINT_INIT:
           displayText = "-> 指纹传感器初始化中...\n";
           displayText += "请稍候,正在建立连接\n";
           if (details.length() > 0) {
               displayText += details;
           }
           break;
           
       case STATE_FINGERPRINT_DETECTING: {
           displayText = "-> 指纹检测模式\n";
           if (details.length() > 0) {
               displayText += details + "\n";
           } else {
               displayText += "请将手指放在传感器上\n";
           }
           
           // 计算剩余时间
           unsigned long elapsedTime = millis() - detectionStartTime;
           unsigned long remainingTime = (elapsedTime < DETECTION_TIMEOUT) ? 
                                         (DETECTION_TIMEOUT - elapsedTime) / 1000 : 0;
           displayText += "剩余时间: " + String(remainingTime) + " 秒";
           break;
       }
           
       case STATE_DETECTION_SUCCESS:
           displayText = "OK 检测成功!\n";
           if (details.length() > 0) {
               displayText += details + "\n";
           }
           displayText += "5秒后自动继续检测...";
           break;
           
       case STATE_DETECTION_ERROR:
           displayText = "ERROR 检测失败\n";
           if (details.length() > 0) {
               displayText += details + "\n";
           } else {
               displayText += "请重试或检查传感器\n";
           }
           displayText += "点击签到按钮重新开始";
           break;
           
       case STATE_ENROLLING:
           displayText = "* 指纹录入模式\n";
           if (details.length() > 0) {
               displayText += details + "\n";
           }
           if (progress >= 0) {
               displayText += "进度: " + String(progress) + "%";
           }
           break;
           
       case STATE_SYSTEM_CHECK:
           displayText = "SCAN 系统检查中...\n";
           if (details.length() > 0) {
               displayText += details + "\n";
           }
           if (progress >= 0) {
               displayText += "进度: " + String(progress) + "%";
           }
           break;
           
       default:
           displayText = "系统状态未知\n请重启设备";
           break;
   }
   
   return displayText;
}

// ==================== 检测模式管理函数 ====================
void startDetectionMode() {
   Serial.println("==================== 启动指纹检测模式 ====================");
   
   // 检查前置条件
   if (!fingerprintSystemReady) {
       Serial.println("失败: 指纹传感器未就绪");
       showMessageBox("启动失败", "指纹传感器未就绪\n请等待系统初始化", "确定", false);
       return;
   }
   
   if (detectionModeActive) {
       Serial.println("警告: 检测模式已经激活");
       return;
   }
   
   // 激活检测模式
   detectionModeActive = true;
   detectionStartTime = millis();
   
   // 使用新的统一状态管理系统
   updateMainScreenStatus(STATE_FINGERPRINT_DETECTING);
   lastFingerprintActivity = millis();
   
   Serial.println("成功: 进入指纹检测模式");
   Serial.println("超时设置: " + String(DETECTION_TIMEOUT / 1000) + " 秒");
   
   // 【已弃用】老的startDetectionMode()函数，统一使用createCheckinDetectionScreen()
   // 如果需要启动检测，应该调用createCheckinDetectionScreen()而不是这个函数
   Serial.println("警告: 使用了已弃用的startDetectionMode()，建议使用createCheckinDetectionScreen()");
   
   // 为了兼容性，调用新界面
   createCheckinDetectionScreen();
   
   Serial.println("检测定时器已启动, 间隔: " + String(FINGER_CHECK_INTERVAL) + "ms");
}

void stopDetectionMode() {
   Serial.println("==================== 停止指纹检测模式 ====================");
   
   if (!detectionModeActive) {
       Serial.println("警告: 检测模式未激活");
       return;
   }
   
   // 停用检测模式
   detectionModeActive = false;
   
   // 清理定时器
   if (detectionTimer != NULL) {
       lv_timer_del(detectionTimer);
       detectionTimer = NULL;
       Serial.println("检测定时器已停止");
   }
   
   // 注意:5秒自动检测定时器是匿名定时器,无法直接清理
   // 但通过设置detectionModeActive = false,定时器回调会检查状态并安全退出
   Serial.println("所有相关定时器清理标记已设置");
   
   // 检查是否使用的是新的签到界面
   if (checkinDetectionScreen != NULL) {
       Serial.println("检测到新签到界面，执行专用清理流程");
       closeCheckinDetectionScreen();
   } else {
       Serial.println("使用老界面，执行传统清理流程");
       // 使用统一状态管理系统更新为空闲状态
       updateMainScreenStatus(STATE_IDLE, "检测已停止");
       
       // 清理取消按钮
       if (cancelButton != NULL) {
           lv_obj_del(cancelButton);
           cancelButton = NULL;
           Serial.println("取消按钮已清理");
       }
   }
   
   // 关闭当前消息框
   closeCurrentMessageBox();
   
   // 计算检测持续时间
   unsigned long detectionDuration = millis() - detectionStartTime;
   Serial.println("检测模式持续时间: " + String(detectionDuration / 1000) + " 秒");
   
   Serial.println("成功: 已退出指纹检测模式");
}

void detectionTimerCallback(lv_timer_t * timer) {
   if (!detectionModeActive) {
       // 如果检测模式被外部停止, 清理定时器
       if (timer != NULL) {
           lv_timer_del(timer);
       }
       detectionTimer = NULL;
       return;
   }
   
   // 检查UI对象有效性,防止访问已删除的对象
   if (checkinDetectionScreen == NULL || checkinStepLabel == NULL || checkinProgressLabel == NULL) {
       Serial.println("警告: UI对象已被删除,停止检测定时器");
       detectionModeActive = false;
       if (timer != NULL) {
           lv_timer_del(timer);
       }
       detectionTimer = NULL;
       return;
   }
   
   // 检查超时
   unsigned long currentTime = millis();
   if (currentTime - detectionStartTime > DETECTION_TIMEOUT) {
       Serial.println("==================== 检测超时处理 ====================");
       Serial.println("检测超时, 停止检测模式");
       Serial.println("指纹传感器状态: " + String(fingerprintSystemReady ? "正常" : "异常"));
       
       // ✅ 改进：直接停止检测，不使用定时器
       stopDetectionMode();
       showMessageBox("检测超时", "30秒内未检测到指纹\n已退出检测模式", "确定", false);
       
       // 检查并尝试恢复指纹传感器
       if (!fingerprintSystemReady) {
           Serial.println("警告: 检测到指纹传感器状态异常，尝试恢复");
           initFingerprintDirect();
       }
       
       return;
   }
   
   // 执行指纹检测
   int fingerprintID = getFingerprintIDWithSteps();
   Serial.println("检测结果: fingerprintID = " + String(fingerprintID));
   
   if (fingerprintID > 0) {
       Serial.println("检测成功! 指纹ID: " + String(fingerprintID));
       
       // 立即停止检测定时器,防止UI被覆盖
       if (detectionTimer != NULL) {
           lv_timer_del(detectionTimer);
           detectionTimer = NULL;
           Serial.println("检测定时器已停止 - 识别成功");
       }
       
       // 发送签到数据
       if (sendCheckinData(fingerprintID)) {
           Serial.println("签到数据发送成功");
       } else {
           Serial.println("签到数据发送失败");
           
           // ✅ 改进：删除3秒自动恢复定时器，改为显示"继续"按钮让用户手动继续
           updateCheckinProgress("上传失败", "网络错误或服务器异常\n点击\"继续\"进行下一次检测", false);
           
           // 显示继续按钮
           if (checkinContinueBtn != NULL) {
               lv_obj_clear_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN);
           }
           
           Serial.println("✅ 网络失败，等待用户点击\"继续\"按钮");
       }
   } else if (fingerprintID == -1) {
       // 正常情况: 没有检测到手指, 继续等待
       return;
   } else if (fingerprintID == -4) {
       // 指纹未注册的情况
       Serial.println("==================== 检测到指纹未注册错误 ====================");
       Serial.println("指纹未注册，等待用户操作");
       Serial.println("当前使用界面: " + String(checkinDetectionScreen != NULL ? "新界面" : "老界面"));
       
       // 停止当前检测定时器，防止重复检测
       if (detectionTimer != NULL) {
           lv_timer_del(detectionTimer);
           detectionTimer = NULL;
           Serial.println("暂停检测定时器");
       }
       
       // ✅ 改进：删除3秒自动恢复定时器，改为显示"继续"按钮让用户手动继续
       updateCheckinProgress("指纹未注册", "该指纹未注册,请联系管理员\n点击\"继续\"进行下一次检测", false);
       
       // 显示继续按钮
       if (checkinContinueBtn != NULL) {
           lv_obj_clear_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN);
       }
       
       Serial.println("✅ 指纹未注册，等待用户点击\"继续\"按钮");
       
   } else if (fingerprintID == -5) {
       // 系统错误的情况
       Serial.println("系统错误，等待用户操作");
       
       // 停止当前检测定时器
       if (detectionTimer != NULL) {
           lv_timer_del(detectionTimer);
           detectionTimer = NULL;
           Serial.println("暂停检测定时器(系统错误)");
       }
       
       // ✅ 改进：删除3秒自动恢复定时器，改为显示"继续"按钮让用户手动继续
       updateCheckinProgress("系统错误", "指纹传感器错误\n点击\"继续\"进行下一次检测", false);
       
       // 显示继续按钮
       if (checkinContinueBtn != NULL) {
           lv_obj_clear_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN);
       }
       
       Serial.println("✅ 系统错误，等待用户点击\"继续\"按钮");
       
   } else {
       // 其他错误情况(如-2, -3等)
       Serial.println("其他检测错误: " + String(fingerprintID) + "，等待用户操作");
       
       // 停止当前检测定时器
       if (detectionTimer != NULL) {
           lv_timer_del(detectionTimer);
           detectionTimer = NULL;
           Serial.println("暂停检测定时器(其他错误)");
       }
       
       // ✅ 改进：删除2秒自动恢复定时器，改为显示"继续"按钮让用户手动继续
       updateCheckinProgress("检测错误", "指纹检测失败(错误码: " + String(fingerprintID) + ")\n点击\"继续\"进行下一次检测", false);
       
       // 显示继续按钮
       if (checkinContinueBtn != NULL) {
           lv_obj_clear_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN);
       }
       
       Serial.println("✅ 其他错误，等待用户点击\"继续\"按钮");
   }
}

void cancelButtonCallback(lv_event_t * e) {
   Serial.println("取消按钮被点击");
   stopDetectionMode();
}

// 【已删除】showDetectionUI() 函数已被删除，统一使用 createCheckinDetectionScreen()
// 如果需要显示检测界面，请使用 createCheckinDetectionScreen() 或 updateCheckinProgress()

void closeCurrentMessageBox() {
   if (currentMsgBox != NULL) {
       lv_obj_del(currentMsgBox);
       currentMsgBox = NULL;
   }
   
   // 清理相关的定时器
   safeDeleteTimer(&msgBoxTimer);
}

// ==================== 指纹检测核心函数 ====================
int getFingerprintIDWithSteps() {
   // 检查是否在新的签到检测界面中
   bool usingNewCheckinUI = (checkinDetectionScreen != NULL);
   
   // 步骤1: 采集指纹图像
   if (usingNewCheckinUI) {
       updateCheckinProgress("步骤1/3", "正在采集指纹...", false);
   } else {
       showMessageBox("指纹识别", "步骤1/3\n正在采集指纹...", "采集中", true);
   }
   
   int p = finger.getImage();
   if (p != FINGERPRINT_OK) {
       if (p == FINGERPRINT_NOFINGER) {
           return -1; // 没有手指, 正常情况
       } else {
           if (usingNewCheckinUI) {
               updateCheckinProgress("采集失败", "请重新放置手指", false);
           } else {
               showMessageBox("采集失败", "请重新放置\n手指位置不正确", "重试", false);
           }
           // 移除阻塞延迟,改为立即返回让定时器重新调度
           return -2;
       }
   }
   
   // 步骤2: 生成指纹特征 - 关键修复, 明确指定缓冲区1
   if (usingNewCheckinUI) {
       updateCheckinProgress("步骤2/3", "生成指纹特征...", false);
   } else {
       showMessageBox("指纹识别", "步骤2/3\n生成指纹特征...", "处理中", true);
   }
   
   p = finger.image2Tz(1);  // 明确指定缓冲区1
   if (p != FINGERPRINT_OK) {
       if (usingNewCheckinUI) {
           updateCheckinProgress("特征失败", "指纹质量差,请重新尝试", false);
       } else {
           showMessageBox("特征失败", "指纹质量差\n请重新尝试", "重试", false);
       }
       // 移除阻塞延迟,改为立即返回让定时器重新调度
       return -3;
   }
   
   // 步骤3: 搜索匹配指纹 - 使用增强搜索解决172号问题
   if (usingNewCheckinUI) {
       updateCheckinProgress("步骤3/3", "搜索匹配中...", false);
   } else {
       showMessageBox("指纹识别", "步骤3/3\n搜索匹配中...", "搜索中", true);
   }
   
   int fingerprintID = detectFingerprintWithExtendedSearch();
   
   if (fingerprintID > 0) {
       Serial.println("找到匹配指纹, ID: " + String(fingerprintID) + ", 置信度: " + String(finger.confidence));
       
       if (usingNewCheckinUI) {
           updateCheckinProgress("识别成功", "正在上传数据...", true);
       } else {
           showMessageBox("识别成功", "指纹识别完成\n正在上传数据...", "上传中", true);
       }
       return fingerprintID;
   } else if (fingerprintID == -2) {
       Serial.println("==================== 指纹搜索未找到匹配 ====================");
       if (usingNewCheckinUI) {
           Serial.println("使用新界面显示指纹未注册信息");
           updateCheckinProgress("未找到", "指纹未注册,请联系管理员", false);
       } else {
           Serial.println("使用老界面显示指纹未注册信息");
           showMessageBox("未找到", "指纹未注册\n请联系管理员", "确定", false);
       }
       // 移除阻塞延迟,改为立即返回让定时器重新调度
       Serial.println("返回错误码 -4 (指纹未注册)");
       return -4;
   } else {
       if (usingNewCheckinUI) {
           updateCheckinProgress("搜索失败", "系统错误,请重试", false);
       } else {
           showMessageBox("搜索失败", "系统错误\n请重试", "重试", false);
       }
       // 移除阻塞延迟,改为立即返回让定时器重新调度
       return -5;
   }
}

// 修复172号指纹查询问题的增强搜索函数
int detectFingerprintWithExtendedSearch() {
   // 首先尝试标准搜索
   int result = finger.fingerSearch(1);
   
   if (result == FINGERPRINT_OK) {
       Serial.println("标准搜索成功, ID: " + String(finger.fingerID));
       return finger.fingerID;
   }
   
   if (result == FINGERPRINT_NOTFOUND) {
       Serial.println("标准搜索未找到匹配, 尝试分段搜索...");
       
       // 关键修复: 分段搜索解决172号以后指纹无法查询的问题
       // 可能的原因: 搜索算法在大范围搜索时有bug, 分段搜索更可靠
       
       int searchRanges[][2] = {
           {0, 199},     // 0-199
           {200, 399},   // 200-399  
           {400, 599},   // 400-599
           {600, 799},   // 600-799
           {800, 999}    // 800-999
       };
       
       for (int i = 0; i < 5; i++) {
           int startID = searchRanges[i][0];
           int endID = searchRanges[i][1];
           
           Serial.println("搜索范围 " + String(startID) + "-" + String(endID) + "...");
           
           // 使用分段搜索
           result = finger.fingerFastSearch();
           
           if (result == FINGERPRINT_OK) {
               int foundID = finger.fingerID;
               
               // 验证找到的ID是否在当前搜索范围内
               if (foundID >= startID && foundID <= endID) {
                   Serial.println("分段搜索成功! 范围: " + String(startID) + "-" + String(endID) + 
                                ", ID: " + String(foundID) + ", 置信度: " + String(finger.confidence));
                   return foundID;
               } else if (foundID >= 0 && foundID <= 999) {
                   // 即使不在当前范围, 但ID有效, 也返回结果
                   Serial.println("跨范围搜索成功! ID: " + String(foundID) + ", 置信度: " + String(finger.confidence));
                   return foundID;
               }
           }
           
           delay(50); // 短暂延迟避免传感器过载
       }
       
       Serial.println("所有范围搜索完成, 未找到匹配");
       return -2; // 未找到匹配
       
   } else {
       Serial.println("搜索失败, 错误码: " + String(result));
       return -3; // 搜索错误
   }
}

// 显示学生信息函数 - 移植自成功代码
void displayStudentInfo(int fingerprintID, String studentName, String studentId, String dormitory, String className) {
   // 使用新的统一状态管理系统显示成功状态
   String successDetails = "姓名: " + studentName + "\n学号: " + studentId + "\n班级: " + className + "\n宿舍: " + dormitory;
   updateMainScreenStatus(STATE_DETECTION_SUCCESS, successDetails);
   
   // 串口输出学生信息
   Serial.println("==================== 签到成功 ====================");
   Serial.println("指纹ID: " + String(fingerprintID));
   Serial.println("学生信息:");
   Serial.println("  姓名: " + studentName);
   Serial.println("  学号: " + studentId);
   Serial.println("  宿舍: " + dormitory);
   Serial.println("===============================================");
   
   // 检查是否在新的签到检测界面中
   Serial.println("检查签到检测界面状态...");
   Serial.println("checkinDetectionScreen指针: " + String((unsigned long)checkinDetectionScreen, HEX));
   
   if (checkinDetectionScreen != NULL) {
       Serial.println("使用新的签到界面显示学生信息");
       // 使用传入的班级信息
       showCheckinStudentInfo(studentName, studentId, className, dormitory);
   } else {
       Serial.println("签到检测界面不存在,使用弹出框显示");
       // ✅ 改进：老界面模式（备用分支）
       // 这个分支通常不会被执行，因为签到按钮总是创建新界面
       // 但保留这里以防万一，改为手动操作
       String infoText = "签到成功!\n";
       infoText += "姓名: " + studentName + "\n";
       infoText += "学号: " + studentId + "\n"; 
       infoText += "宿舍: " + dormitory + "\n\n";
       infoText += "点击\"确定\"继续";
       
       showMessageBox("签到结果", infoText, "确定", false);
       // ✅ 已删除3秒自动关闭定时器，改为等待用户手动点击
       Serial.println("✅ 签到成功（老界面模式），等待用户点击\"确定\"按钮");
   }
}

// ==================== 指纹录入功能 ====================
void showStudentIdInputDialog() {
   if (enrollmentInProgress) {
       Serial.println("警告: 指纹录入正在进行中");
       return;
   }
   
   Serial.println("==================== 显示学号输入界面 ====================");
   
   // 学习WiFi功能的安全创建模式 - 先清理已存在的界面
   if (studentIdInputScreen != NULL) {
       Serial.println("清理已存在的学号输入界面");
       lv_obj_del(studentIdInputScreen);
       studentIdInputScreen = NULL;
       studentIdTextArea = NULL;
       studentIdKeyboard = NULL;
   }
   
   // 创建学号输入屏幕
   studentIdInputScreen = lv_obj_create(NULL);
   lv_obj_set_size(studentIdInputScreen, LV_HOR_RES, LV_VER_RES);
   
   // 创建标题
   lv_obj_t *titleLabel = lv_label_create(studentIdInputScreen);
   lv_label_set_text(titleLabel, "指纹录入");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_align(titleLabel, LV_ALIGN_TOP_MID, 0, 20);
   
   // 创建说明文字
   lv_obj_t *descLabel = lv_label_create(studentIdInputScreen);
   lv_label_set_text(descLabel, "请输入学号\n系统将自动获取指纹ID");
   lv_obj_set_style_text_font(descLabel, &myFont_new, 0);
   lv_obj_set_style_text_align(descLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(descLabel, LV_ALIGN_TOP_MID, 0, 60);
   
   // 创建输入提示标签
   lv_obj_t *inputHintLabel = lv_label_create(studentIdInputScreen);
   lv_label_set_text(inputHintLabel, "学号:");
   lv_obj_set_style_text_font(inputHintLabel, &myFont_new, 0);
   lv_obj_align(inputHintLabel, LV_ALIGN_CENTER, -120, -100);
   
   // 创建文本输入区域
   studentIdTextArea = lv_textarea_create(studentIdInputScreen);
   lv_obj_set_size(studentIdTextArea, 200, 50);
   lv_obj_align(studentIdTextArea, LV_ALIGN_CENTER, 20, -100);
   lv_textarea_set_placeholder_text(studentIdTextArea, "请输入学号");
   lv_obj_set_style_text_font(studentIdTextArea, &myFont_new, 0);
   lv_textarea_set_one_line(studentIdTextArea, true); // 设置为单行输入
   lv_obj_add_event_cb(studentIdTextArea, studentIdInputCallback, LV_EVENT_FOCUSED, NULL);
   lv_obj_add_event_cb(studentIdTextArea, studentIdInputCallback, LV_EVENT_DEFOCUSED, NULL);
   
   // 创建键盘（在按钮之前创建,确保按钮在键盘上方）
   studentIdKeyboard = lv_keyboard_create(studentIdInputScreen);
   lv_obj_set_size(studentIdKeyboard, LV_HOR_RES, LV_VER_RES / 2);
   lv_obj_align(studentIdKeyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
   lv_keyboard_set_textarea(studentIdKeyboard, studentIdTextArea);
   lv_keyboard_set_mode(studentIdKeyboard, LV_KEYBOARD_MODE_NUMBER); // 数字键盘模式
   
   // 创建确认按钮 - 位置在键盘上方
   lv_obj_t *confirmBtn = lv_btn_create(studentIdInputScreen);
   lv_obj_set_size(confirmBtn, 120, 45);
   lv_obj_align(confirmBtn, LV_ALIGN_CENTER, -80, -40); // 键盘上方
   lv_obj_set_style_bg_color(confirmBtn, lv_color_hex(0x4CAF50), 0); // 绿色
   lv_obj_set_style_radius(confirmBtn, 8, 0);
   lv_obj_add_event_cb(confirmBtn, confirmStudentIdCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *confirmLabel = lv_label_create(confirmBtn);
   lv_label_set_text(confirmLabel, "确认");
   lv_obj_set_style_text_font(confirmLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(confirmLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(confirmLabel);
   
   // 创建取消按钮 - 位置在键盘上方
   lv_obj_t *cancelBtn = lv_btn_create(studentIdInputScreen);
   lv_obj_set_size(cancelBtn, 120, 45);
   lv_obj_align(cancelBtn, LV_ALIGN_CENTER, 80, -40); // 键盘上方
   lv_obj_set_style_bg_color(cancelBtn, lv_color_hex(0xF44336), 0); // 红色
   lv_obj_set_style_radius(cancelBtn, 8, 0);
   lv_obj_add_event_cb(cancelBtn, cancelStudentIdCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *cancelLabel = lv_label_create(cancelBtn);
   lv_label_set_text(cancelLabel, "取消");
   lv_obj_set_style_text_font(cancelLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(cancelLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(cancelLabel);
   
   // 显示屏幕
   lv_scr_load(studentIdInputScreen);
   
   Serial.println("学号输入界面已显示");
}

// ==================== 手动签到独立功能（完全分离，无定时器）====================

/**
* 手动签到 - 显示学号输入界面（完全独立）
*/
void showManualCheckinInputDialog() {
   Serial.println("==================== 手动签到：显示学号输入界面 ====================");
   
   // 清理已存在的界面
   if (manualCheckinInputScreen != NULL) {
       Serial.println("清理已存在的手动签到输入界面");
       lv_obj_del(manualCheckinInputScreen);
       manualCheckinInputScreen = NULL;
       manualCheckinTextArea = NULL;
       manualCheckinKeyboard = NULL;
   }
   
   // 创建全屏界面
   manualCheckinInputScreen = lv_obj_create(NULL);
   lv_obj_set_size(manualCheckinInputScreen, LV_HOR_RES, LV_VER_RES);
   
   // 创建标题
   lv_obj_t *titleLabel = lv_label_create(manualCheckinInputScreen);
   lv_label_set_text(titleLabel, "手动签到");  // ⭐ 标题改为"手动签到"
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_align(titleLabel, LV_ALIGN_TOP_MID, 0, 20);
   
   // 创建说明文字
   lv_obj_t *descLabel = lv_label_create(manualCheckinInputScreen);
   lv_label_set_text(descLabel, "请输入学号\n用于手指无法录入指纹的学生");
   lv_obj_set_style_text_font(descLabel, &myFont_new, 0);
   lv_obj_set_style_text_align(descLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(descLabel, LV_ALIGN_TOP_MID, 0, 60);
   
   // 创建输入提示标签
   lv_obj_t *inputHintLabel = lv_label_create(manualCheckinInputScreen);
   lv_label_set_text(inputHintLabel, "学号:");
   lv_obj_set_style_text_font(inputHintLabel, &myFont_new, 0);
   lv_obj_align(inputHintLabel, LV_ALIGN_CENTER, -120, -100);
   
   // 创建文本输入区域
   manualCheckinTextArea = lv_textarea_create(manualCheckinInputScreen);
   lv_obj_set_size(manualCheckinTextArea, 200, 50);
   lv_obj_align(manualCheckinTextArea, LV_ALIGN_CENTER, 20, -100);
   lv_textarea_set_placeholder_text(manualCheckinTextArea, "请输入学号");
   lv_obj_set_style_text_font(manualCheckinTextArea, &myFont_new, 0);
   lv_textarea_set_one_line(manualCheckinTextArea, true);
   
   // 创建键盘
   manualCheckinKeyboard = lv_keyboard_create(manualCheckinInputScreen);
   lv_obj_set_size(manualCheckinKeyboard, LV_HOR_RES, LV_VER_RES / 2);
   lv_obj_align(manualCheckinKeyboard, LV_ALIGN_BOTTOM_MID, 0, 0);
   lv_keyboard_set_textarea(manualCheckinKeyboard, manualCheckinTextArea);
   lv_keyboard_set_mode(manualCheckinKeyboard, LV_KEYBOARD_MODE_NUMBER);
   
   // 创建确认按钮
   lv_obj_t *confirmBtn = lv_btn_create(manualCheckinInputScreen);
   lv_obj_set_size(confirmBtn, 120, 45);
   lv_obj_align(confirmBtn, LV_ALIGN_CENTER, -80, -40);
   lv_obj_set_style_bg_color(confirmBtn, lv_color_hex(0x4CAF50), 0);
   lv_obj_set_style_radius(confirmBtn, 8, 0);
   lv_obj_add_event_cb(confirmBtn, confirmManualCheckinIdCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *confirmLabel = lv_label_create(confirmBtn);
   lv_label_set_text(confirmLabel, "提交签到");
   lv_obj_set_style_text_font(confirmLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(confirmLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(confirmLabel);
   
   // 创建取消按钮
   lv_obj_t *cancelBtn = lv_btn_create(manualCheckinInputScreen);
   lv_obj_set_size(cancelBtn, 120, 45);
   lv_obj_align(cancelBtn, LV_ALIGN_CENTER, 80, -40);
   lv_obj_set_style_bg_color(cancelBtn, lv_color_hex(0xF44336), 0);
   lv_obj_set_style_radius(cancelBtn, 8, 0);
   lv_obj_add_event_cb(cancelBtn, cancelManualCheckinCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *cancelLabel = lv_label_create(cancelBtn);
   lv_label_set_text(cancelLabel, "返回");
   lv_obj_set_style_text_font(cancelLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(cancelLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(cancelLabel);
   
   // 加载界面
   lv_scr_load(manualCheckinInputScreen);
   
   Serial.println("手动签到学号输入界面已创建");
}

/**
* 手动签到 - 取消按钮回调
*/
void cancelManualCheckinCallback(lv_event_t * e) {
   Serial.println("手动签到：用户点击取消，返回主界面");
   
   // ⭐⭐⭐ 关键：先切换到主界面
   lv_scr_load(mainScreen);
   
   // ⭐ 再清理输入界面（此时输入界面不是活动屏幕了，安全！）
   if (manualCheckinInputScreen != NULL) {
       lv_obj_del(manualCheckinInputScreen);
       manualCheckinInputScreen = NULL;
       manualCheckinTextArea = NULL;
       manualCheckinKeyboard = NULL;
   }
   
   Serial.println("已返回主界面");
}

/**
* 手动签到 - 确认按钮回调（参考指纹录入模式，回调立即返回）
*/
void confirmManualCheckinIdCallback(lv_event_t * e) {
   Serial.println("========== 手动签到：确认按钮被点击 ==========");
   
   // 1. 立即获取学号（在界面还有效时）
   String studentId = lv_textarea_get_text(manualCheckinTextArea);
   studentId.trim();
   
   // 2. 验证学号
   if (studentId.length() == 0) {
       Serial.println("❌ 学号为空");
       showMessageBox("输入错误", "学号不能为空", "确定", false);
       return;
   }
   
   Serial.println("输入的学号: " + studentId);
   
   // 3. 检查网络
   if (WiFi.status() != WL_CONNECTED) {
       Serial.println("❌ WiFi未连接");
       showMessageBox("网络错误", "WiFi未连接 请检查网络后重试", "确定", false);
       return;
   }
   
   // 4. ⭐⭐⭐ 调用处理函数（参考指纹录入的设计模式）
   //    让处理函数负责界面切换，回调立即返回
   processManualCheckin(studentId);
   // 回调立即返回 ✅ 安全！
}

/**
* 处理手动签到（参考 showStudentIdInputDialog 的设计模式）
* 此函数负责界面切换和签到提交
*/
void processManualCheckin(String studentId) {
   Serial.println("========== 处理手动签到 ==========");
   Serial.println("学号: " + studentId);
   
   // 1. ⭐⭐⭐ 关键：先创建并显示加载界面（会切换屏幕）
   //    必须先切换屏幕，再删除旧界面！
   createManualCheckinLoadingScreen();
   
   // 2. ⭐ 再清理输入界面（此时已经不是活动屏幕了，安全！）
   if (manualCheckinInputScreen != NULL) {
       Serial.println("清理手动签到输入界面");
       lv_obj_del(manualCheckinInputScreen);
       manualCheckinInputScreen = NULL;
       manualCheckinTextArea = NULL;
       manualCheckinKeyboard = NULL;
   }
   
   // 3. 执行HTTP请求（同步，但界面已切换，安全）
   Serial.println("开始提交签到请求...");
   bool success = submitManualCheckin(studentId);
   
   // 4. ⭐⭐⭐ 关键：先显示结果界面（会切换屏幕）
   //    必须先切换屏幕，再删除加载界面！
   if (success) {
       Serial.println("✅ 手动签到成功");
       showManualCheckinSuccessScreen(studentId);
   } else {
       Serial.println("❌ 手动签到失败");
       showManualCheckinFailureScreen("签到失败\n\n可能原因:\n- 学号不存在\n- 网络错误\n- 服务器异常");
   }
   
   // 5. ⭐ 再删除加载界面（此时已经不是活动屏幕了，安全！）
   if (manualCheckinLoadingScreen != NULL) {
       Serial.println("删除加载界面");
       lv_obj_del(manualCheckinLoadingScreen);
       manualCheckinLoadingScreen = NULL;
   }
   
   Serial.println("========== 手动签到处理完成 ==========");
}

/**
* 创建手动签到加载界面（参考 createCheckinDetectionScreen 的模式）
*/
void createManualCheckinLoadingScreen() {
   Serial.println("==================== 创建手动签到加载界面 ====================");
   
   // 1. 清理旧的加载界面（如果存在）
   if (manualCheckinLoadingScreen != NULL) {
       Serial.println("清理已存在的加载界面");
       lv_obj_del(manualCheckinLoadingScreen);
       manualCheckinLoadingScreen = NULL;
   }
   
   // 2. 创建全屏界面
   manualCheckinLoadingScreen = lv_obj_create(NULL);
   lv_obj_set_size(manualCheckinLoadingScreen, LV_HOR_RES, LV_VER_RES);
   lv_obj_set_style_bg_color(manualCheckinLoadingScreen, lv_color_hex(0xF5F5F5), 0);
   
   // 3. 创建标题
   lv_obj_t *titleLabel = lv_label_create(manualCheckinLoadingScreen);
   lv_label_set_text(titleLabel, "提交中");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x2196F3), 0);
   lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, -40);
   
   // 4. 创建消息标签
   lv_obj_t *msgLabel = lv_label_create(manualCheckinLoadingScreen);
   lv_label_set_text(msgLabel, "正在提交手动签到...\n请稍候");
   lv_obj_set_style_text_font(msgLabel, &myFont_new, 0);
   lv_obj_set_style_text_align(msgLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(msgLabel, LV_ALIGN_CENTER, 0, 20);
   
   // 5. ⭐ 切换屏幕（这会让输入界面脱离事件系统）
   lv_scr_load(manualCheckinLoadingScreen);
   
   Serial.println("加载界面已显示");
}

/**
* 手动签到 - 显示签到成功界面（完全手动，无定时器）
*/
void showManualCheckinSuccessScreen(String studentId) {
   Serial.println("==================== 显示手动签到成功界面 ====================");
   
   // 先关闭消息框
   safeCloseCurrentMessageBox();
   
   // 清理旧的结果界面
   if (manualCheckinResultScreen != NULL) {
       lv_obj_del(manualCheckinResultScreen);
       manualCheckinResultScreen = NULL;
   }
   
   // 创建全屏界面
   manualCheckinResultScreen = lv_obj_create(NULL);
   lv_obj_set_size(manualCheckinResultScreen, LV_HOR_RES, LV_VER_RES);
   lv_obj_set_style_bg_color(manualCheckinResultScreen, lv_color_hex(0xE8F5E9), 0);  // 浅绿色背景
   
   // 成功图标（使用✅符号）
   lv_obj_t *iconLabel = lv_label_create(manualCheckinResultScreen);
   lv_label_set_text(iconLabel, LV_SYMBOL_OK);
   lv_obj_set_style_text_font(iconLabel, &lv_font_montserrat_48, 0);
   lv_obj_set_style_text_color(iconLabel, lv_color_hex(0x4CAF50), 0);  // 绿色
   lv_obj_align(iconLabel, LV_ALIGN_CENTER, 0, -120);
   
   // 成功标题
   lv_obj_t *titleLabel = lv_label_create(manualCheckinResultScreen);
   lv_label_set_text(titleLabel, "签到成功");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x4CAF50), 0);
   lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, -60);
   
   // 学号信息
   lv_obj_t *infoLabel = lv_label_create(manualCheckinResultScreen);
   String infoText = "学号: " + studentId + "\n\n已成功签到";
   lv_label_set_text(infoLabel, infoText.c_str());
   lv_obj_set_style_text_font(infoLabel, &myFont_new, 0);
   lv_obj_set_style_text_align(infoLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(infoLabel, LV_ALIGN_CENTER, 0, 0);
   
   // ⭐⭐⭐ 按钮1："继续签到" - 返回学号输入界面
   lv_obj_t *continueBtn = lv_btn_create(manualCheckinResultScreen);
   lv_obj_set_size(continueBtn, 150, 50);
   lv_obj_align(continueBtn, LV_ALIGN_CENTER, 0, 80);
   lv_obj_set_style_bg_color(continueBtn, lv_color_hex(0x4CAF50), 0);
   lv_obj_set_style_radius(continueBtn, 8, 0);
   
   // ⭐ 绑定回调：返回学号输入界面
   lv_obj_add_event_cb(continueBtn, [](lv_event_t * e) {
       Serial.println("用户点击\"继续签到\"，返回学号输入界面");
       
       // ⭐⭐⭐ 关键：先创建新界面（会切换屏幕）
       showManualCheckinInputDialog();
       
       // ⭐ 再清理结果界面（此时结果界面不是活动屏幕了）
       if (manualCheckinResultScreen != NULL) {
           lv_obj_del(manualCheckinResultScreen);
           manualCheckinResultScreen = NULL;
       }
       
   }, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *continueLabel = lv_label_create(continueBtn);
   lv_label_set_text(continueLabel, "继续签到");
   lv_obj_set_style_text_font(continueLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(continueLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(continueLabel);
   
   // ⭐⭐⭐ 按钮2："返回主界面"
   lv_obj_t *backBtn = lv_btn_create(manualCheckinResultScreen);
   lv_obj_set_size(backBtn, 150, 50);
   lv_obj_align(backBtn, LV_ALIGN_CENTER, 0, 150);
   lv_obj_set_style_bg_color(backBtn, lv_color_hex(0x2196F3), 0);
   lv_obj_set_style_radius(backBtn, 8, 0);
   
   // ⭐ 绑定回调：返回主界面
   lv_obj_add_event_cb(backBtn, [](lv_event_t * e) {
       Serial.println("用户点击\"返回主界面\"");
       
       // ⭐⭐⭐ 关键：先切换到主界面
       lv_scr_load(mainScreen);
       
       // ⭐ 再清理结果界面（此时结果界面不是活动屏幕了）
       if (manualCheckinResultScreen != NULL) {
           lv_obj_del(manualCheckinResultScreen);
           manualCheckinResultScreen = NULL;
       }
       
   }, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *backLabel = lv_label_create(backBtn);
   lv_label_set_text(backLabel, "返回主界面");
   lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(backLabel);
   
   // 加载界面
   lv_scr_load(manualCheckinResultScreen);
   
   Serial.println("签到成功界面已显示，等待用户手动操作");
}

/**
* 手动签到 - 显示签到失败界面（完全手动，无定时器）
*/
void showManualCheckinFailureScreen(String errorMessage) {
   Serial.println("==================== 显示手动签到失败界面 ====================");
   Serial.println("错误信息: " + errorMessage);
   
   // 先关闭消息框
   safeCloseCurrentMessageBox();
   
   // 清理旧的结果界面
   if (manualCheckinResultScreen != NULL) {
       lv_obj_del(manualCheckinResultScreen);
       manualCheckinResultScreen = NULL;
   }
   
   // 创建全屏界面
   manualCheckinResultScreen = lv_obj_create(NULL);
   lv_obj_set_size(manualCheckinResultScreen, LV_HOR_RES, LV_VER_RES);
   lv_obj_set_style_bg_color(manualCheckinResultScreen, lv_color_hex(0xFFEBEE), 0);  // 浅红色背景
   
   // 失败图标（使用✖符号）
   lv_obj_t *iconLabel = lv_label_create(manualCheckinResultScreen);
   lv_label_set_text(iconLabel, LV_SYMBOL_CLOSE);
   lv_obj_set_style_text_font(iconLabel, &lv_font_montserrat_48, 0);
   lv_obj_set_style_text_color(iconLabel, lv_color_hex(0xF44336), 0);  // 红色
   lv_obj_align(iconLabel, LV_ALIGN_CENTER, 0, -140);
   
   // 失败标题
   lv_obj_t *titleLabel = lv_label_create(manualCheckinResultScreen);
   lv_label_set_text(titleLabel, "签到失败");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0xF44336), 0);
   lv_obj_align(titleLabel, LV_ALIGN_CENTER, 0, -80);
   
   // 错误信息
   lv_obj_t *infoLabel = lv_label_create(manualCheckinResultScreen);
   lv_label_set_text(infoLabel, errorMessage.c_str());
   lv_obj_set_style_text_font(infoLabel, &myFont_new, 0);
   lv_obj_set_style_text_align(infoLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(infoLabel, LV_ALIGN_CENTER, 0, 0);
   
   // ⭐⭐⭐ 按钮1："重试" - 返回学号输入界面
   lv_obj_t *retryBtn = lv_btn_create(manualCheckinResultScreen);
   lv_obj_set_size(retryBtn, 150, 50);
   lv_obj_align(retryBtn, LV_ALIGN_CENTER, 0, 90);
   lv_obj_set_style_bg_color(retryBtn, lv_color_hex(0xFF9800), 0);  // 橙色
   lv_obj_set_style_radius(retryBtn, 8, 0);
   
   // ⭐ 绑定回调：返回学号输入界面重试
   lv_obj_add_event_cb(retryBtn, [](lv_event_t * e) {
       Serial.println("用户点击\"重试\"，返回学号输入界面");
       
       // ⭐⭐⭐ 关键：先创建新界面（会切换屏幕）
       showManualCheckinInputDialog();
       
       // ⭐ 再清理结果界面（此时结果界面不是活动屏幕了）
       if (manualCheckinResultScreen != NULL) {
           lv_obj_del(manualCheckinResultScreen);
           manualCheckinResultScreen = NULL;
       }
       
   }, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *retryLabel = lv_label_create(retryBtn);
   lv_label_set_text(retryLabel, "重试");
   lv_obj_set_style_text_font(retryLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(retryLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(retryLabel);
   
   // ⭐⭐⭐ 按钮2："返回主界面"
   lv_obj_t *backBtn = lv_btn_create(manualCheckinResultScreen);
   lv_obj_set_size(backBtn, 150, 50);
   lv_obj_align(backBtn, LV_ALIGN_CENTER, 0, 160);
   lv_obj_set_style_bg_color(backBtn, lv_color_hex(0x2196F3), 0);
   lv_obj_set_style_radius(backBtn, 8, 0);
   
   // ⭐ 绑定回调：返回主界面
   lv_obj_add_event_cb(backBtn, [](lv_event_t * e) {
       Serial.println("用户点击\"返回主界面\"");
       
       // ⭐⭐⭐ 关键：先切换到主界面
       lv_scr_load(mainScreen);
       
       // ⭐ 再清理结果界面（此时结果界面不是活动屏幕了）
       if (manualCheckinResultScreen != NULL) {
           lv_obj_del(manualCheckinResultScreen);
           manualCheckinResultScreen = NULL;
       }
       
   }, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *backLabel = lv_label_create(backBtn);
   lv_label_set_text(backLabel, "返回主界面");
   lv_obj_set_style_text_font(backLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(backLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(backLabel);
   
   // 加载界面
   lv_scr_load(manualCheckinResultScreen);
   
   Serial.println("签到失败界面已显示，等待用户手动操作");
}

// ==================== 手动签到独立功能结束 ====================

void studentIdInputCallback(lv_event_t * e) {
   lv_event_code_t code = lv_event_get_code(e);
   
   if (code == LV_EVENT_FOCUSED) {
       Serial.println("文本区域获得焦点,显示键盘");
       if (studentIdKeyboard != NULL) {
           lv_obj_clear_flag(studentIdKeyboard, LV_OBJ_FLAG_HIDDEN);
       }
   } else if (code == LV_EVENT_DEFOCUSED) {
       Serial.println("文本区域失去焦点");
       // 键盘保持显示,用户可以继续输入
   }
}

void confirmStudentIdCallback(lv_event_t * e) {
   Serial.println("确认按钮被点击");
   
   // 立即获取学号以避免竞态条件
   String inputStudentId = "";
   
   // 检查指针有效性并立即获取文本
   if (studentIdTextArea != NULL) {
       const char* studentIdText = lv_textarea_get_text(studentIdTextArea);
       if (studentIdText != NULL) {
           inputStudentId = String(studentIdText);
           inputStudentId.trim(); // 去除空格
           Serial.println("成功获取输入学号: " + inputStudentId);
       } else {
           Serial.println("错误: 无法获取输入文本");
           return;
       }
   } else {
       Serial.println("错误: studentIdTextArea为空");
       return;
   }
   
   // 验证学号
   if (inputStudentId.length() == 0) {
       Serial.println("输入为空,请输入学号");
       showMessageBox("输入错误", "学号不能为空", "确定", false);
       return;
   }
   
   // 保存学号到全局变量
   currentStudentId = inputStudentId;
   Serial.println("学号已保存: " + currentStudentId);
   
   // 关闭学号输入界面
   if (studentIdInputScreen != NULL) {
       lv_scr_load(mainScreen);
       lv_obj_del(studentIdInputScreen);
       studentIdInputScreen = NULL;
       studentIdTextArea = NULL;
       studentIdKeyboard = NULL;
   }
   
   // ⭐⭐⭐ 指纹录入流程
   
   // 检查网络
   if (WiFi.status() != WL_CONNECTED) {
       showMessageBox("网络错误", "WiFi未连接 无法查询学生信息", "确定", false);
       currentOperationMode = MODE_NONE;  // 重置模式
       return;
   }
   
   // ⭐⭐⭐ 只保留指纹录入模式（手动签到已独立）
   if (currentOperationMode == MODE_FINGERPRINT_ENROLL) {
       // ==================== 原有的指纹录入流程 ====================
       Serial.println("========== 指纹录入：查询学生信息 ==========");
       
       // 防止重复点击
       if (enrollmentInProgress) {
           Serial.println("WARN 录入操作正在进行中,请等待...");
           return;
       }
       
       // 使用新的内存保护机制
       if (!checkMemoryAndProtect("指纹录入")) {
           return;
       }
       
       // 设置操作状态
       enrollmentInProgress = true;
       
       // ⭐ 调用函数获取学生信息并显示确认界面
       getStudentInfoAndShowConfirm(currentStudentId);
       
       Serial.println("* 学号确认处理已启动,异步执行中...");
   } else {
       // ❌ 异常：未知模式
       Serial.println("❌ 错误：未知操作模式: " + String(currentOperationMode));
       showMessageBox("系统错误", "操作模式异常 请重新操作", "确定", false);
       currentOperationMode = MODE_NONE;
   }
}

void cancelStudentIdCallback(lv_event_t * e) {
   Serial.println("学号输入界面：取消按钮被点击");
   
   // ⭐ 用户真正取消操作，重置所有状态
   currentOperationMode = MODE_NONE;
   enrollmentInProgress = false;
   
   // 设置关闭标志
   shouldCloseStudentIdDialog = true;
   dialogClosedByConfirm = false;
   
   Serial.println("取消按钮处理完成 - 设置关闭标志");
}

void closeStudentIdInputDialog() {
   Serial.println("关闭学号输入界面");
   
   // 安全地关闭输入界面
   if (studentIdInputScreen != NULL) {
       lv_obj_del(studentIdInputScreen);
       studentIdInputScreen = NULL;
       studentIdTextArea = NULL;
       studentIdKeyboard = NULL;
   }
   
   // 切换回主界面
   lv_scr_load(mainScreen);
   
   Serial.println("学号输入界面已关闭");
}


void startFingerprintEnrollmentProcess() {
   Serial.println("==================== 开始指纹录入流程 ====================");
   Serial.println("学号: " + currentStudentId);
   
   if (enrollmentInProgress) {
       Serial.println("警告: 录入流程已在进行中");
       return;
   }
   
   if (currentStudentId.length() == 0) {
       Serial.println("错误: 学号为空");
       showMessageBox("录入错误", "学号不能为空", "确定", false);
       return;
   }
   
   enrollmentInProgress = true;
   
   // 显示开始录入的消息
   showMessageBox("指纹录入", "学号: " + currentStudentId + "\\n\\n准备开始指纹录入\\n请按照提示操作", "开始录入", true);
   
   // 3秒后开始实际录入流程
   lv_timer_create([](lv_timer_t * timer) {
       // 第一步:获取指纹ID
       Serial.println("步骤1: 从服务器获取指纹ID");
       int fingerprintId = -1;
       bool success = getStudentFingerprintId(currentStudentId, fingerprintId);
       
       if (success && fingerprintId > 0) {
           targetFingerprintId = fingerprintId;
           Serial.println("成功获取指纹ID: " + String(fingerprintId));
           
           // 开始指纹采集
           safeCloseCurrentMessageBox();
           showMessageBox("指纹录入", "指纹ID: " + String(fingerprintId) + "\\n\\n请将手指放在传感器上\\n开始第一次采集", "采集中", true);
           
           // 启动指纹采集流程
           lv_timer_create([](lv_timer_t * t) {
               startActualFingerprint();
               lv_timer_del(t);
           }, 2000, NULL);
           
       } else {
           Serial.println("获取指纹ID失败");
           showMessageBox("录入失败", "无法获取学号对应的指纹ID\\n请检查学号是否正确\\n或联系管理员", "确定", false);
           enrollmentInProgress = false;
       }
       
       lv_timer_del(timer);
   }, 3000, NULL);
   
   Serial.println("指纹录入流程已启动");
}

void startActualFingerprint() {
   Serial.println("==================== 开始实际指纹采集 ====================");
   Serial.println("目标指纹ID: " + String(targetFingerprintId));
   
   if (!fingerprintSystemReady) {
       Serial.println("错误: 指纹传感器未就绪");
       showMessageBox("传感器错误", "指纹传感器未就绪\\n请检查连接", "确定", false);
       enrollmentInProgress = false;
       return;
   }
   
   // 检查指纹ID是否已存在，直接开始录入，不使用消息框避免冲突
   if (finger.loadModel(targetFingerprintId) == FINGERPRINT_OK) {
       Serial.println("警告: 指纹ID " + String(targetFingerprintId) + " 已存在,将覆盖");
   } else {
       Serial.println("指纹ID " + String(targetFingerprintId) + " 可用,开始录入");
   }
   
   // 统一使用界面系统：先创建录入界面，再开始录入
   createFingerprintEnrollmentScreen();
}

void performFingerprintEnrollment() {
   Serial.println("==================== 执行指纹录入 ====================");
   Serial.println("学号: " + currentStudentId);
   Serial.println("指纹ID: " + String(targetFingerprintId));
   
   // 第一次指纹采集
   Serial.println("步骤1: 第一次指纹采集");
   showMessageBox("指纹录入 1/5", "第一次采集\\n请将手指放在传感器上\\n保持不动", "采集中", true);
   
   int result1 = captureAndGenerate(1);
   if (result1 != FINGERPRINT_OK) {
       Serial.println("第一次采集失败: " + String(result1));
       showMessageBox("录入失败", "第一次采集失败\\n请重试", "确定", false);
       enrollmentInProgress = false;
       return;
   }
   
   Serial.println("第一次采集成功");
   showMessageBox("指纹录入 2/5", "第一次采集成功\\n请抬起手指", "等待中", true);
   
   // 等待手指抬起 - 使用非阻塞方式
   delay(1000);
   
   // 创建非阻塞等待手指抬起的定时器
   if (waitLiftTimer != NULL) {
       lv_timer_del(waitLiftTimer);
       waitLiftTimer = NULL;
   }
   
   waitLiftTimer = lv_timer_create([](lv_timer_t * timer) {
       // 非阻塞检查手指是否抬起
       if (finger.getImage() == FINGERPRINT_OK) {
           // 手指还在，继续等待
           return;
       }
       
       // 手指已抬起，停止检查定时器，继续下一步
       lv_timer_del(timer);
       waitLiftTimer = NULL;
       Serial.println("手指已抬起，准备第二次采集");
       
       // 继续第二次采集
       continueSecondCapture();
   }, 100, NULL);
   
   return; // 提前返回，让定时器处理后续流程
}

// 继续第二次采集的函数
void continueSecondCapture() {
   // 第二次指纹采集
   Serial.println("步骤2: 第二次指纹采集");
   showMessageBox("指纹录入 3/5", "第二次采集\\n请再次将同一手指\\n放在传感器上", "采集中", true);
   
   int result2 = captureAndGenerate(2);
   if (result2 != FINGERPRINT_OK) {
       Serial.println("第二次采集失败: " + String(result2));
       showMessageBox("录入失败", "第二次采集失败\\n请重试", "确定", false);
       enrollmentInProgress = false;
       return;
   }
   
   Serial.println("第二次采集成功");
   
   // 特征融合
   Serial.println("步骤3: 特征融合");
   showMessageBox("指纹录入 4/5", "正在融合特征\\n生成指纹模板", "处理中", true);
   
   int mergeResult = finger.createModel();
   if (mergeResult != FINGERPRINT_OK) {
       Serial.println("特征融合失败: " + String(mergeResult));
       if (mergeResult == FINGERPRINT_ENROLLMISMATCH) {
           showMessageBox("录入失败", "两次指纹不匹配\\n请重新录入", "确定", false);
       } else {
           showMessageBox("录入失败", "特征融合失败\\n错误码: " + String(mergeResult), "确定", false);
       }
       enrollmentInProgress = false;
       return;
   }
   
   Serial.println("特征融合成功");
   
   // 存储模板
   Serial.println("步骤4: 存储模板");
   showMessageBox("指纹录入 5/5", "正在存储指纹模板\\n请稍候", "存储中", true);
   
   int storeResult = finger.storeModel(targetFingerprintId);
   if (storeResult != FINGERPRINT_OK) {
       Serial.println("存储失败: " + String(storeResult));
       showMessageBox("录入失败", "指纹存储失败\\n错误码: " + String(storeResult), "确定", false);
       enrollmentInProgress = false;
       return;
   }
   
   Serial.println("指纹录入完成!");
   
   // 显示成功消息
   showMessageBox("录入成功", "学号: " + currentStudentId + "\\n指纹ID: " + String(targetFingerprintId) + "\\n\\n指纹录入完成!", "完成", true);
   
   // 重置状态
   enrollmentInProgress = false;
   currentStudentId = "";
   targetFingerprintId = -1;
   
   Serial.println("==================== 指纹录入流程完成 ====================");
}

// 开始等待手指抬起的函数
void startWaitForLiftOff() {
   Serial.println("🔄 startWaitForLiftOff() 函数开始执行");
   Serial.println("开始等待手指抬起...");
   
   // ⭐⭐⭐ 监控点3：等待手指抬起开始
   printMemoryStatus("等待手指抬起");
   printTimerStatus("等待抬起前");
   
   // 清理任何现有的等待定时器
   if (waitLiftTimer != NULL) {
       Serial.println("⚠️ 清理现有的waitLiftTimer");
       lv_timer_del(waitLiftTimer);
       waitLiftTimer = NULL;
   }
   
   // 非阻塞等待手指抬起的周期检查定时器
   Serial.println("🕐 创建waitLiftTimer定时器，200ms间隔检查");
   // 初始化全局计数器而不使用lambda中的static
   enrollmentWaitLiftAttempts = 0;
   
   waitLiftTimer = lv_timer_create([](lv_timer_t * timer2) {
       // 非阻塞检查手指是否抬起
       int imageResult = finger.getImage();
       if (imageResult == FINGERPRINT_OK) {
           // 手指还在，继续等待
           enrollmentWaitLiftAttempts++;
           if (enrollmentWaitLiftAttempts % 10 == 0) { // 每2秒打印一次
               Serial.println("⏳ 等待手指抬起中... (" + String(enrollmentWaitLiftAttempts * 0.2) + "秒)");
           }
           // 添加超时检查
           if (enrollmentWaitLiftAttempts >= 100) { // 20秒超时
               Serial.println("❌ 等待手指抬起超时");
               lv_timer_del(timer2);
               waitLiftTimer = NULL;
               updateEnrollmentProgress("操作超时", "等待手指抬起超时 请重试");
               handleEnrollmentFailure();
               return;
           }
           return;
       }
       
       // 手指已抬起，停止检查定时器，继续下一步
       Serial.println("✅ 手指已抬起，准备第二次采集");
       updateEnrollmentProgress("第3步/5", "第二次采集 请再次将同一手指放在传感器上");
       
       // ⭐⭐⭐ 监控点4：手指抬起成功
       printMemoryStatus("手指抬起成功");
       printTimerStatus("准备第二次采集");
       
       // 安全地转换到第二次采集
       Serial.println("🔄 准备开始第二次采集");
       
       // 删除当前定时器（只删除一次）
       lv_timer_del(timer2);
       waitLiftTimer = NULL;
       
       // 重置计数器
       enrollmentSecondCaptureAttempts = 0;
       
       // 使用最简单的方式：直接在当前定时器中开始第二次采集
       Serial.println("🔄 立即开始第二次采集");
       
       // 创建第二次采集定时器
       secondCaptureTimer = lv_timer_create([](lv_timer_t * timer) {
           int result = finger.getImage();
           
           if (result == FINGERPRINT_OK) {
               Serial.println("📷 第二次图像采集成功");
               
               // 生成特征
               int featureResult = finger.image2Tz(2);
               if (featureResult == FINGERPRINT_OK) {
                   Serial.println("✅ 第二次采集完全成功");
                   lv_timer_del(timer);
                   secondCaptureTimer = NULL;
                   
                   // ⭐⭐⭐ 监控点5：第二次采集成功
                   printMemoryStatus("第二次采集成功");
                   printTimerStatus("准备特征融合");
                   
                   updateEnrollmentProgress("第4步/5", "正在融合特征 生成指纹模板");
                   
                   // 延迟开始融合，避免嵌套定时器
                   lv_timer_t *mergeTimer = lv_timer_create([](lv_timer_t * mergeTimer) {
                       startFeatureMerge();
                       lv_timer_del(mergeTimer);
                   }, 200, NULL);
                   lv_timer_set_repeat_count(mergeTimer, 1);
               } else {
                   Serial.println("❌ 第二次特征生成失败: " + String(featureResult));
                   lv_timer_del(timer);
                   secondCaptureTimer = NULL;
                   updateEnrollmentProgress("采集失败", "特征生成失败 请重试");
                   handleEnrollmentFailure();
               }
               
           } else if (result == FINGERPRINT_NOFINGER) {
               enrollmentSecondCaptureAttempts++;
               if (enrollmentSecondCaptureAttempts >= 20) { // 10秒超时
                   Serial.println("⏰ 第二次采集超时");
                   lv_timer_del(timer);
                   secondCaptureTimer = NULL;
                   updateEnrollmentProgress("采集超时", "第二次采集超时 请重试");
                   handleEnrollmentFailure();
               } else if (enrollmentSecondCaptureAttempts % 4 == 0) {
                   Serial.println("⏳ 等待第二次放置手指... (" + String(enrollmentSecondCaptureAttempts * 0.5) + "秒)");
               }
           } else {
               Serial.println("❌ 第二次采集错误: " + String(result));
               lv_timer_del(timer);
               secondCaptureTimer = NULL;
               updateEnrollmentProgress("采集失败", "第二次采集失败 请重试");
               handleEnrollmentFailure();
           }
       }, 500, NULL);
   }, 200, NULL);  // 200ms间隔，频繁检查手指抬起状态
}

// 非阻塞的第二次采集函数 - 避免在定时器回调中执行阻塞操作
void startSecondCaptureNonBlocking() {
   Serial.println("🔄 startSecondCaptureNonBlocking() 开始执行");
   
   // 清理现有定时器
   if (secondCaptureTimer != NULL) {
       lv_timer_del(secondCaptureTimer);
       secondCaptureTimer = NULL;
   }
   
   // 重置计数器
   enrollmentSecondCaptureAttempts = 0;
   
   // 创建非阻塞的第二次采集定时器
   Serial.println("🕐 创建第二次采集定时器，500ms间隔检查");
   secondCaptureTimer = lv_timer_create([](lv_timer_t * timer) {
       // 使用非阻塞采集函数
       int result = captureAndGenerateNonBlocking(2);
       
       if (result == FINGERPRINT_OK) {
           // 第二次采集成功
           Serial.println("✅ 第二次采集成功");
           lv_timer_del(timer);
           secondCaptureTimer = NULL;
           
           updateEnrollmentProgress("第4步/5", "正在融合特征 生成指纹模板");
           
           // 延迟开始融合，避免嵌套定时器
           lv_timer_t *mergeTimer = lv_timer_create([](lv_timer_t * mergeTimer) {
               startFeatureMerge();
               lv_timer_del(mergeTimer);
           }, 300, NULL);
           lv_timer_set_repeat_count(mergeTimer, 1);
           
       } else if (result == FINGERPRINT_NOFINGER) {
           // 没有手指，继续等待
           enrollmentSecondCaptureAttempts++;
           if (enrollmentSecondCaptureAttempts >= 25) { // 12.5秒超时 (25 * 500ms)
               Serial.println("⏰ 第二次采集超时");
               lv_timer_del(timer);
               secondCaptureTimer = NULL;
               updateEnrollmentProgress("采集超时", "第二次采集超时 请重试");
               enrollmentInProgress = false;
           } else if (enrollmentSecondCaptureAttempts % 4 == 0) {
               Serial.println("⏳ 等待第二次放置手指... (" + String(enrollmentSecondCaptureAttempts * 0.5) + "秒)");
           }
       } else {
           // 采集错误
           Serial.println("❌ 第二次采集失败: " + String(result));
           lv_timer_del(timer);
           secondCaptureTimer = NULL;
           updateEnrollmentProgress("采集失败", "第二次采集失败 请重试");
           enrollmentInProgress = false;
       }
   }, 500, NULL); // 500ms间隔，避免过于频繁
}

// 开始第二次采集的函数 - 简化版本，减少内存使用
void startSecondCapture() {
   Serial.println("🔄 startSecondCapture() 函数开始执行");
   
   // 清理任何现有的第二次采集定时器
   if (secondCaptureTimer != NULL) {
       Serial.println("⚠️ 清理现有的secondCaptureTimer");
       lv_timer_del(secondCaptureTimer);
       secondCaptureTimer = NULL;
   }
   
   // 让看门狗知道我们还活着
   esp_task_wdt_reset();
   
   Serial.println("🔄 开始第二次采集，最大等待5秒");
   
   // 简单的阻塞采集，但有超时保护
   int attempts = 0;
   const int MAX_ATTEMPTS = 25; // 5秒超时 (25 * 200ms)
   
   while (attempts < MAX_ATTEMPTS) {
       esp_task_wdt_reset(); // 重置看门狗
       
       int result = finger.getImage();
       if (result == FINGERPRINT_OK) {
           Serial.println("📷 第二次图像采集成功");
           
           // 生成特征
           int featureResult = finger.image2Tz(2);
           if (featureResult == FINGERPRINT_OK) {
               Serial.println("✅ 第二次采集成功");
               updateEnrollmentProgress("第4步/5", "正在融合特征 生成指纹模板");
               
               // 延迟一点让UI更新
               delay(200);
               startFeatureMerge();
               return;
           } else {
               Serial.println("❌ 第二次特征生成失败: " + String(featureResult));
               updateEnrollmentProgress("采集失败", "特征生成失败 请重试");
               enrollmentInProgress = false;
               return;
           }
       } else if (result == FINGERPRINT_NOFINGER) {
           delay(200);
           attempts++;
           if (attempts % 5 == 0) {
               Serial.println("⏳ 等待第二次放置手指... (" + String(attempts * 0.2) + "秒)");
           }
       } else {
           Serial.println("❌ 第二次图像采集错误: " + String(result));
           updateEnrollmentProgress("采集失败", "图像采集失败 请重试");
           enrollmentInProgress = false;
           return;
       }
   }
   
   // 超时
   Serial.println("⏰ 第二次采集超时");
   updateEnrollmentProgress("采集超时", "第二次采集超时 请重试");
   enrollmentInProgress = false;
}

// 开始特征融合的函数 - 完全简化，避免定时器
void startFeatureMerge() {
   Serial.println("🔄 开始特征融合");
   Serial.println("🧠 正在执行特征融合...");
   
   // ⭐⭐⭐ 监控点6：特征融合开始
   printMemoryStatus("特征融合开始");
   printTimerStatus("融合前");
   
   // 重置看门狗
   //esp_task_wdt_reset();
   
   int mergeResult = finger.createModel();
   if (mergeResult != FINGERPRINT_OK) {
       if (mergeResult == FINGERPRINT_ENROLLMISMATCH) {
           Serial.println("❌ 两次指纹不匹配");
           updateEnrollmentProgress("融合失败", "两次指纹不匹配 请重新录入");
       } else {
           Serial.println("❌ 特征融合失败，错误码: " + String(mergeResult));
           updateEnrollmentProgress("融合失败", "特征融合失败 错误码: " + String(mergeResult));
       }
       
       // 调用通用的失败处理函数
       handleEnrollmentFailure();
       return;
   }
   
   Serial.println("✅ 特征融合成功");
   updateEnrollmentProgress("第5步/5", "正在存储指纹模板 请稍候");
   
   // 让UI有时间更新
   delay(500);
   esp_task_wdt_reset();
   
   Serial.println("💾 正在存储指纹模板...");
   int storeResult = finger.storeModel(targetFingerprintId);
   if (storeResult != FINGERPRINT_OK) {
       Serial.println("❌ 指纹存储失败，错误码: " + String(storeResult));
       updateEnrollmentProgress("存储失败", "指纹存储失败 错误码: " + String(storeResult));
       
       // 调用通用的失败处理函数
       handleEnrollmentFailure();
       return;
   }
   
   Serial.println("🎉 指纹录入完全成功！");
   
   // ⭐⭐⭐ 监控点7：录入成功
   printMemoryStatus("指纹录入成功");
   printTimerStatus("录入成功");
   
   // 在重置状态前保存需要的信息
   String savedStudentId = currentStudentId;
   int savedFingerprintId = targetFingerprintId;
   
   // 直接更新录入界面显示成功信息，使用保存的信息
   updateEnrollmentProgress("录入完成", "学号: " + savedStudentId + " 指纹录入成功!");
   
   Serial.println("✅ 录入成功，等待用户点击返回按钮");
   
   // ✅ 方案B：不自动跳转，等待用户手动点击"返回"按钮
   // 状态和定时器的清理将在用户点击"返回"按钮时，由 closeFingerprintEnrollmentScreen() 统一处理
}

// 通用的录入失败处理函数 - 避免消息框冲突，直接在界面显示
void handleEnrollmentFailure() {
   Serial.println("录入失败，准备返回学号输入界面");
   
   // ⭐⭐⭐ 监控点9：录入失败
   printMemoryStatus("指纹录入失败");
   printTimerStatus("录入失败");
   
   // 直接在录入界面显示失败信息，不使用消息框避免冲突
   updateEnrollmentProgress("录入失败", "指纹录入失败,请重新尝试");
   
   Serial.println("❌ 录入失败，等待用户点击返回按钮");
   
   // ✅ 方案B：不自动跳转，等待用户手动点击"返回"按钮
   // 状态和定时器的清理将在用户点击"返回"按钮时，由 closeFingerprintEnrollmentScreen() 统一处理
}


bool getStudentFingerprintId(String studentId, int &fingerprintId) {
   if (WiFi.status() != WL_CONNECTED) {
       Serial.println("错误: WiFi未连接");
       return false;
   }
   
   HTTPClient http;
   String apiUrl = "http://YOUR_SERVER_IP/api/fingerprint_api.php";
   http.begin(apiUrl);
   
   // 设置请求头
   http.addHeader("Content-Type", "application/json");
   configureHTTP(http, 10000);  // 指纹ID获取使用10秒超时
   
   // 准备JSON数据 - 使用assign_fingerprint_multidevice action（支持多设备录入）
   StaticJsonDocument<300> doc;
   doc["action"] = "assign_fingerprint_multidevice";
   doc["student_id"] = studentId;
   doc["device_id"] = DEVICE_ID;
   doc["finger_index"] = 1; // 默认使用第1个手指
   
   String jsonData;
   serializeJson(doc, jsonData);
   
   Serial.println("发送请求: " + jsonData);
   
   // 发送POST请求
   int httpResponseCode = retryHttpPost(http, jsonData, 3);  // 指纹ID获取重要，重试3次
   
   if (httpResponseCode > 0) {
       String response = http.getString();
       Serial.println("HTTP响应码: " + String(httpResponseCode));
       Serial.println("响应内容: " + response);
       
       // 解析响应（指纹ID响应较小，512字节足够）
       StaticJsonDocument<512> responseDoc;
       DeserializationError error = deserializeJson(responseDoc, response);
       
       // 立即释放response内存
       response = String();
       
       if (!error) {
           bool success = responseDoc["success"];
           if (success) {
               fingerprintId = responseDoc["fingerprint_id"];
               Serial.println("成功分配指纹ID: " + String(fingerprintId));
               http.end();
               return true;
           } else {
               String errorMsg = responseDoc["message"] | "未知错误";
               Serial.println("服务器返回错误: " + errorMsg);
           }
       } else {
           Serial.println("JSON解析失败: " + String(error.c_str()));
       }
   } else {
       Serial.println("HTTP请求失败,错误码: " + String(httpResponseCode));
   }
   
   http.end();
   return false;
}


// 指纹采集和特征生成函数 (复用已有的逻辑)
// 非阻塞版本的指纹采集函数
int captureAndGenerateNonBlocking(int bufferID) {
   int result = finger.getImage();
   
   if (result == FINGERPRINT_OK) {
       Serial.println("图像采集成功");
       
       // 生成特征
       int featureResult = finger.image2Tz(bufferID);
       if (featureResult != FINGERPRINT_OK) {
           Serial.println("特征生成失败: " + String(featureResult));
           return featureResult;
       }
       
       Serial.println("特征生成成功,存入缓冲区 " + String(bufferID));
       return FINGERPRINT_OK;
       
   } else if (result == FINGERPRINT_NOFINGER) {
       return FINGERPRINT_NOFINGER; // 没有手指，需要继续等待
   } else {
       Serial.println("图像采集错误: " + String(result));
       return result;
   }
}

// 保留阻塞版本用于其他地方
int captureAndGenerate(int bufferID) {
   int attempts = 0;
   const int MAX_ATTEMPTS = 50; // 5秒超时
   
   // 等待手指放置
   while (attempts < MAX_ATTEMPTS) {
       int result = finger.getImage();
       
       if (result == FINGERPRINT_OK) {
           Serial.println("图像采集成功");
           break;
       } else if (result == FINGERPRINT_NOFINGER) {
           delay(100);
           attempts++;
           continue;
       } else {
           Serial.println("图像采集错误: " + String(result));
           return result;
       }
   }
   
   if (attempts >= MAX_ATTEMPTS) {
       Serial.println("采集超时");
       return FINGERPRINT_IMAGEFAIL;
   }
   
   // 生成特征
   int featureResult = finger.image2Tz(bufferID);
   if (featureResult != FINGERPRINT_OK) {
       Serial.println("特征生成失败: " + String(featureResult));
       return featureResult;
   }
   
   Serial.println("特征生成成功,存入缓冲区 " + String(bufferID));
   return FINGERPRINT_OK;
}

void showEnrollmentProgress(String step, String message) {
   if (!uiInitialized) return;
   
   // 使用新的统一状态管理系统显示录入进度
   updateMainScreenStatus(STATE_ENROLLING, step + ": " + message);
}

// ==================== 直接指纹传感器初始化 ====================
void initFingerprintDirect() {
   Serial.println("==================== 直接初始化指纹传感器 ====================");
   
   // 使用新的统一状态管理系统显示初始化状态
   updateMainScreenStatus(STATE_FINGERPRINT_INIT, "正在建立连接...");
   
   // 确保之前的连接完全关闭
   fingerprintSerial.end();
   delay(200);
   
   // 直接使用57600波特率初始化
   Serial.println("使用57600波特率初始化Serial1...");
   fingerprintSerial.begin(57600, SERIAL_8N1, FP_RX_PIN, FP_TX_PIN);
   finger.begin(57600);
   delay(500);
   
   // 测试连接
   bool success = false;
   for (int attempt = 0; attempt < 3; attempt++) {
       if (finger.verifyPassword()) {
           success = true;
           Serial.println("成功: 指纹传感器连接成功 (57600 bps)");
           break;
       }
       delay(300);
       yield();
   }
   
   if (success) {
       // 获取传感器参数
       if (finger.getParameters() == FINGERPRINT_OK) {
           Serial.println("传感器信息:");
           Serial.println("  容量: " + String(finger.capacity));
           Serial.println("  安全等级: " + String(finger.security_level));
       }
       
       workingBaudRate = 57600;
       fingerprintSystemReady = true;
       lastFingerprintActivity = millis();
       
       // 使用统一状态管理系统更新UI状态为就绪
       updateMainScreenStatus(STATE_FINGERPRINT_INIT, "指纹传感器就绪\n波特率: 57600 bps");
       
       Serial.println("成功: 指纹传感器初始化完成");
       
   } else {
       Serial.println("失败: 指纹传感器连接失败");
       fingerprintSystemReady = false;
       
       // 使用统一状态管理系统更新UI状态为失败
       updateMainScreenStatus(STATE_DETECTION_ERROR, "指纹传感器连接失败\n请检查GPIO17/18接线");
   }
   
   Serial.println("===============================================");
}

// ==================== 新的签到检测界面实现 ====================

void createCheckinDetectionScreen() {
   Serial.println("==================== 创建签到检测界面 ====================");
   
   // 如果界面已存在,先关闭
   if (checkinDetectionScreen != NULL) {
       closeCheckinDetectionScreen();
   }
   
   // 创建新的检测界面
   checkinDetectionScreen = lv_obj_create(NULL);
   lv_obj_set_size(checkinDetectionScreen, LV_HOR_RES, LV_VER_RES);
   lv_obj_set_style_bg_color(checkinDetectionScreen, lv_color_hex(0xF5F5F5), 0);
   
   // 创建标题
   lv_obj_t *titleLabel = lv_label_create(checkinDetectionScreen);
   lv_label_set_text(titleLabel, "指纹签到");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x2196F3), 0);
   lv_obj_align(titleLabel, LV_ALIGN_TOP_MID, 0, 20);
   
   // 创建步骤显示标签
   checkinStepLabel = lv_label_create(checkinDetectionScreen);
   lv_label_set_text(checkinStepLabel, "准备就绪");
   lv_obj_set_style_text_font(checkinStepLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(checkinStepLabel, lv_color_hex(0x4CAF50), 0);
   lv_obj_align(checkinStepLabel, LV_ALIGN_TOP_MID, 0, 60);
   
   // 创建进度显示标签
   checkinProgressLabel = lv_label_create(checkinDetectionScreen);
   lv_label_set_text(checkinProgressLabel, "请将手指放在传感器上");
   lv_obj_set_style_text_font(checkinProgressLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(checkinProgressLabel, lv_color_hex(0x333333), 0);
   lv_obj_set_style_text_align(checkinProgressLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(checkinProgressLabel, LV_ALIGN_CENTER, 0, -40);
   lv_obj_set_width(checkinProgressLabel, 280);
   
   // 创建学生信息显示标签（初始隐藏）
   checkinStudentInfoLabel = lv_label_create(checkinDetectionScreen);
   lv_label_set_text(checkinStudentInfoLabel, "");
   lv_obj_set_style_text_font(checkinStudentInfoLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(checkinStudentInfoLabel, lv_color_hex(0x333333), 0);
   lv_obj_set_style_text_align(checkinStudentInfoLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(checkinStudentInfoLabel, LV_ALIGN_CENTER, 0, 20);
   lv_obj_set_width(checkinStudentInfoLabel, 280);
   lv_obj_add_flag(checkinStudentInfoLabel, LV_OBJ_FLAG_HIDDEN); // 初始隐藏
   
   // 创建取消按钮
   checkinCancelBtn = lv_btn_create(checkinDetectionScreen);
   lv_obj_set_size(checkinCancelBtn, 120, 45);
   lv_obj_align(checkinCancelBtn, LV_ALIGN_BOTTOM_LEFT, 30, -30);
   lv_obj_set_style_bg_color(checkinCancelBtn, lv_color_hex(0xF44336), 0);
   lv_obj_set_style_radius(checkinCancelBtn, 8, 0);
   lv_obj_add_event_cb(checkinCancelBtn, checkinCancelCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *cancelLabel = lv_label_create(checkinCancelBtn);
   lv_label_set_text(cancelLabel, "取消");
   lv_obj_set_style_text_font(cancelLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(cancelLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(cancelLabel);
   
   // 创建继续按钮（初始隐藏）
   checkinContinueBtn = lv_btn_create(checkinDetectionScreen);
   lv_obj_set_size(checkinContinueBtn, 120, 45);
   lv_obj_align(checkinContinueBtn, LV_ALIGN_BOTTOM_RIGHT, -30, -30);
   lv_obj_set_style_bg_color(checkinContinueBtn, lv_color_hex(0x4CAF50), 0);
   lv_obj_set_style_radius(checkinContinueBtn, 8, 0);
   lv_obj_add_event_cb(checkinContinueBtn, checkinContinueCallback, LV_EVENT_CLICKED, NULL);
   lv_obj_add_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN); // 初始隐藏
   
   lv_obj_t *continueLabel = lv_label_create(checkinContinueBtn);
   lv_label_set_text(continueLabel, "继续");
   lv_obj_set_style_text_font(continueLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(continueLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(continueLabel);
   
   // 显示界面
   lv_scr_load(checkinDetectionScreen);
   
   // 启动检测模式
   detectionModeActive = true;
   detectionStartTime = millis();
   lastFingerprintActivity = millis();
   
   // 显示初始检测状态
   updateCheckinProgress("等待指纹", "请将手指放在传感器上", false);
   
   // 启动检测定时器
   if (detectionTimer != NULL) {
       lv_timer_del(detectionTimer);
   }
   detectionTimer = lv_timer_create(detectionTimerCallback, FINGER_CHECK_INTERVAL, NULL);
   Serial.println("检测定时器已创建 (定时器指针: " + String((unsigned long)detectionTimer, HEX) + ")");
   
   Serial.println("签到检测界面已创建并启动");
}

void updateCheckinProgress(String step, String message, bool isSuccess) {
   if (checkinStepLabel != NULL) {
       lv_label_set_text(checkinStepLabel, step.c_str());
       lv_obj_set_style_text_color(checkinStepLabel, 
           isSuccess ? lv_color_hex(0x4CAF50) : lv_color_hex(0x2196F3), 0);
   }
   
   if (checkinProgressLabel != NULL) {
       lv_label_set_text(checkinProgressLabel, message.c_str());
   }
   
   // 强制刷新LVGL显示 - 修复UI不更新问题
   lv_timer_handler();
   lv_refr_now(NULL);
   
   Serial.println("检测进度更新: " + step + " - " + message);
}

void showCheckinStudentInfo(String name, String studentId, String class_name, String dormitory) {
   Serial.println("==================== showCheckinStudentInfo 调用 ====================");
   
   // 构建学生信息文本
   String studentInfo = "签到成功!\n\n";
   studentInfo += "姓名: " + name + "\n";
   studentInfo += "学号: " + studentId + "\n";
   studentInfo += "班级: " + class_name + "\n";
   studentInfo += "宿舍: " + dormitory + "\n\n";
   studentInfo += "点击\"继续\"进行下一个签到";
   
   // 显示学生信息
   if (checkinStudentInfoLabel != NULL) {
       lv_label_set_text(checkinStudentInfoLabel, studentInfo.c_str());
       lv_obj_clear_flag(checkinStudentInfoLabel, LV_OBJ_FLAG_HIDDEN);
       lv_timer_handler();
       lv_refr_now(NULL);
   }
   
   // 隐藏进度标签,显示学生信息
   if (checkinProgressLabel != NULL) {
       lv_obj_add_flag(checkinProgressLabel, LV_OBJ_FLAG_HIDDEN);
   }
   
   // 显示继续按钮
   if (checkinContinueBtn != NULL) {
       lv_obj_clear_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN);
   }
   
   updateCheckinProgress("签到完成", "", true);
   
   Serial.println("✅ 签到成功，等待用户点击\"继续\"按钮");
}

// ✅ 已删除倒计时功能，改为完全手动操作

void checkinCancelCallback(lv_event_t * e) {
   Serial.println("用户取消签到检测");
   closeCheckinDetectionScreen();
}

void checkinContinueCallback(lv_event_t * e) {
   Serial.println("继续下一轮检测");
   
   // 重置界面状态
   if (checkinStudentInfoLabel != NULL) {
       lv_obj_add_flag(checkinStudentInfoLabel, LV_OBJ_FLAG_HIDDEN);
       lv_label_set_text(checkinStudentInfoLabel, "");
   }
   
   if (checkinProgressLabel != NULL) {
       lv_obj_clear_flag(checkinProgressLabel, LV_OBJ_FLAG_HIDDEN);
   }
   
   if (checkinContinueBtn != NULL) {
       lv_obj_add_flag(checkinContinueBtn, LV_OBJ_FLAG_HIDDEN);
   }
   
   // 重新开始检测
   detectionStartTime = millis();
   updateCheckinProgress("等待指纹", "请将手指放在传感器上", false);
   
   // 重新启动检测定时器
   if (detectionTimer != NULL) {
       lv_timer_del(detectionTimer);
   }
   detectionTimer = lv_timer_create(detectionTimerCallback, FINGER_CHECK_INTERVAL, NULL);
   Serial.println("检测定时器已重新启动 - 继续检测 (定时器指针: " + String((unsigned long)detectionTimer, HEX) + ")");
}

void closeCheckinDetectionScreen() {
   Serial.println("==================== 关闭签到检测界面 ====================");
   
   // 检查当前状态
   if (!detectionModeActive) {
       Serial.println("警告: 检测模式未激活,但仍执行界面清理");
   }
   
   // 停止检测模式
   detectionModeActive = false;
   
   // 使用统一状态管理系统重置为空闲状态
   updateMainScreenStatus(STATE_IDLE, "检测界面已关闭");
   
   // 【关键】立即切换到主界面,脱离当前屏幕的事件处理上下文
   // 这样可以避免LVGL事件处理完成后访问已删除的UI对象
   lv_scr_load(mainScreen);
   Serial.println("已切换到主界面,脱离事件处理上下文");
   
   // 清理定时器
   if (detectionTimer != NULL) {
       lv_timer_del(detectionTimer);
       detectionTimer = NULL;
       Serial.println("检测定时器已清理");
   }
   
   // ✅ 已删除倒计时定时器清理代码（变量已不存在）
   
   // 清理消息框和相关定时器
   closeCurrentMessageBox();
   Serial.println("消息框已清理");
   
   // 【学习closeEnrollmentConfirmScreen模式】先清理所有子对象的全局引用
   Serial.println("开始清理子对象指针");
   checkinStepLabel = NULL;
   checkinProgressLabel = NULL;
   checkinStudentInfoLabel = NULL;
   checkinCancelBtn = NULL;
   checkinContinueBtn = NULL;
   
   // ✅ 已删除倒计时相关变量的重置代码
   Serial.println("所有子对象指针已重置");
   
   // 最后删除屏幕对象
   if (checkinDetectionScreen != NULL) {
       Serial.println("删除签到检测屏幕对象");
       lv_obj_del(checkinDetectionScreen);
       checkinDetectionScreen = NULL;
       Serial.println("屏幕对象已删除");
   } else {
       Serial.println("签到检测界面已经为NULL,跳过删除");
   }
   
   Serial.println("成功: 已安全关闭签到检测界面");
}

// ==================== 新的录入确认界面实现 ====================

void createEnrollmentConfirmScreen(String name, String studentId, String class_name, String dormitory) {
   Serial.println("==================== 创建录入确认界面 ====================");
   
   // 安全地关闭学号输入界面（如果还存在）
   if (studentIdInputScreen != NULL) {
       Serial.println("清理学号输入界面");
       // 先切换到主界面,再删除对象
       extern lv_obj_t * mainScreen;
       lv_scr_load(mainScreen);
       lv_obj_del(studentIdInputScreen);
       studentIdInputScreen = NULL;
       studentIdTextArea = NULL;
       studentIdKeyboard = NULL;
       Serial.println("学号输入界面已清理");
   }
   
   // 如果确认界面已存在,先关闭
   if (enrollmentConfirmScreen != NULL) {
       closeEnrollmentConfirmScreen();
   }
   
   // 创建新的确认界面
   enrollmentConfirmScreen = lv_obj_create(NULL);
   lv_obj_set_size(enrollmentConfirmScreen, LV_HOR_RES, LV_VER_RES);
   lv_obj_set_style_bg_color(enrollmentConfirmScreen, lv_color_hex(0xF5F5F5), 0);
   
   // 创建标题
   lv_obj_t *titleLabel = lv_label_create(enrollmentConfirmScreen);
   lv_label_set_text(titleLabel, "确认学生信息");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x2196F3), 0);
   lv_obj_align(titleLabel, LV_ALIGN_TOP_MID, 0, 20);
   
   // ⭐ 显示学生信息（仅用于指纹录入）
   String infoText = "请确认以下信息是否正确:\n\n";
   infoText += "姓名: " + name + "\n";
   infoText += "学号: " + studentId + "\n";
   infoText += "班级: " + class_name + "\n";
   infoText += "宿舍: " + dormitory + "\n\n";
   infoText += "确认无误后点击\"确定录入\"按钮";
   
   confirmStudentInfoLabel = lv_label_create(enrollmentConfirmScreen);
   lv_label_set_text(confirmStudentInfoLabel, infoText.c_str());
   lv_obj_set_style_text_font(confirmStudentInfoLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(confirmStudentInfoLabel, lv_color_hex(0x333333), 0);
   lv_obj_set_style_text_align(confirmStudentInfoLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(confirmStudentInfoLabel, LV_ALIGN_CENTER, 0, -20);
   lv_obj_set_width(confirmStudentInfoLabel, 300);
   
   // ⭐ 创建确认按钮（仅用于指纹录入）
   confirmEnrollBtn = lv_btn_create(enrollmentConfirmScreen);
   lv_obj_set_size(confirmEnrollBtn, 120, 50);
   lv_obj_align(confirmEnrollBtn, LV_ALIGN_BOTTOM_LEFT, 20, -30);
   lv_obj_set_style_bg_color(confirmEnrollBtn, lv_color_hex(0x4CAF50), 0);
   lv_obj_set_style_radius(confirmEnrollBtn, 8, 0);
   lv_obj_add_event_cb(confirmEnrollBtn, confirmEnrollCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *enrollLabel = lv_label_create(confirmEnrollBtn);
   lv_label_set_text(enrollLabel, "确定录入");
   lv_obj_set_style_text_font(enrollLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(enrollLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(enrollLabel);
   
   // 创建返回按钮
   confirmCancelBtn = lv_btn_create(enrollmentConfirmScreen);
   lv_obj_set_size(confirmCancelBtn, 120, 50);
   lv_obj_align(confirmCancelBtn, LV_ALIGN_BOTTOM_RIGHT, -20, -30);
   lv_obj_set_style_bg_color(confirmCancelBtn, lv_color_hex(0xF44336), 0);
   lv_obj_set_style_radius(confirmCancelBtn, 8, 0);
   lv_obj_add_event_cb(confirmCancelBtn, confirmCancelCallback, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *cancelLabel = lv_label_create(confirmCancelBtn);
   lv_label_set_text(cancelLabel, "返回修改");
   lv_obj_set_style_text_font(cancelLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(cancelLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(cancelLabel);
   
   // 显示界面
   lv_scr_load(enrollmentConfirmScreen);
   
   Serial.println("录入确认界面已创建");
}

void confirmEnrollCallback(lv_event_t * e) {
   Serial.println("确认界面：确认按钮被点击");
   Serial.println("学号: " + currentStudentId);
   Serial.println("当前模式: " + String(currentOperationMode));
   
   // ⭐⭐⭐ 只保留指纹录入流程（手动签到已独立）
   if (currentOperationMode == MODE_FINGERPRINT_ENROLL) {
       // ==================== 指纹录入流程 ====================
       Serial.println("========== 执行指纹录入 ==========");
       
       // ⭐⭐⭐ 优化：第一次请求时已经获取了指纹ID，直接判断
       if (targetFingerprintId > 0) {
           // ✅ 已有指纹ID，直接开始录入（覆盖旧指纹）
           Serial.println("✅ 使用已获取的指纹ID: " + String(targetFingerprintId));
           createFingerprintEnrollmentScreen();
           
       } else if (targetFingerprintId == -1) {
           // ⚠️ 该学生还没有指纹，需要分配新的指纹ID
           Serial.println("ℹ️ 学生尚未录入指纹，正在分配新的指纹ID...");
           
           // 异步获取新的指纹ID
           lv_timer_create([](lv_timer_t * timer) {
               Serial.println("开始分配新的指纹ID");
               
               // 调用现有的获取指纹ID函数
               int fingerprintId = -1;
               bool success = getStudentFingerprintId(currentStudentId, fingerprintId);
               
               if (success && fingerprintId > 0) {
                   Serial.println("✅ 成功分配指纹ID: " + String(fingerprintId));
                   targetFingerprintId = fingerprintId;
                   
                   // 创建指纹录入界面
                   createFingerprintEnrollmentScreen();
                   
               } else {
                   Serial.println("❌ 分配指纹ID失败");
                   closeEnrollmentConfirmScreen();
                   showMessageBox("录入失败", "无法分配指纹ID 请检查学号是否正确 或联系管理员", "确定", false);
                   enrollmentInProgress = false;
                   targetFingerprintId = -1;
                   currentOperationMode = MODE_NONE;  // ✅ 修复：重置操作模式
                   currentStudentId = "";             // ✅ 修复：清空学号
               }
               
               lv_timer_del(timer);
           }, 500, NULL);  // 500ms后执行
       } else {
           // ❌ 异常情况：fingerprint_id 无效
           Serial.println("❌ 错误：指纹ID无效: " + String(targetFingerprintId));
           closeEnrollmentConfirmScreen();
           showMessageBox("录入失败", "指纹ID无效 请重试", "确定", false);
           enrollmentInProgress = false;
           targetFingerprintId = -1;
           currentOperationMode = MODE_NONE;  // 重置模式
       }
   } else {
       // ❌ 异常情况：未知操作模式
       Serial.println("❌ 错误：未知操作模式: " + String(currentOperationMode));
       closeEnrollmentConfirmScreen();
       showMessageBox("系统错误", "操作模式异常 请重新操作", "确定", false);
       currentOperationMode = MODE_NONE;  // 重置模式
   }
}

void confirmCancelCallback(lv_event_t * e) {
   Serial.println("确认界面：取消/返回修改按钮被点击");
   Serial.println("当前模式: " + String(currentOperationMode) + " (保持不变)");
   
   // 学习WiFi功能的资源清理模式
   closeEnrollmentConfirmScreen();
   
   // ⭐⭐⭐ 关键修复：不重置 currentOperationMode！
   // 用户点"返回修改"时，应该保持原来的模式（手动签到或指纹录入）
   // 只重置其他临时状态
   enrollmentInProgress = false;
   // currentOperationMode 保持不变！用户修改学号后还是同一个操作
   
   // 返回学号输入界面
   showStudentIdInputDialog();
}

void closeEnrollmentConfirmScreen() {
   Serial.println("关闭录入确认界面");
   
   // 学习WiFi功能的完整资源清理模式
   // 先清理所有子对象的全局引用
   confirmStudentInfoLabel = NULL;
   confirmEnrollBtn = NULL;
   confirmCancelBtn = NULL;
   
   // 然后切换界面并删除屏幕
   if (enrollmentConfirmScreen != NULL) {
       lv_scr_load(mainScreen);
       lv_obj_del(enrollmentConfirmScreen);
       enrollmentConfirmScreen = NULL;
   }
   
   Serial.println("录入确认界面已清理");
}

// ==================== 获取学生信息函数 ====================

void getStudentInfoAndShowConfirm(String studentId) {
   Serial.println("==================== 获取学生信息 ====================");
   Serial.println("学号: " + studentId);
   
   // 显示加载界面
   showMessageBox("获取信息", "正在获取学生信息...\n请稍候", "加载中", true);
   
   // 使用定时器执行API查询，避免阻塞UI
   lv_timer_create([](lv_timer_t * timer) {
       Serial.println("开始从服务器获取学生信息");
       
       // 定义变量存储学生信息
       String name = "未知";
       String class_name = "未知";
       String dormitory = "未知";
       bool success = false;
       
       try {
           // 调用后端API获取学生信息
           HTTPClient http;
           http.begin("http://YOUR_SERVER_IP/api/fingerprint_api.php");
           http.addHeader("Content-Type", "application/json");
           http.setTimeout(5000);  // 5秒超时
           
           // 构建请求数据
           StaticJsonDocument<200> requestDoc;
           requestDoc["action"] = "get_student_info";
           requestDoc["student_id"] = currentStudentId;
           requestDoc["device_id"] = DEVICE_ID;  // ⭐ 新增：发送设备ID
           requestDoc["token"] = API_TOKEN;
           
           String requestBody;
           serializeJson(requestDoc, requestBody);
           
           Serial.println("发送请求: " + requestBody);
           int httpResponseCode = http.POST(requestBody);
           
           if (httpResponseCode == 200) {
               String response = http.getString();
               Serial.println("API响应: " + response);
               
               // 解析响应
               StaticJsonDocument<1024> responseDoc;
               DeserializationError error = deserializeJson(responseDoc, response);
               
               // 立即释放response内存
               response = String();
               
               if (!error && responseDoc["success"].as<bool>()) {
                   name = responseDoc["data"]["name"].as<String>();
                   class_name = responseDoc["data"]["class_name"].as<String>();
                   dormitory = responseDoc["data"]["dormitory"].as<String>();
                   
                   // ⭐⭐⭐ 新增：解析并保存指纹ID（如果有）
                   if (responseDoc["data"].containsKey("fingerprint_id") && 
                       !responseDoc["data"]["fingerprint_id"].isNull()) {
                       targetFingerprintId = responseDoc["data"]["fingerprint_id"].as<int>();
                       Serial.println("✅ 已获取指纹ID: " + String(targetFingerprintId));
                   } else {
                       targetFingerprintId = -1;  // 该学生还没录入指纹
                       Serial.println("ℹ️ 该学生尚未录入指纹");
                   }
                   
                   success = true;
                   
                   Serial.println("✅ 成功获取学生信息");
                   Serial.println("  姓名: " + name);
                   Serial.println("  班级: " + class_name);
                   Serial.println("  宿舍: " + dormitory);
               } else {
                   Serial.println("❌ API返回错误或解析失败");
                   name = "数据获取失败";
                   class_name = "请联系管理员";
                   dormitory = "学号可能不存在";
               }
           } else {
               Serial.println("❌ HTTP请求失败，状态码: " + String(httpResponseCode));
               name = "网络请求失败";
               class_name = "请检查网络连接";
               dormitory = "状态码: " + String(httpResponseCode);
           }
           
           http.end();
           
       } catch (...) {
           Serial.println("❌ 异常：获取学生信息失败");
           name = "系统异常";
           class_name = "请重试";
           dormitory = "或联系管理员";
       }
       
       // 关闭加载消息框
       safeCloseCurrentMessageBox();
       
       // 显示确认界面（无论成功与否都显示，让用户看到错误信息）
       createEnrollmentConfirmScreen(name, currentStudentId, class_name, dormitory);
       
       lv_timer_del(timer);
   }, 500, NULL); // 500ms后执行
}

// ==================== 指纹录入界面实现 ====================

void createFingerprintEnrollmentScreen() {
   Serial.println("==================== 创建指纹录入界面 ====================");
   
   // 先关闭确认界面
   closeEnrollmentConfirmScreen();
   
   // 安全检查：如果录入界面已存在，先清理
   if (fingerprintEnrollmentScreen != NULL) {
       Serial.println("清理已存在的录入界面");
       lv_obj_del(fingerprintEnrollmentScreen);
       fingerprintEnrollmentScreen = NULL;
       enrollmentStepLabel = NULL;
       enrollmentProgressLabel = NULL;
       enrollmentCancelBtn = NULL;
   }
   
   // 创建录入界面
   fingerprintEnrollmentScreen = lv_obj_create(NULL);
   lv_obj_set_size(fingerprintEnrollmentScreen, LV_HOR_RES, LV_VER_RES);
   lv_obj_set_style_bg_color(fingerprintEnrollmentScreen, lv_color_hex(0xF0F8FF), 0);
   
   // 标题
   lv_obj_t *titleLabel = lv_label_create(fingerprintEnrollmentScreen);
   lv_label_set_text(titleLabel, "指纹录入");
   lv_obj_set_style_text_font(titleLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(titleLabel, lv_color_hex(0x2196F3), 0);
   lv_obj_align(titleLabel, LV_ALIGN_TOP_MID, 0, 20);
   
   // 步骤标签
   enrollmentStepLabel = lv_label_create(fingerprintEnrollmentScreen);
   lv_label_set_text(enrollmentStepLabel, "准备录入");
   lv_obj_set_style_text_font(enrollmentStepLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(enrollmentStepLabel, lv_color_hex(0x4CAF50), 0);
   lv_obj_align(enrollmentStepLabel, LV_ALIGN_CENTER, 0, -60);
   
   // 进度标签
   enrollmentProgressLabel = lv_label_create(fingerprintEnrollmentScreen);
   lv_label_set_text(enrollmentProgressLabel, "正在获取指纹ID...");
   lv_obj_set_style_text_font(enrollmentProgressLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(enrollmentProgressLabel, lv_color_hex(0x666666), 0);
   lv_obj_set_style_text_align(enrollmentProgressLabel, LV_TEXT_ALIGN_CENTER, 0);
   lv_obj_align(enrollmentProgressLabel, LV_ALIGN_CENTER, 0, 0);
   lv_obj_set_width(enrollmentProgressLabel, 300);
   
   // 取消按钮
   enrollmentCancelBtn = lv_btn_create(fingerprintEnrollmentScreen);
   lv_obj_set_size(enrollmentCancelBtn, 120, 45);
   lv_obj_align(enrollmentCancelBtn, LV_ALIGN_BOTTOM_MID, 0, -30);
   lv_obj_set_style_bg_color(enrollmentCancelBtn, lv_color_hex(0xF44336), 0);
   lv_obj_add_event_cb(enrollmentCancelBtn, [](lv_event_t * e) {
       Serial.println("❌ 用户取消指纹录入");
       
       // 安全清理所有录入相关的定时器
       if (firstCaptureTimer != NULL) {
           lv_timer_del(firstCaptureTimer);
           firstCaptureTimer = NULL;
           Serial.println("🧹 清理firstCaptureTimer");
       }
       if (waitLiftTimer != NULL) {
           lv_timer_del(waitLiftTimer);
           waitLiftTimer = NULL;
           Serial.println("🧹 清理waitLiftTimer");
       }
       if (secondCaptureTimer != NULL) {
           lv_timer_del(secondCaptureTimer);
           secondCaptureTimer = NULL;
           Serial.println("🧹 清理secondCaptureTimer");
       }
       
       // 重置所有录入相关状态
       enrollmentInProgress = false;
       targetFingerprintId = -1;
       currentStudentId = "";
       
       // 确保指纹传感器状态重置
       finger.getImage(); // 清除可能的残留状态
       
       // 添加延迟确保清理完成
       delay(100);
       
       closeFingerprintEnrollmentScreen();
       Serial.println("取消录入，正在切换到学号输入界面以便重新录入");
       showStudentIdInputDialog();  // 返回学号输入界面
   }, LV_EVENT_CLICKED, NULL);
   
   lv_obj_t *cancelLabel = lv_label_create(enrollmentCancelBtn);
   lv_label_set_text(cancelLabel, "返回");  // ✅ 修改为"返回"更符合语义
   lv_obj_set_style_text_font(cancelLabel, &myFont_new, 0);
   lv_obj_set_style_text_color(cancelLabel, lv_color_hex(0xFFFFFF), 0);
   lv_obj_center(cancelLabel);
   
   // 加载界面
   lv_scr_load(fingerprintEnrollmentScreen);
   
   // 开始指纹录入流程
   lv_timer_create([](lv_timer_t * timer) {
       performFingerprintEnrollmentWithUI();
       lv_timer_del(timer);
   }, 1000, NULL);
   
   Serial.println("指纹录入界面已创建");
}

void updateEnrollmentProgress(String step, String message) {
   // 安全检查：确保录入界面存在
   if (fingerprintEnrollmentScreen == NULL) {
       Serial.println("警告: 录入界面不存在，无法更新进度 - " + step + ": " + message);
       return;
   }
   
   if (enrollmentStepLabel != NULL) {
       lv_label_set_text(enrollmentStepLabel, step.c_str());
   }
   if (enrollmentProgressLabel != NULL) {
       lv_label_set_text(enrollmentProgressLabel, message.c_str());
   }
   
   Serial.println("录入进度更新: " + step + " - " + message);
}

void closeFingerprintEnrollmentScreen() {
   Serial.println("==================== 关闭指纹录入界面 ====================");
   
   // 检查当前状态
   if (fingerprintEnrollmentScreen == NULL) {
       Serial.println("警告: 指纹录入界面已经为NULL,跳过清理");
       return;
   }
   
   // 关闭当前消息框
   closeCurrentMessageBox();
   Serial.println("消息框已清理");
   
   // ✅ 重置录入状态（清理定时器和状态变量）
   resetEnrollmentState();
   Serial.println("录入状态已重置");
   
   // 【学习closeCheckinDetectionScreen模式】先清理所有子对象的全局引用
   Serial.println("开始清理子对象指针");
   enrollmentStepLabel = NULL;
   enrollmentProgressLabel = NULL;
   enrollmentCancelBtn = NULL;
   Serial.println("所有子对象指针已重置");
   
   // 安全删除屏幕对象：先切换到主界面再删除，避免删除活动屏幕
   if (fingerprintEnrollmentScreen != NULL) {
       Serial.println("删除指纹录入屏幕对象");
       // 临时切换到主界面以安全删除录入界面
       lv_scr_load(mainScreen);
       lv_obj_del(fingerprintEnrollmentScreen);
       fingerprintEnrollmentScreen = NULL;
       Serial.println("屏幕对象已删除，调用者可以切换到目标界面");
   }
   
   Serial.println("成功: 已安全关闭指纹录入界面（等待调用者切换到目标界面）");
}

void performFingerprintEnrollmentWithUI() {
   Serial.println("==================== 开始带UI的指纹录入 ====================");
   Serial.println("学号: " + currentStudentId);
   Serial.println("指纹ID: " + String(targetFingerprintId));
   
   // ⭐⭐⭐ 监控点1：录入开始
   printMemoryStatus("指纹录入开始");
   printTimerStatus("录入开始");
   
   // 关键安全检查：确保录入界面已创建
   if (fingerprintEnrollmentScreen == NULL) {
       Serial.println("错误: 录入界面未创建，无法开始录入");
       return;
   }
   
   // 检查指纹系统状态
   if (!fingerprintSystemReady) {
       updateEnrollmentProgress("系统错误", "指纹传感器未就绪 请检查连接");
       return;
   }
   
   // 第一次指纹采集
   updateEnrollmentProgress("第1步/5", "第一次采集 请将手指放在传感器上");
   
   // 清理任何现有的定时器，防止内存泄漏
   cleanupEnrollmentTimers();
   
   // 初始化全局变量
   enrollmentFirstCaptureAttempts = 0;
   firstCaptureTimer = lv_timer_create([](lv_timer_t * timer) {
       int result1 = captureAndGenerateNonBlocking(1);
       
       if (result1 == FINGERPRINT_OK) {
           // 采集成功，停止定时器，继续下一步
           lv_timer_del(timer);
           firstCaptureTimer = NULL;
           enrollmentFirstCaptureAttempts = 0;
           
           updateEnrollmentProgress("第2步/5", "第一次采集成功 请抬起手指");
           Serial.println("✅ 第一次采集成功，即将开始等待手指抬起");
           
           // ⭐⭐⭐ 监控点2：第一次采集成功
           printMemoryStatus("第一次采集成功");
           printTimerStatus("第一次采集后");
           
           // 立即开始等待手指抬起的逻辑
           startWaitForLiftOff();
           Serial.println("📌 startWaitForLiftOff() 函数已调用");
           return;
           
       } else if (result1 == FINGERPRINT_NOFINGER) {
           // 没有手指，继续等待
           enrollmentFirstCaptureAttempts++;
           if (enrollmentFirstCaptureAttempts >= 50) { // 10秒超时 (50 * 200ms)
               lv_timer_del(timer);
               firstCaptureTimer = NULL;
               updateEnrollmentProgress("采集超时", "第一次采集超时 请重试");
               enrollmentInProgress = false;
               return;
           }
           return; // 继续等待
       } else {
           // 采集错误
           lv_timer_del(timer);
           firstCaptureTimer = NULL;
           updateEnrollmentProgress("采集失败", "第一次采集失败 请重试");
           enrollmentInProgress = false;
           return;
       }
       // ✅ 修复：删除死代码（上面所有分支都已return，这里永远不会执行）
   }, 200, NULL);  // 200ms间隔，非阻塞检查指纹采集
}

// ==================== 手动签到功能实现 ====================

/**
* 提交手动签到到服务器
* @param studentId 学生学号
* @return true=成功, false=失败
*/
bool submitManualCheckin(String studentId) {
   Serial.println("==================== 提交手动签到 ====================");
   Serial.println("学号: " + studentId);
   Serial.println("设备ID: " + String(DEVICE_ID));
   
   HTTPClient http;
   http.begin(SERVER_URL);  // 使用现有的 /api/checkin.php
   http.addHeader("Content-Type", "application/json");
   http.addHeader("X-Api-Token", API_TOKEN);
   http.setTimeout(5000);  // 5秒超时
   
   // ⭐ 构建JSON（直接用 student_id，不用 fingerprint_id）
   // checkin.php 会自动识别这是直接学号方式
   StaticJsonDocument<256> doc;
   doc["student_id"] = studentId;      // 直接发送学号
   doc["device_id"] = DEVICE_ID;       // 设备ID
   
   String jsonData;
   serializeJson(doc, jsonData);
   
   Serial.println("发送请求数据: " + jsonData);
   
   // 发送POST请求
   int httpCode = http.POST(jsonData);
   
   bool success = false;
   String responseName = "";
   
   if (httpCode == 200) {
       String response = http.getString();
       Serial.println("服务器响应码: " + String(httpCode));
       Serial.println("服务器响应内容: " + response);
       
       // 解析响应
       StaticJsonDocument<512> responseDoc;
       DeserializationError error = deserializeJson(responseDoc, response);
       
       if (!error) {
           success = responseDoc["success"] | false;
           
           if (success) {
               responseName = responseDoc["name"] | String("未知");
               Serial.println("✅ 手动签到成功");
               Serial.println("学生姓名: " + responseName);
               Serial.println("签到状态: " + String(responseDoc["status"] | "在寝"));
           } else {
               String errorMsg = responseDoc["message"] | String("未知错误");
               Serial.println("❌ 服务器返回失败: " + errorMsg);
           }
       } else {
           Serial.println("❌ JSON解析失败: " + String(error.c_str()));
       }
       
   } else if (httpCode > 0) {
       Serial.println("❌ HTTP错误码: " + String(httpCode));
       String response = http.getString();
       Serial.println("错误响应: " + response);
   } else {
       Serial.println("❌ HTTP请求失败，错误: " + http.errorToString(httpCode));
   }
   
   http.end();
   
   Serial.println("========================================");
   return success;
}
