# Cursor 開發交接文檔 — local_tm_course (TM Physical Course Management)
> 版本：V6.1（打掉重練版）｜目標伺服器：https://moodletest.tm-robot.com/mymoodle/ ｜Moodle 3.10.4 / PHP 7.2.24

---

## 1. 專案現況摘要

### Plugin 識別資訊
| 項目 | 值 |
|------|-----|
| Component name | `local_tm_course` |
| 安裝路徑 | `/moodleroot/local/tm_course/` |
| 目前版本（DB） | `2026040203`（伺服器已確認） |
| 本機最新版本 | `2026040205`（待上傳，含 attendance_manager 重大修正）|
| Release tag | `5.2.0` |
| Maturity | `MATURITY_BETA` |

### 三大模組定義
- **M1 — 資源管理（Admin）**：場次建立/編輯/刪除、批次建立（每週/每月重複）、桌次容量邏輯
- **M2 — 報名流程（Enrolment）**：自動/手動審核、先修課程驗證、Institution 自動補完、Moodle course 同步入學
- **M3 — 出缺席同步（Attendance）**：自動建 Moodle Group、同步 `mod_attendance`、標記出席 → 觸發課程完成

### 目前完成狀態
| 模組 | 狀態 | 備註 |
|------|------|------|
| M1 場次管理 | ✅ 完成 | sessions.php / edit_session.php 正常運作 |
| M1 批次建立 | ✅ 完成 | 每週/每月重複已測試 |
| M2 報名流程 | ✅ 完成 | 自動核准路徑已驗證 |
| M2 手動審核 | ✅ 完成 | enrolments.php Approve/Reject 正常 |
| M2 Institution 補完 | ✅ 完成 | 自動寫入 mdl_user.institution |
| M3 出席頁面 UI | ✅ 完成 | attendance.php 正常載入 |
| M3 建立 Group + attendance | ⚠️ 修正中 | `ensure_attendance_activity()` 改用直接 DB 寫入（v2026040205）|
| M3 標記出席/缺席 | ⏳ 待測試 | 依賴 setup 完成後才能測試 |
| I18n 7 語系 | ✅ 完成 | zh_tw / zh_cn / en / ja / de / fr / ko |
| 前端 index.php | ✅ 完成 | 學員報名入口 |
| 搜尋 search.php | ✅ 完成 | 三合一搜尋（姓名/Email/機構）|

---

## 2. 完整規格彙整

### 2.1 首頁與導航

#### 左側選單掛載（已實作）
- Hook：`local_tm_course_extend_navigation(global_navigation $nav)`
- 進入點：`/local/tm_course/index.php`
- 所有已登入非訪客使用者看到「課程報名管理」主節點
- Admin（有 `local/tm_course:manage` capability）額外看到：場次管理、報名紀錄、出缺席管理

#### Moodle 首頁項目掛載（已評估為不可行）
- Moodle 3.10.4 的 `frontpageloggedin` 項目是 core 硬寫，無外掛 hook 可掛入
- **替代方案（已實作）**：在 Moodle「網站公告」論壇發布公告文，貼入功能連結清單
- 相關連結清單：

| 功能 | URL |
|------|-----|
| 報名入口 | `/local/tm_course/index.php` |
| 場次管理 | `/local/tm_course/admin/sessions.php` |
| 新增場次 | `/local/tm_course/admin/edit_session.php` |
| 批次建立（每週） | `/local/tm_course/admin/edit_session.php?batch=1` |
| 批次建立（每月） | `/local/tm_course/admin/edit_session.php?batch=2` |
| 報名紀錄（全部） | `/local/tm_course/admin/enrolments.php` |
| 指定場次報名審核 | `/local/tm_course/admin/enrolments.php?sessionid=N` |
| 搜尋紀錄 | `/local/tm_course/search.php` |
| 出缺席管理 | `/local/tm_course/admin/attendance.php?sessionid=N` |

### 2.2 三方自動化同步邏輯

```
報名核准（auto or manual approve）
    │
    ├─ sync_moodle_enrolment()     → 將使用者加入 mdl_course（manual enrol；角色取自該課 manual 實例 `roleid` 或全站 enrol_manual 預設）
    │
    └─ attendance_manager::add_to_group()
            │
            ├─ 讀取 sessions.groupid（是否已建立 Group）
            ├─ 若已建立 → groups_add_member()
            └─ 若尚未建立 → 跳過（等 Admin 手動執行 Setup）

Admin 點擊「建立群組與出席記錄」（setup action）
    │
    ├─ ensure_group()
    │       ├─ 群組命名格式：[TM] {session_name} ({YYYY-MM-DD})
    │       ├─ 幂等：同名群組已存在則複用
    │       └─ groups_create_group() → 存入 sessions.groupid
    │
    ├─ ensure_attendance_activity()  ← 若 mod_attendance 已安裝
    │       ├─ 查找課程中已有 [TM] 前綴的 attendance instance
    │       ├─ 若無 → 直接 DB 寫入（attendance + course_modules + section sequence）
    │       ├─ 建立 attendance_sessions 時段（sessdate = session.starttime）
    │       ├─ 建立預設 statuses（P/L/A/E）
    │       └─ rebuild_course_cache()
    │
    └─ 將所有已核准學員加入 Group（補建）

標記出席（mark_attended）
    │
    ├─ 更新 enrolments.attended = 1/2
    ├─ 若 mod_attendance 已安裝 → 寫入 attendance_log
    └─ 若 attended = PRESENT → sync_completion() → mark_complete(time())
```

### 2.3 桌次容量邏輯

```
總容量 = num_desks × persons_per_desk
約束：
  - num_desks：1–6（最多 6 桌/教室）
  - persons_per_desk：1–3
  - 最大容量：6 × 3 = 18 人
  - 最小容量：1 × 1 = 1 人

狀態機：
  - STATUS_OPEN (1)：confirmed_count < total_capacity
  - STATUS_FULL (2)：confirmed_count >= total_capacity
  - STATUS_CLOSED (0)：Admin 手動關閉

核准時自動重算：session_manager::recalculate_status($sessionid)
```

### 2.4 Institution 自動補完

```php
// enrolment_manager::enrol() 內
$userobj = $DB->get_record('user', ['id' => $userid]);
if (empty($userobj->institution)) {
    if (empty(trim($institution))) {
        throw new moodle_exception('error_institution_required');
    }
    $DB->set_field('user', 'institution', trim($institution), ['id' => $userid]);
}
// 報名記錄也 snapshot 一份
$record->institution = $userobj->institution;
```

### 2.5 搜尋規格

- 頁面：`search.php`
- 搜尋欄位：`u.firstname`, `u.lastname`, `u.email`, `u.institution`, `CONCAT(firstname,' ',lastname)`
- 模糊比對：`$DB->sql_like(..., false)` (case-insensitive)
- 角色過濾：
  - Admin (`local/tm_course:manage`)：看到全部結果
  - 一般使用者：只看到自己的報名紀錄

---

## 3. 多國語系架構

### 目錄結構
```
local/tm_course/lang/
├── en/local_tm_course.php        ✅ 完成（主要語言）
├── zh_tw/local_tm_course.php     ✅ 完成（繁體中文）
├── zh_cn/local_tm_course.php     ✅ 完成（簡體中文）
├── ja/local_tm_course.php        ✅ 完成（日文）
├── de/local_tm_course.php        ✅ 完成（德文）
├── fr/local_tm_course.php        ✅ 完成（法文）
└── ko/local_tm_course.php        ✅ 完成（韓文）
```

### 關鍵字串分類（所有語系均須包含）
```
[Plugin名稱]    pluginname, pluginname_desc
[導航]          nav_manage, nav_sessions, nav_enrolments, nav_search, nav_calendar, nav_attendance
[場次欄位]      session_name, session_course, session_startdate, session_enddate,
                session_duration_hours, session_location, session_desks,
                session_persons_per_desk, session_total_capacity,
                session_approval_mode, session_approval_auto, session_approval_manual,
                session_prerequisite, session_status, session_status_open,
                session_status_full, session_status_closed, session_remaining_desks,
                session_description
[報名]          enrol_now, enrol_pending, enrol_approved, enrol_rejected,
                enrol_cancelled, enrol_waitlisted, cancel_enrolment,
                approve_enrolment, reject_enrolment
[錯誤]          error_desks_positive, error_desks_max, error_persons_range,
                error_end_after_start, error_hours_positive, error_session_full,
                error_already_enrolled, error_prerequisite,
                error_institution_required, error_course_not_found
[M3出缺席]      attendance_setup, attendance_re_setup, attendance_setup_done,
                attendance_setup_error, attendance_setup_ready, attendance_not_setup,
                attendance_setup_confirm, attendance_re_setup_confirm,
                attendance_mark_present, attendance_mark_absent,
                attendance_mark_all_present, attendance_markall_confirm,
                attendance_marked_present, attendance_marked_absent,
                attendance_marked_all, attendance_no_students,
                attendance_total_enrolled, attendance_present, attendance_absent,
                attendance_not_recorded, attendance_completion_note
```

---

## 4. 待完成開發清單

### 4.1 緊急（影響核心流程）

| 檔案 | 問題 | 待辦 |
|------|------|------|
| `classes/attendance_manager.php` | `ensure_attendance_activity()` 使用 `add_moduleinfo()` → PHP namespace 內呼叫全域函式失敗 | **已修正（v2026040205）** 改為直接 DB 寫入；待上傳測試 |
| `classes/attendance_manager.php` | 多處全域函式缺 `\` 前綴（`groups_create_group`、`get_coursemodule_from_id` 等） | **已修正（v2026040205）** |
| `classes/attendance_manager.php` | `get_session_attendance()` SQL 缺 `fullname()` 所需欄位 | **已修正（v2026040205）** 補上 `middlename, firstnamephonetic, lastnamephonetic, alternatename` |

### 4.2 高優先（功能不完整）

| 檔案 | 問題 | 待辦 |
|------|------|------|
| `lib.php` | `extend_navigation` 出缺席子項目的 URL 指向 `sessions.php` 而非 `attendance.php` | 修正為適當入口（待議：attendance 需要 sessionid，無法直接從 nav 進入） |
| `lib.php` | `extend_navigation_frontpage` 為空函式 | 確認是否需要 Frontpage block，或移除此 hook |
| `admin/attendance.php` | `✓ 出席` / `✗ 缺席` 按鈕功能尚未測試（依賴 setup 完成） | 待 v2026040205 上傳後測試 |
| `index.php` | 未測試學員角色的報名流程（Phase 2 測試） | 需切換 Learner 帳號測試 |

### 4.3 中優先（品質改善）

| 檔案 | 問題 | 待辦 |
|------|------|------|
| `admin/sessions.php` | 出缺席管理按鈕只能點擊進入，無狀態顯示（已 setup / 未 setup）| 可在 Actions 欄顯示 groupid 是否存在 |
| `admin/enrolments.php` | Filter 下拉（Pending）點擊後頁面不跳轉（View 按鈕行為待確認） | 確認 JS form submit 邏輯 |
| `classes/enrolment_manager.php` | `sync_moodle_enrolment()` 中 `enrol_get_plugin()` 為全域函式，namespace 內可能有問題 | 加 `\` 前綴安全化 |
| `styles.css` | `.btn-tm-danger` 等自訂 class 是否與主題衝突 | 全站測試 |

### 4.4 低優先（擴充功能）

| 功能 | 說明 |
|------|------|
| 出缺席 CSV 匯出 | Admin 可下載場次出缺席名單 |
| 候補自動升等 | 有名額釋放時自動核准 waitlisted 者 |
| Email 通知 | 報名成功/審核結果/提醒通知 |
| 行事曆整合 | 在 Moodle Calendar 中顯示場次 |
| Agent（業務）角色 | 可查詢所屬客戶的報名紀錄（Phase 3 測試）|

---

## 5. 測試協議（Testing Protocol）

### Phase 1：Admin 角色（目前進行中）

**測試帳號**：waylon.su（Admin 權限）
**測試伺服器**：https://moodletest.tm-robot.com/mymoodle/

| # | 測試項目 | URL | 預期結果 | 狀態 |
|---|---------|-----|---------|------|
| 1 | 場次管理頁面載入 | `/admin/sessions.php` | 顯示場次列表、Edit/Enrolments/出缺席管理按鈕 | ✅ 通過 |
| 2 | 新增場次表單 | `/admin/edit_session.php` | 表單完整顯示、容量預覽即時更新 | ✅ 通過 |
| 3 | 批次建立場次 | `edit_session.php?batch=1` | 每週重複建立 N 個場次 | ✅ 通過 |
| 4 | 報名紀錄總覽 | `/admin/enrolments.php` | 列出所有場次及報名數 | ✅ 通過 |
| 5 | 場次報名明細 | `/admin/enrolments.php?sessionid=N` | 顯示學員資料、審核按鈕 | ✅ 通過 |
| 6 | 出缺席頁面載入 | `/admin/attendance.php?sessionid=N` | 頁面正常、顯示學員、統計數字 | ✅ 通過 |
| 7 | 建立群組與出席記錄 | 點擊 Setup 按鈕 | 建立 Moodle Group + attendance activity | ⏳ 待測（v2026040205）|
| 8 | 標記個別出席 | 點擊 ✓ 出席按鈕 | 狀態變為 Present，課程完成觸發 | ⏳ 待測 |
| 9 | 全員標記出席 | 點擊「全員標記為出席」 | N 位學員全部 Present | ⏳ 待測 |

### Phase 2：Learner 角色（待 Phase 1 完成後）

**測試帳號**：需建立或使用現有學員帳號

| # | 測試項目 | URL | 預期結果 |
|---|---------|-----|---------|
| 1 | 首頁可見入口連結 | `/` | 公告欄有報名系統連結 |
| 2 | 報名入口頁面 | `/local/tm_course/index.php` | 顯示開放中的場次、剩餘桌次 |
| 3 | 直接報名（Auto approve）| 點擊「立即報名」 | 狀態 Approved，加入 Moodle course |
| 4 | Institution 未填時報名 | 送出無 institution 的表單 | 提示填寫，或 modal 要求輸入 |
| 5 | 搜尋自己的報名紀錄 | `/local/tm_course/search.php` | 只顯示自己的紀錄 |
| 6 | 取消報名 | 點擊取消 | 狀態變為 Cancelled，容量釋放 |

### Phase 3：Agent（業務）角色（待 Phase 2 完成後）

**角色定義**：有 `local/tm_course:viewall` 但沒有 `local/tm_course:manage`

| # | 測試項目 | 預期結果 |
|---|---------|---------|
| 1 | 搜尋客戶報名紀錄 | 可看到所有人的紀錄（不限自己）|
| 2 | 無法進入 Admin 頁面 | 403 Forbidden |
| 3 | 無法 Approve/Reject | 按鈕不顯示 |

---

## 6. 核心程式碼架構

### 6.1 db/install.xml（完整版）

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<XMLDB PATH="local/tm_course/db" VERSION="20260402" COMMENT="TM Course Management DB Schema">
  <TABLES>

    <!-- =========================================================
         local_tm_course_sessions — 實體課程場次
         ========================================================= -->
    <TABLE NAME="local_tm_course_sessions" COMMENT="Physical course sessions">
      <FIELDS>
        <FIELD NAME="id"                     TYPE="int"    LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="courseid"               TYPE="int"    LENGTH="10" NOTNULL="true" DEFAULT="0"
               COMMENT="Linked Moodle course (mdl_course.id)"/>
        <FIELD NAME="name"                   TYPE="char"   LENGTH="255" NOTNULL="true" DEFAULT=""/>
        <FIELD NAME="description"            TYPE="text"   NOTNULL="false"/>
        <FIELD NAME="location"               TYPE="char"   LENGTH="255" NOTNULL="false" DEFAULT=""/>
        <FIELD NAME="starttime"              TYPE="int"    LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="endtime"                TYPE="int"    LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="duration_hours"         TYPE="number" LENGTH="6" DECIMALS="2" NOTNULL="true" DEFAULT="8"
               COMMENT="Actual training hours (may differ from start/end span)"/>
        <FIELD NAME="num_desks"              TYPE="int"    LENGTH="2"  NOTNULL="true" DEFAULT="6"
               COMMENT="Number of desks; max 6 per room"/>
        <FIELD NAME="persons_per_desk"       TYPE="int"    LENGTH="1"  NOTNULL="true" DEFAULT="2"
               COMMENT="1-3 persons per desk"/>
        <FIELD NAME="approval_mode"          TYPE="int"    LENGTH="1"  NOTNULL="true" DEFAULT="0"
               COMMENT="0=auto, 1=manual"/>
        <FIELD NAME="prerequisite_courseid"  TYPE="int"    LENGTH="10" NOTNULL="false"
               COMMENT="Required prior course; NULL = none"/>
        <FIELD NAME="status"                 TYPE="int"    LENGTH="1"  NOTNULL="true" DEFAULT="1"
               COMMENT="0=closed, 1=open, 2=full"/>
        <FIELD NAME="groupid"                TYPE="int"    LENGTH="10" NOTNULL="false"
               COMMENT="mdl_groups.id — auto-created Moodle group"/>
        <FIELD NAME="attendance_cmid"        TYPE="int"    LENGTH="10" NOTNULL="false"
               COMMENT="mdl_course_modules.id for linked mod_attendance"/>
        <FIELD NAME="attendance_sessionid"   TYPE="int"    LENGTH="10" NOTNULL="false"
               COMMENT="mdl_attendance_sessions.id for the time slot"/>
        <FIELD NAME="timecreated"            TYPE="int"    LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="timemodified"           TYPE="int"    LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="createdby"              TYPE="int"    LENGTH="10" NOTNULL="true" DEFAULT="0"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary"    TYPE="primary" FIELDS="id"/>
        <KEY NAME="fk_course"  TYPE="foreign" FIELDS="courseid" REFTABLE="course" REFFIELDS="id"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="idx_starttime" UNIQUE="false" FIELDS="starttime"/>
        <INDEX NAME="idx_status"    UNIQUE="false" FIELDS="status"/>
        <INDEX NAME="idx_courseid"  UNIQUE="false" FIELDS="courseid"/>
      </INDEXES>
    </TABLE>

    <!-- =========================================================
         local_tm_course_enrolments — 學員報名記錄
         ========================================================= -->
    <TABLE NAME="local_tm_course_enrolments" COMMENT="Session enrolment records">
      <FIELDS>
        <FIELD NAME="id"           TYPE="int"  LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="sessionid"    TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="userid"       TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="status"       TYPE="int"  LENGTH="1"  NOTNULL="true" DEFAULT="0"
               COMMENT="0=pending, 1=approved, 2=rejected, 3=cancelled, 4=waitlisted"/>
        <FIELD NAME="desk_number"  TYPE="int"  LENGTH="2"  NOTNULL="false"
               COMMENT="Assigned desk (1-6); NULL = not yet assigned"/>
        <FIELD NAME="attended"     TYPE="int"  LENGTH="1"  NOTNULL="true" DEFAULT="0"
               COMMENT="0=unset, 1=present, 2=absent"/>
        <FIELD NAME="institution"  TYPE="char" LENGTH="255" NOTNULL="false"
               COMMENT="Snapshot of mdl_user.institution at enrolment time"/>
        <FIELD NAME="notes"        TYPE="text" NOTNULL="false"
               COMMENT="Admin notes: rejection reason etc."/>
        <FIELD NAME="timecreated"  TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="timemodified" TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary"         TYPE="primary" FIELDS="id"/>
        <KEY NAME="fk_session"      TYPE="foreign" FIELDS="sessionid"
             REFTABLE="local_tm_course_sessions" REFFIELDS="id"/>
        <KEY NAME="fk_user"         TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
        <KEY NAME="uq_session_user" TYPE="unique"  FIELDS="sessionid, userid"/>
      </KEYS>
      <INDEXES>
        <INDEX NAME="idx_userid"    UNIQUE="false" FIELDS="userid"/>
        <INDEX NAME="idx_status"    UNIQUE="false" FIELDS="status"/>
        <INDEX NAME="idx_sessionid" UNIQUE="false" FIELDS="sessionid"/>
      </INDEXES>
    </TABLE>

    <!-- =========================================================
         local_tm_course_batch — 批次建立紀錄
         ========================================================= -->
    <TABLE NAME="local_tm_course_batch" COMMENT="Batch session creation records">
      <FIELDS>
        <FIELD NAME="id"           TYPE="int"  LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="courseid"     TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="batch_name"   TYPE="char" LENGTH="255" NOTNULL="true" DEFAULT=""/>
        <FIELD NAME="repeat_type"  TYPE="int"  LENGTH="1"  NOTNULL="true" DEFAULT="0"
               COMMENT="0=none, 1=weekly, 2=monthly"/>
        <FIELD NAME="repeat_count" TYPE="int"  LENGTH="3"  NOTNULL="true" DEFAULT="1"/>
        <FIELD NAME="session_ids"  TYPE="text" NOTNULL="false"
               COMMENT="JSON array of created session ids"/>
        <FIELD NAME="timecreated"  TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
        <FIELD NAME="createdby"    TYPE="int"  LENGTH="10" NOTNULL="true" DEFAULT="0"/>
      </FIELDS>
      <KEYS>
        <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
      </KEYS>
    </TABLE>

  </TABLES>
</XMLDB>
```

### 6.2 lib.php（Hook 架構）

```php
<?php
/**
 * lib.php — Moodle hook callbacks
 * @package local_tm_course
 */
defined('MOODLE_INTERNAL') || die();

/**
 * HOOK: 左側選單 / flat navigation
 * 已登入非訪客：看到「課程報名管理」主節點 + 搜尋
 * Admin (local/tm_course:manage)：額外看到場次管理、報名紀錄、出缺席
 */
function local_tm_course_extend_navigation(global_navigation $nav): void {
    global $USER, $CFG;
    if (!isloggedin() || isguestuser()) return;

    $node = $nav->add(
        get_string('nav_manage', 'local_tm_course'),
        new moodle_url('/local/tm_course/index.php'),
        navigation_node::TYPE_CUSTOM, null, 'tm_course',
        new pix_icon('i/course', '')
    );
    $node->showinflatnavigation = true;
    $node->force_open();

    $node->add(get_string('nav_search', 'local_tm_course'),
        new moodle_url('/local/tm_course/search.php'),
        navigation_node::TYPE_CUSTOM, null, 'tm_course_search');

    if (has_capability('local/tm_course:manage', context_system::instance())) {
        $node->add(get_string('nav_sessions', 'local_tm_course'),
            new moodle_url('/local/tm_course/admin/sessions.php'),
            navigation_node::TYPE_CUSTOM, null, 'tm_course_sessions');
        $node->add(get_string('nav_enrolments', 'local_tm_course'),
            new moodle_url('/local/tm_course/admin/enrolments.php'),
            navigation_node::TYPE_CUSTOM, null, 'tm_course_enrolments');
        // NOTE: 出缺席需要 sessionid，nav 入口指向 sessions.php（從那邊點按鈕進入）
        $node->add(get_string('nav_attendance', 'local_tm_course'),
            new moodle_url('/local/tm_course/admin/sessions.php'),
            navigation_node::TYPE_CUSTOM, null, 'tm_course_attendance');
    }
}

/**
 * HOOK: Boost sidebar（可選，目前留空）
 * Moodle 3.10 的 frontpageloggedin items 無法由外掛掛載。
 * 建議使用「網站公告」論壇貼出連結清單作為替代。
 */
function local_tm_course_extend_navigation_frontpage(navigation_node $node): void {
    // 不需要額外實作 — 由 extend_navigation 統一處理
}
```

### 6.3 Capabilities（db/access.php）

```php
$capabilities = [
    'local/tm_course:manage' => [
        'riskbitmask' => RISK_CONFIG,
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_SYSTEM,
        'archetypes'  => ['manager' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW],
    ],
    'local/tm_course:enrol' => [
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_SYSTEM,
        'archetypes'  => ['student' => CAP_ALLOW, 'user' => CAP_ALLOW],
    ],
    'local/tm_course:viewall' => [
        'captype'     => 'read',
        'contextlevel'=> CONTEXT_SYSTEM,
        'archetypes'  => ['manager' => CAP_ALLOW],
        // Agent（業務）需要在 Moodle 後台手動給予此 capability
    ],
    'local/tm_course:approve' => [
        'captype'     => 'write',
        'contextlevel'=> CONTEXT_SYSTEM,
        'archetypes'  => ['manager' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW],
    ],
];
```

### 6.4 Class 架構概覽

```
local_tm_course/
├── classes/
│   ├── session_manager.php      # M1: CRUD, capacity, status machine
│   │   ├── const STATUS_OPEN=1, STATUS_FULL=2, STATUS_CLOSED=0
│   │   ├── const ENROL_PENDING=0, ENROL_APPROVED=1, ENROL_REJECTED=2, ...
│   │   ├── const APPROVAL_AUTO=0, APPROVAL_MANUAL=1
│   │   ├── create_session(array $data): int
│   │   ├── update_session(int $id, array $data): void
│   │   ├── delete_session(int $id): void
│   │   ├── get_session(int $id): stdClass     ← calls enrich_session()
│   │   ├── get_sessions(array $filters): array
│   │   ├── batch_create(array $base, int $repeat_type, int $repeat_count): array
│   │   ├── total_capacity(stdClass): int
│   │   ├── confirmed_count(int $sessionid): int
│   │   ├── remaining_persons(stdClass): int
│   │   ├── remaining_desks(stdClass): int
│   │   ├── recalculate_status(int $sessionid): void
│   │   └── enrich_session(stdClass): stdClass  ← adds computed fields
│   │
│   ├── enrolment_manager.php    # M2: enrol, approve, prereq, institution sync
│   │   ├── enrol(int $sessionid, int $userid, string $institution): int
│   │   ├── approve(int $enrolid): void
│   │   ├── reject(int $enrolid, string $reason): void
│   │   ├── cancel(int $enrolid, int $userid): void
│   │   ├── has_completed_course(int $userid, int $courseid): bool
│   │   ├── sync_moodle_enrolment(int $userid, int $courseid, string $action): void
│   │   └── search(string $query, bool $admin, int $userid): array
│   │
│   └── attendance_manager.php   # M3: groups, mod_attendance, completion
│       ├── const ATTEND_UNSET=0, ATTEND_PRESENT=1, ATTEND_ABSENT=2
│       ├── is_mod_attendance_installed(): bool
│       ├── setup_session(int $sessionid): void        ← 建 Group + attendance
│       ├── add_to_group(int $sessionid, int $userid): void
│       ├── mark_attended(int $enrolid, int $attended): void
│       ├── mark_all_present(int $sessionid): int
│       └── get_session_attendance(int $sessionid): array
│
├── admin/
│   ├── sessions.php     # 場次列表（Admin）
│   ├── edit_session.php # 新增/編輯/批次建立
│   ├── enrolments.php   # 報名總覽 + 場次明細 + Approve/Reject
│   └── attendance.php   # 出缺席管理（M3 UI）
│
├── db/
│   ├── install.xml      # 完整 schema（見上方）
│   ├── upgrade.php      # 版本升級腳本（已至 2026040205）
│   └── access.php       # Capabilities 定義
│
├── lang/                # 7 語系（全部完成）
├── index.php            # 學員報名入口
├── search.php           # 三合一搜尋
├── lib.php              # Moodle hooks
├── settings.php         # Admin 設定頁
├── styles.css           # 自訂 CSS（TM 品牌色）
└── version.php          # 目前版本 2026040205
```

---

## 7. 給 Cursor 的指示

### 首要任務（Next Sprint）
1. **上傳並測試 v2026040205** — 測試「建立群組與出席記錄」按鈕，確認 Group 建立 + attendance activity 建立成功
2. **完成 M3 出席標記測試** — 個別 ✓出席 / 全員標記，確認 `attended` 欄位更新 + 課程完成觸發
3. **Phase 2 Learner 測試** — 切換到學員帳號，測試報名入口 `index.php` 完整流程

### 重要注意事項
- **PHP 版本**：伺服器為 **PHP 7.2.24**，禁止使用：
  - `fn() =>` 箭頭函式（PHP 7.4+）
  - `??=` 空值指派（PHP 7.4+）
  - `match` 表達式（PHP 8.0+）
  - `?->` nullsafe operator（PHP 8.0+）
- **Namespace 陷阱**：所有 PHP class 在 `namespace local_tm_course` 內，呼叫 Moodle 全域函式必須加 `\` 前綴（e.g., `\groups_create_group()`、`\rebuild_course_cache()`）
- **DB 回傳型別**：`$DB->get_record()` 回傳所有欄位皆為 `string`，與常數比較必須用 `(int)` 強制轉型，不可用 `===`
- **版本號**：每次改動必須遞增，格式為 `YYYYMMDDXX`（目前 `2026040205`）

### 伺服器資訊
- URL: https://moodletest.tm-robot.com/mymoodle/
- Moodle: 3.10.4（Build: 20210521）
- PHP: 7.2.24
- 外掛安裝路徑：Admin → 外掛 → 安裝外掛 → 上傳 ZIP
