Moodle Plugin Spec: TM Physical Course Management (`local_tm_course`) V5.7

## 1. 核心目標與權限 (Core Goals & Permissions)
- 視覺與品牌一致性：
  - 主色使用 Techman Blue `#005f7e`
  - 強調色使用 Techman Green `#74b42a`
- 權限管理延續 M4 設計：
  - 規則入口：`/local/tm_course/settings/permissions.php`
- 業務自動授權規則：
  - 可依 `idnumber` 前綴（prefix）比對
  - 可依 `institution` 關鍵字比對
  - 可依使用者 Email/姓名關鍵字比對
- 語系策略：
  - `lang/zh_tw` 為主要語系
  - 新增 UI 需提供 fallback，避免缺字串造成頁面錯誤

## 2. 報名與審核流程 (Enrolment & Approval)

### M4 報名流程
- 一般報名：
  - 使用者送出後依場次審核模式進入自動核准或待審核
  - 手動審核流程需保留 pending 狀態
- 批次報名：
  - 支援批次匯入與逐筆資料驗證（含 Email）
  - institution 規則與既有 `institution` 欄位邏輯一致
- Email / 站內通知：
  - 送出報名後可通知管理端
  - 審核結果（核准/駁回）可通知申請者
  - 通知失敗不得阻斷主流程
- Debrief 補強：
  - 支援在送出前顯示 Debrief/摘要資訊
  - 可呈現 Email 發送狀態與審核狀態 badge
- 追蹤欄位：
  - 批次建立報名時初始狀態應為 `pending`
  - 需記錄 `batch_submittedby` 供追蹤與審核使用

### 審核流程
- 審核頁可操作 `Desk Number`（僅實體課程）
- 支援取消核准（Revoke）：
  - 狀態回到 `pending`
  - 保留審核軌跡
- 核准/駁回結果需透過 M5 通知機制發送

## 3. 自動化與通知 (Automation & Notifications)

### M3 與 Moodle 整合
- 報名核准後需同步 Moodle Group 與 Attendance 相關資源
- Group 命名慣例需維持日期可讀格式（例如 `YYYY/MM/DD`）
- **Moodle 課程選課（Manual enrolment／手動選課）與角色（規格補充）**
  - 核准或自動核准將使用者寫入場次所綁定之 Moodle 課程時，應使用 **該課程已啟用的「手動選課」實例**（`{enrol}`，`enrol = manual`）上設定的 **預設角色（`roleid`）**，與管理員在課程內透過「手動選課」加入成員時所依據的預設角色來源一致。
  - 若該課程實例之 `roleid` 未設定或無效，則退回使用全站 **手動選課外掛（`enrol_manual`）** 的預設角色設定（與 Moodle 建立預設 manual 實例時相同來源）。
  - **不得**以口語「學員」、帳號在組織上的稱呼類型（例如經銷商、代理商、訪客、內部人員等），或固定角色 shortname（例如 `student`）作為是否允許選入課程的關卡；實際指派角色以上述 **Manual 實例／外掛預設** 為準，由課程與站台設定決定。

### M5 通知機制
- 建議集中於 `classes/notification_helper.php`
- 使用 Moodle Message Provider
- 通知場景至少包含：
  - 新報名通知管理端
  - 審核結果通知申請者
  - 駁回/取消等狀態變更通知
- 通知通道：
  - 所有通知需同時支援「站內通知（鈴鐺）」與「Email」。
- 模板化設定：
  - 系統管理員可在通知設定頁，依通知情境分別編輯主旨/內文模板。
  - 模板可使用資料變數（token）插入實際資料。
- 收件對象設定：
  - 每個通知情境需可勾選預設收件對象。
  - 每個通知情境需可複選額外系統角色作為加發對象。
  - 同一事件若同一使用者命中多個收件來源，系統需去重後僅送出一次。

#### M5 通知設定 UI 規格
- 通知設定頁採「通知種類 block 入口 + 各自獨立設定分區」。
- 每個通知種類 block 必須顯示副標題，說明觸發情境。
- 預設所有通知設定分區為收合狀態；點擊 block 才展開該分區。
- 一次僅可展開一個通知分區（accordion 行為）。
- 頁面需提供可點擊的「儲存變更」按鈕（單一按鈕）。

#### M5 通知模板變數（依通知種類）
- `new_enrolment`（新報名通知）
  - 可用變數：`{{session}}`、`{{learner}}`、`{{status}}`
- `approval_result`（審核結果通知）
  - 可用變數：`{{session}}`、`{{learner}}`、`{{status}}`、`{{reason}}`
- `cancelled`（取消報名通知）
  - 可用變數：`{{session}}`、`{{learner}}`、`{{status}}`、`{{reason}}`
  - `{{reason}}` 需支援帶入取消原因代碼對應文字（含 `work`、`other_session`、`other`）。
- `pending_overdue`（逾時未審提醒）
  - 可用變數：`{{count}}`、`{{threshold}}`
- `reservation_result`（客製需求審核結果）
  - 可用變數：`{{reservationid}}`、`{{status}}`、`{{reason}}`

### M5-N3 逾時未審提醒
- 實作方式：
  - Cron Job：`classes/task/remind_pending_enrolment.php`
- 設定來源：
  - 由 `settings.php` 提供可調參數
  - 參數名稱：`reminder_threshold`
  - UI?Dropdown
  - 可選值：
    - 分鐘：5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55
    - 小時：1, 2, 3, ... , 24
  - 預設值：24 小時
- 設定儲存：
  - 寫入 `config_plugins` 的 `reminder_threshold`
- 篩選條件：
  - `timecreated < (current_time - reminder_threshold_seconds)`
  - 僅通知 `pending` 狀態報名
- 通知對象：
  - 該課程/場次的 approvers
  - 通知須避免重複轟炸

## 4. 曆式佈局 (M6: Calendar Layout)
- 核心視圖：
  - 預設使用全螢幕寬度 `Month View`。
  - 視覺風格需對齊高質感學習平台（如 LinkedIn Learning / Udemy），呈現專業、現代、高端感。

- Event Styling（Solid Card System）：
  - 場次事件必須採用「實心卡片（Solid Card）」樣式，不可僅以純文字顯示。
  - 卡片背景色：Techman Blue `#005f7e`。
  - 文字顏色：高對比白色 `#FFFFFF`。
  - 卡片圓角：`4px - 6px`。
  - 卡片內距：需有明確上下與左右 padding，避免文字貼邊。

- Visual Hierarchy 與狀態辨識：
  - 狀態標記（Status Tag）：
    - 高重要度狀態（例如 Open / Featured）需使用 Techman Green `#74b42a` 作為 badge 或邊框強調。
  - 報名進度（Enrollment Progress）：
    - 每張事件卡片底部需加入低干擾、半透明進度條，用於表達 `X/18` 報名比例。
    - 進度條僅作視覺輔助，不改變實際資料判定邏輯。

- Grid 與 Typography 清理：
  - 月曆格線（Grid Lines）需改為極淺灰 `#F0F0F0`，降低雜訊。
  - 今日日期（Today）數字需使用 Techman Blue `#005f7e` 的圓形或實心背景強調。
  - 週末（Saturday / Sunday）日期格背景需使用輕微灰白底，與平日區隔但不可過度搶眼。

- Advanced Interactions（FullCalendar）：
  - Hover 狀態：
    - 事件卡片基礎樣式需設定 `transition: all 0.3s ease;`。
    - 事件卡片滑入時需套用「浮起」效果：`transform: translateY(-5px) !important;`。
    - 同時加上柔和陰影：`box-shadow: 0 8px 20px rgba(0,0,0,0.15);`。
    - Hover 狀態需提升 `z-index`，確保卡片浮在鄰近日期格之上。
  - Popover 強化：
    - 浮窗必須以「事件元素本身」為定位參考（anchor），不可使用滑鼠座標作為主要定位依據。
    - 定位策略建議 `placement: top` 並允許 `auto` fallback。
    - 浮窗容器需使用 viewport 邊界保護（例如 `boundary: viewport`）並可 `appendTo: document.body`，避免被月曆容器裁切。
    - Hover 浮窗內容至少包含：
      - Full Course Name
      - Instructor / Speaker
      - Available Seats（清楚可讀的人數資訊）
    - 浮窗內容需移除「講師頭像」顯示。
    - 浮窗底部需提供明顯 `View Details` 按鈕。
    - 浮窗需採用白底，並以 Techman Blue `#005f7e` 作為 header 或按鈕主色。
    - 浮窗資訊應維持清楚的文字層級與留白，不可擁擠。

## 5. 互動與權限策略 (Interaction & Permissions)
- 自動授權依據：
  - 可由 `institution`、`idnumber` 等規則命中
- 申請互動：
  - 報名動作需提供明確成功/失敗回饋
  - 審核前狀態統一顯示為 `pending`
- 業務（Sales）權限：
  - 可使用批次報名與追蹤功能
  - 不可越權操作管理端專屬設定
- 管理員（Admin）權限：
  - 可管理場次、審核、權限規則與系統設定

## 6. M6 月曆資料流 (Data Flow)
- 月曆資料來源：
  - 透過 AJAX 取得 `local_tm_session` 事件資料
  - 報名狀態依 `local_tm_enrolment` 計算顯示
  - Calendar must render all created sessions within the visible month range (not only open status).
  - Front-end init must wait until FullCalendar is available (Moodle loads `$PAGE->requires->js(..., true)` in the page footer; inline scripts must not assume the library is already defined).
  - If the site blocks `cdn.jsdelivr.net`, host FullCalendar assets locally or allowlist the CDN in CSP.
- 報名提交：
  - 寫入報名資料到 `local_tm_enrolment`
  - 手動審核模式需維持 `pending`，不可提前要求 desk
- 審核決策：
  - 審核結果變更需連動 M5 通知與後續同步
- 同步流程：
  - 需同步處理 Moodle Group 與 `YYYY/MM/DD` 命名規範

## 7. 文件治理 (Documentation Governance)
- `spec.md` 為**行為與介面規格**的唯一規格來源（single source of truth for product spec）
- 任何行為調整必須先回寫 `spec.md`
- `README.md` 需維持與 `spec.md` 的角色權限與近期行為一致（至少同步：Auto 時段規則、課程連動預設課時、角色權限區分）。
- **`lesson-learned.md`**：累積使用者回報、除錯根因與已驗證解法（維運／開發教訓），與本檔分工，不重複貼兩份長篇教訓內文。

## 8. 追加規格（Session 截止與管理者特權）

- 場次自動截止規則（Auto Close Before Start Day）：
  - 每個場次需在「開課日前一天 00:00」自動改為 `已截止`。
  - 範例：場次時間為 `4/2 09:30`，則系統需於 `4/1 00:00` 起將該場次狀態自動調整為 `已截止`。
  - 此規則為無條件自動生效，不依使用者角色或前端操作觸發。

- 已截止場次的報名限制：
  - 一般使用者不可對 `已截止` 場次進行報名。
  - 業務（Sales）不可對 `已截止` 場次使用批次報名。
  - 前端卡片與清單可顯示該場次，但報名相關按鈕需呈現不可操作狀態。

- 管理者特權（Only Admin Override）：
  - 管理者仍可對 `已截止` 場次執行批次加入學員（批次報名寫入）作業。
  - 此特權僅限管理者，不可下放給業務批次報名權限。

- 管理端入口整合：
  - 需將業務批次報名使用的功能模組，整合為管理者在「編輯場次」頁可直接進入的操作入口。
  - 該入口應帶入當前場次脈絡（session id），以便管理者直接對該場次執行批次加入。

## 9. 追加規格（場次語言 / 授課型態 / 容量設定）

- 場次資訊新增「授課語言」：
  - 欄位選項：`英文`、`繁體中文`。
  - 管理者需可於「編輯場次」頁設定。

- 場次資訊新增「授課型態」：
  - 欄位選項：`實體`、`視訊`。
  - 管理者需可於「編輯場次」頁設定。
  - 當選擇 `視訊` 時，需即時顯示「視訊連結」輸入欄位供管理者填寫並儲存。

- 容量設定規則調整：
  - 教室選單中需移除容量字樣（例如 `6x3=18` 這類提示）。
  - 「管理場次」中的容量區塊需改為管理者直接設定：
    - 幾桌（桌數）
    - 每桌最多人數
  - 若授課型態為 `視訊`，容量區塊在 UI 上需自動失效（不可編輯）。
  - 當管理者尚未設定時，預設值：
    - 手臂教室：`6` 桌、每桌 `3` 人
    - 維修教室：`2` 桌、每桌 `3` 人

- 前端顯示：
  - 月曆卡片與「開課場次」卡片中需顯示：
    - 授課語言
    - 授課型態（實體 / 視訊）
  - 視訊連結不需直接顯示在卡片資訊中。

## 10. M7：Session Duration Automation

- Course Metadata Editor（課程總時數管理）：
  - 需新增一個管理介面，供管理者維護每門課程的 `total_hours`（以 `courseid` 關聯）。
  - 介面形式可為簡易 CRUD 表格（新增、編輯、刪除、查詢）。
  - `total_hours` 為場次 Auto Mode 計算的唯一來源。

- Session「Auto Mode」時段計算規則：
  - 當建立場次（`local_tm_session` / `local_tm_course_sessions`）且 Duration Mode = `Auto` 時，需依授課型態套用不同規則：

  - 實體課程（`實體`）：
    - 當管理者選擇授課型態為 `實體` 時，系統需自動將 Duration Mode 帶入 `Auto`。
    - 預設開始時間：`09:30`。
    - 每日上限：使用全域設定值 `physical_daily_limit`（預設 `7` 小時）。
    - `physical_daily_limit` 設定需放在既有 Plugin Settings（`settings.php`，與 `reminder_threshold` 同區）。
    - 結束日期/時間必須依所選 Moodle 課程的預設時數（`total_hours`）計算並即時回填欄位。
    - 計算公式需包含 `1` 小時午餐時間（僅實體課程）。
    - Overflow 邏輯：
      - 若 `total_hours > physical_daily_limit`，需自動切分或延展到連續日期。
      - 範例：`10` 小時課程，週一 09:30 開始：
        - Day 1：09:30 - 16:30（7 小時）
        - Day 2：09:30 - 12:30（剩餘 3 小時）

  - 視訊課程（`視訊`）：
    - 開始時間讀取管理者輸入（若未輸入可採可設定預設時間）。
    - 結束時間 = 開始時間 + `total_hours`。
    - 不套用 7 小時每日上限（除非未來規格另行定義）。

- Backend（PHP）：
  - 需新增 helper：`calculate_session_times($courseid, $type, $startdate)`。
  - 函式需封裝：
    - 讀取 `total_hours`
    - 實體課程每日上限（由 `get_config('local_tm_course', 'physical_daily_limit')` 讀取）與跨日拆分
    - 視訊課程直加總時數
  - 週末規則：
    - 目前預設為「連續日期」。
    - 若後續需跳過週末，應再加設定開關（本版先不強制）。

- Frontend（UI）：
  - 場次建立表單在選課後，需以 AJAX 讀取該課程 `total_hours`。
  - 在 `Auto` 模式下，需以 AJAX 呼叫後端計算結果並回填 End Date/Time（避免前端硬編碼每日上限）。
  - `實體/視訊`切換時，需正確觸發：
    - 實體：自動切換 Duration Mode = Auto，開始時間預設 09:30，並以課程預設時數即時計算結束欄位
    - 視訊：使用管理者輸入（或預設）

- Plugin Settings（`settings.php`）：
  - 新增 `physical_daily_limit` 設定（建議 `admin_setting_configtext` 或 `admin_setting_configselect`）。
  - 預設值：`7`。
  - 說明文字：`Sets the maximum training hours per day for physical courses in Auto Mode.`

- Database：
  - 需建立（或擴充）資料表以儲存課程 `total_hours`，並以 `courseid` 關聯。
  - `upgrade.php` 與 `install.xml` 需同步可部署。

- Constraints（實作限制）：
  - 不得修改 M6 Calendar Layout 與 M4 Enrolment 主流程，除非為顯示 M7 資料所必須。
  - `zh_tw` 語系需完整覆蓋所有新增 UI 與錯誤字串。

## 11. 已實作更新（2026-04-09）

- Auto 模式即時回填（實體）：
  - 當 `授課型態 = 實體` 時，Duration Mode 強制為 `Auto`，開始時間預設 `09:30`。
  - 管理者變更開始日期/時間或關聯課程後，前端需立即呼叫後端 `duration_calc.php`，並回填 `結束日期/時間`。
  - 為避免舊 AJAX 回應覆寫新值，前端需採用請求序號（僅套用最後一次回應）。

- Auto 計算來源與規則（實體）：
  - 結束時間由 `calculate_session_times()` 依課程 `預設課時` + 午餐 `1` 小時計算。
  - 若超過 `physical_daily_limit`，需依每日上限跨日切分。

- 課時欄位定義（資料一致性）：
  - `local_tm_course_sessions.duration_hours` 在「實體 + Auto」情境下，定義為「課程預設授課時數（教學時數）」；
  - 不使用跨日時間戳差（避免因過夜產生 26.0 小時等誤差顯示）。

- 課程連動設定（預設課時）：
  - `settings/course_mapping.php` 儲存後重新開啟頁面，`預設課時` 必須顯示最新已儲存值，不可回退為舊值。

- 前台開課場次卡片（資訊呈現）：
  - 實體課程在課時下方需顯示備註：`備註：包含用餐時間 1 小時`。
  - 若場次跨日，時間列需顯示含日期的起訖時間（而非僅顯示時:分），避免誤讀。

## 12. 補充規格（2026-04-09，Hotfix）

- 課程連動設定（`settings/course_mapping.php`）：
  - 儲存 `預設課時` 後重新開啟頁面，欄位值必須回顯資料庫最新值。
  - 類別遞迴渲染（category closure）不得遺漏 `duration_by_course` 來源，避免 UI 回退舊值/預設值。

- Auto 模式一致性：
  - 後台「計算後課時」在 `實體 + Auto` 場景應顯示課程預設授課時數（教學時數），不得用跨日時戳差推算。
  - 結束日期/時間必須以後端 `duration_calc.php` / `calculate_session_times()` 回傳值為準，前端只做回填顯示。

- 前台卡片文案：
  - 實體課程時數區塊固定顯示備註：`備註：包含用餐時間 1 小時`（多語系需同步）。

## 13. 角色與權限區分（目前版本）

- 管理者（Admin / Manager，具 `local/tm_course:manage`）：
  - 場次管理：新增/編輯/刪除、場次狀態管理、批次建立。
  - 審核管理：核准/拒絕報名（`local/tm_course:approve`）。
  - 權限規則：管理批次報名自動授權規則（`settings/permissions.php`）。
  - 報名查詢：可看全部報名紀錄（`local/tm_course:viewall`）。
  - 批次報名：可執行批次加入，且可對已截止場次批次加入（admin override）。

- 業務（Sales，`permissions_manager::user_can_batch_enrol()` 為 true）：
  - 可用批次報名入口（前提：符合角色 capability 或規則命中）。
  - 不可使用管理者後台（無 `local/tm_course:manage`）。
  - 已截止場次不可批次加入（由 `batch_enrol.php` 擋下，僅管理者可 override）。
  - 一般情境不可看全部報名（除非另授 `local/tm_course:viewall`）。

- 一般使用者（Learner，具 `local/tm_course:enrol`）：
  - 可在前台瀏覽場次、報名/重報、取消報名。
  - 不可審核、不可批次報名、不可進入管理設定頁。
  - 對已截止場次不可報名。

## 14. 首頁 Dashboard（方案 A，2026-04-10）

- 顯示方式：
  - 採 `local_tm_course_before_standard_top_of_body_html()` 注入首頁主內容區，不修改 Moodle core「登入時首頁項目」核心選單。
  - 僅在使用者登入且 frontpage layout 顯示。

- 位置控制（系統管理員）：
  - Dashboard 右上角提供位置切換按鈕（navigation controls）：
    - `首頁標題下`
    - `網站公告後`
    - `主內容底部`
  - 位置設定寫入 plugin config：`front_dashboard_position`。

- 顯示/隱藏控制（系統管理員）：
  - Dashboard 右上角提供「隱藏給一般用戶 / 顯示給一般用戶」控制。
  - 顯示狀態寫入 plugin config：`front_dashboard_visible`。
  - 隱藏後：
    - 一般用戶不顯示 dashboard
    - 系統管理員仍可見控制列，以便重新啟用
  - 控制操作走專用端點 `dashboard_control.php`，需 `sesskey` 且僅 `is_siteadmin()` 可操作。

- Dashboard 按鈕：
  - 一般使用者可見：
    - `探索 & 預約課程`（連至 `/local/tm_course/index.php`）
    - `搜尋報名紀錄`（連至 `/local/tm_course/search.php`）
  - 系統管理員專用（僅 site admin 可見）：
    - `場次管理`（`/local/tm_course/admin/sessions.php`）
    - `教室管理`（`/local/tm_course/classroom/index.php`）
    - `課程連動設定`（`/local/tm_course/settings/course_mapping.php`）
    - `報名審核`（`/local/tm_course/admin/enrolments.php`）

- 視覺規範：
  - 保持 M6 風格（實心卡片、Techman Blue/Green）。
  - `探索 & 預約課程` 按鈕預設灰底，hover/focus 顯示 TM 綠色調。

## 15. Dashboard 與前台互動補充（2026-04-10）

- Dashboard「即將到來的課程」資料欄位：
  - 需顯示：
    - 已分配桌次（若有）
    - 當次葷素紀錄摘要（同 `enrolment_manager::format_diet_summary()`）
  - 每張已核准卡片需提供：
    - `取消報名`（沿用既有取消原因驗證規則）
    - `變更葷素習慣`

- 前台「開課場次」清單（`/local/tm_course/index.php`）：
  - 對已審核（approved）且已報名卡片，在與取消報名同區塊新增 `變更葷素習慣` 按鈕。
  - 已報名資訊區需顯示葷素摘要文字。

- 飲食變更流程：
  - 新增頁面 `enrol_diet_edit.php`，供已報名使用者更新 diet 欄位，不重建報名。
  - 更新完成後返回前台並顯示成功訊息。

- 取消報名流程（Dashboard 入口）：
  - 新增頁面 `enrol_cancel.php` 作為 dashboard 的取消報名入口。
  - 後端仍呼叫既有 `enrolment_manager::cancel()`，維持：
    - 必填取消原因
    - `other` 時必填補充文字

- `/local/tm_course/index.php` 頁面結構調整：
  - 保留 `月視圖`、`開課場次`
  - 移除中間 `搜尋紀錄` 區塊（該功能改由 dashboard 的「搜尋報名紀錄」按鈕導向獨立頁）。

## 16. Dashboard 樣式一致性補充（2026-04-10）

- Dashboard 按鈕視覺規則（含前次功能）：
  - 一般功能按鈕（探索、搜尋、管理入口、位置控制、顯示控制）統一採：
    - 預設灰底
    - hover/focus 轉為 TM 綠色
  - `取消報名` 按鈕：
    - 預設紅色
    - hover/focus 仍沿用與其他按鈕一致的 TM 綠互動效果

- Dashboard 已納入規格的完整範圍（彙整）：
  - 位置可切換（首頁標題下 / 網站公告後 / 主內容底部）
  - 可由 site admin 隱藏/顯示給一般用戶
  - 一般用戶入口：探索課程、搜尋報名紀錄
  - admin 專用入口：場次管理、教室管理、課程連動設定、報名審核
  - 即將到來課程卡片顯示：開課時間、地點、桌次、葷素摘要
  - 即將到來課程卡片操作：取消報名、變更葷素習慣

## 17. 報名時段衝突防呆（2026-04-10）

- 規則目的：
  - 同一位學員在同一時間不可同時出現在兩堂課（即使教室不同也不允許）。

- 比對範圍：
  - 使用者發起報名時，系統必須檢查「目標場次」是否與該使用者「已核准（approved）」的其他場次時間重疊。
  - 同一天但時段不重疊可正常報名。

- 判斷條件（時間重疊）：
  - `existing.start < target.end` 且 `existing.end > target.start` 視為衝突。

- 生效節點：
  - 一般前台報名流程（`enrolment_manager::enrol()`）必檢。
  - 核准動作（`enrolment_manager::approve()`）再次檢查，避免 pending 場次在核准時造成重疊。
  - 批次加入 pending（`enrol_one_pending_batch()`）同樣執行相同檢查，保持資料一致性。

- 衝突處理：
  - 一旦偵測衝突，立即阻止報名/核准。
  - 需回傳可讀錯誤訊息，並帶出衝突場次資訊（場次名稱與時間）供使用者判斷。

## 18. 專屬開班預約系統（2026-04-10）

### 18.1 權限與准入規範（Permissions）

- 開放對象：
  - 系統管理員（`is_siteadmin()`）
  - 具業務批次報名資格使用者（`permissions_manager::user_can_batch_enrol()` 為 true）

- 入口位置：
  - 業務 Dashboard 新增 `專屬開班預約` 按鈕。

- 前置注意事項（Disclaimer Modal）：
  - 進入流程前必須強制彈出注意事項視窗。
  - 文案需可於外掛設定頁維護（configurable string/content）。
  - 必須勾選「我已了解並同意」才可進入下一步。

### 18.2 申請階段：資料填寫（Application Form）

- 視覺結構：
  - 採區塊化設計（Cards），分為：
    - `課程配置`
    - `時段排程`
    - `學員名單`

- 課程來源與複選：
  - 僅限已設定 `total_hours` 之課程。
  - 課程選擇器採 Tagging 多選組件（支援以 Pills 顯示已選課程並可移除）。

- 授課型態規則：
  - 實體：
    - 固定開始時間 `09:30`。
  - 視訊：
    - 必填 `視訊連結`。
    - 開放自訂開始時間。

- 移除教室選擇（業務端）：
  - 業務端不需選擇教室。
  - **實體**：申請表可選「希望上課教室」（限所選課程適用教室交集）；月曆預排依 §22、`allowed_classroomids`。
  - **視訊**：申請表教室欄停用；月曆預排教室由管理員在課程連動設定 **`online_classroomid`**，詳見 **§47**。

- 學員資料整合：
  - 必須 100% 複用既有 `批次報名（Batch Enrol）` 功能模組，包含：
    - Excel 檔案解析
    - 群組挑選
    - 資料初步驗證

- 期望日期限制：
  - DatePicker 封鎖今日起 14 天內日期。

### 18.3 互動導覽與智能月曆（Onboarding & Interactive Calendar）

- 操作引導小視窗（Onboarding Guide）：
  - 僅在「新申請案」第一次進入月曆頁面時顯示。
  - 說明需包含：
    - 可拖曳 Block 調整時段
    - 系統會自動避開衝突

- 同日複數堆疊邏輯（Stacking Logic）：
  - 系統需檢索目標教室可用空檔。
  - 若同日已有場次，新 Block 預設吸附至既有場次結束後空檔（含 1h 午餐規則）。
  - 時長依課程 `total_hours` 與每日上限 `7h` 自動切分跨日。

- 月曆操作規範：
  - 視覺：
    - 沿用 M6 實心卡片
    - 本案 Block：Techman Blue `#005f7e`
    - 既有場次：淺灰底
  - 衝突阻斷：
    - 拖曳重疊既有場次時，Block 需轉紅並回彈
    - 顯示提示：`此時段教室已被佔用`

### 18.4 審核與管理流程（Admin & Review）

- 業務端：
  - 可於 Dashboard 查看申請狀態：
    - 待審核
    - 已通過
    - 已駁回
  - 可查看管理員備註。

- 管理端：
  - 場次管理新增 `客製需求審核` 分頁。
  - 管理員可在月曆介面微調時段。
  - 核准後需自動：
    - 建立 `local_tm_course_sessions` 正式場次
    - 觸發既有 M5 通知流程

### 18.5 技術合規要求（Technical Compliance）

- 模組相依性：
  - 確保申請頁面能正確加載 `batch_enrol_manager` 類別。
  - 避免學員資料處理邏輯與主系統脫節。

- 自動導航：
  - 點擊 `送出` 後，應直接進入「智能月曆互動」階段。
  - 需自動將複選課程按時數堆疊排入目標教室。

## 19. 已實作更新（2026-04-10，Phase 1）

- 專屬開班預約（基礎）已完成：
  - Dashboard 新增 `專屬開班預約` 入口按鈕（admin + 業務權限可見）
  - 左側導航新增 `專屬開班預約` 項目（業務權限可見）
  - 新增預留頁：`/local/tm_course/reservation/index.php`（Phase 1 placeholder）

- 外掛設定已新增：
  - `reservation_disclaimer_text`（可編輯前置注意事項文案）

- 資料模型（DB）已新增：
  - `local_tm_course_reservation`（申請主檔）
  - `local_tm_course_resv_learner`（申請學員明細）
  - `install.xml` 與 `upgrade.php` 已同步，支援新裝與升級

## 20. 已實作更新（2026-04-10，Phase 2）

- 前置注意事項（Disclaimer Gate）：
  - 進入 `reservation/index.php` 後，未同意者會先看到強制 modal。
  - 必須勾選「我已了解並同意」才可進入申請表單。
  - 文案來源：`local_tm_course/reservation_disclaimer_text` 設定（無值時使用預設文案）。

- 申請表單（初版）：
  - 課程下拉僅顯示 `course_mapping` 且 `default_duration_hours > 0` 的課程。
  - 授課型態支援 `實體 / 視訊`：
    - 實體：開始時間固定 `09:30`，需選擇教室。
    - 視訊：可自訂開始時間，教室欄位停用。
  - 目前可提交建立 reservation 主檔（`pending` 狀態），供後續 Phase 3/4 擴充。

- 權限與入口延續：
  - Dashboard `專屬開班預約` 按鈕維持 admin + 業務權限顯示。
  - 左側導航 `專屬開班預約` 維持業務權限可見。

## 21. 已實作更新（2026-04-10，Reservation 擴充）

- 申請頁面欄位擴充：
  - 課程改為可複選（同次申請可選多門課）。
  - 視訊模式新增 `視訊連結` 欄位，且切換視訊後可編輯 `期望開始時間`。

- 教室處理改為系統導向：
  - 申請頁已移除「期望教室」輸入。
  - 教室將在後續智能月曆階段依課程屬性自動導向與排程。

- 學員資料來源區塊：
  - 申請頁整合批次風格名單輸入區（手動批次列）。
  - 學員名單改為「非必填」：允許先送出申請，後續階段再補齊名單。
  - 若有填寫手動名單，後端仍會執行基本驗證並寫入 `local_tm_course_resv_learner`。

- 提交流程：
  - 送出申請後直接導向 `reservation/calendar.php`（智能月曆互動入口）。

- 資料結構補充：
  - `local_tm_enabled_courses.allowed_classroomids`：課程可用教室清單（CSV）。
  - `local_tm_course_reservation` 新增：
    - `courseids_json`
    - `preferred_meeting_link`
    - `learner_source`
    - `cohortid`
    - `excel_filename`

## 22. 已實作更新（2026-04-10，Phase 3 月曆互動與排課防呆）

- 申請流程改為「先確認、再進月曆」：
  - `reservation/index.php` 改為兩段式：
    - 第一步 `submit_request`：做欄位驗證並顯示 Summary。
    - 第二步 `confirm_request`：建立 reservation 主檔，導向 `reservation/calendar.php`。
  - 移除「尚未完成月曆排程就顯示申請已送出」的舊訊息時機，避免流程誤導。

- 日期限制（申請頁）：
  - `期望開課日期` 前端 DatePicker 設定 `min = 今日 + 14 天`。
  - 後端同時驗證，不允許送出小於 `今日 + 14 天` 的日期。

- 月曆互動基礎（`reservation/calendar.php`）：
  - 頁面標題更新為 `課程日期選定`。
  - 月曆可顯示：
    - 既有場次（灰色）
    - 本次申請預排（藍色）
  - 若 plan API 未回傳預排，前端 fallback 仍會依「已選課程數量」建立對應預排 block，避免無法操作。

- 週切換與草稿保留：
  - 月曆 `<`、`>` 改為每次固定推進/退回 7 天（週切換，不再整月跳動）。
  - 使用者拖曳後的草稿位置會於週切換後保留，不會因重新載入事件而重置。

- 排課防呆（假日、衝突、單日上限）：
  - 禁止拖曳到週六、週日；若拖入假日立即回彈。
  - 同教室時段衝突檢查：
    - 拖曳時檢查目標時段是否與同教室既有/預排事件重疊。
    - 若衝突則禁止並回彈。
  - 預排產生（`reservation/plan_events.php`）亦套用同教室占用檢查，避免初始預排就出現重疊。
  - 浮動課程時間（同日自動接續）：
    - 拖曳課程後，系統會在同日同教室內尋找下一個可用空檔自動調整起訖時間。
    - 每次拖曳均讀取既有設定 `physical_daily_limit` 作為當日總時數上限。
    - 若當日剩餘可用時數不足，則回彈並提示改排其他日期。

  - 相容性補強：
    - 移除 `array_key_first()` 依賴，改為舊版 PHP 相容寫法（`reset()` + `key()`），避免低版本環境 fatal error。

- 同一次申請內「學員不可分身」：
  - 同一筆 `local_tm_course_reservation` 所產生的所有預排 block，**時間區間不得互相重疊**（不論是否同一教室）。
  - 後端 `reservation/plan_events.php` 產生預排時，除教室占用外，會同步檢查與同申請已排區間是否重疊；若有則往後推到下一個可排工作日再計算。
  - 前端拖曳浮動排程時，同日空檔計算會納入「其他預排 block」與「同教室既有場次」，避免與同申請其他課程時段重疊。

- 初始預排與拖曳一致：
  - 首次載入預排時即套用與拖曳相同的教室占用規則，避免「先可排入、拖出後再拉回才被擋」的不一致。
  - 前端 fallback（plan API 例外時）亦採用與後端相同之占用與同申請不重疊邏輯。

## 23. 已實作更新（2026-04-16，Phase 4：引導視窗與排程送出）

- **首次進入月曆引導（§18.3）**：
  - 新申請第一次開啟 `reservation/calendar.php` 時顯示說明視窗（拖曳藍色預排、系統避開衝突等）。
  - 使用者按下「我知道了」後寫入 `calendar_onboarding_seen = 1`，同一申請再次進入不再顯示。

- **排程持久化與送出（§18.4 基礎）**：
  - `local_tm_course_reservation` 新增：`calendar_plan_json`（預排 JSON）、`calendar_onboarding_seen`、`calendar_plan_submitted`。
  - 「確認排程並送出」經 `reservation/save_calendar_plan.php` 驗證後儲存：申請仍為 `pending`，`calendar_plan_submitted = 1`，成功後導回 `reservation/index.php` 並顯示成功訊息。
  - 後端驗證（`classes/reservation_plan_validator.php`）：課程與區塊對應、假日、實體/視訊教室規則、同申請內時段不重疊、實體教室與既有 `local_tm_course_sessions` 不衝突。
  - 若資料庫已有 `calendar_plan_json`，月曆優先載入已存預排（略過 plan API），以便接續調整與重送。

- **預排 API**：`plan_events.php` 與前端 fallback 之 `extendedProps` 新增 `courseId`，供送出與驗證對應課程。

- **月曆返回修改（申請資料回填）**：
  - `reservation/calendar.php` 新增「返回修改資料」按鈕，連回 `reservation/index.php?editrid={reservationid}`。
  - `reservation/index.php` 支援 `editrid` 回填模式：自動帶回已選課程、授課型態、授課語言、期望日期/時間與手動學員名單，供業務回到前頁修正後重新送出。

### Phase 4 建議測試

1. 升級外掛後確認資料表新欄位存在；新裝由 `install.xml` 建立。
2. 新申請 → 進月曆：應看到引導視窗一次；重新整理或再進同申請，應不再出現。
3. 拖曳預排後按「確認排程並送出」：應導向申請首頁並顯示成功通知；資料庫 `calendar_plan_json` 有內容且 `calendar_plan_submitted = 1`。
4. 故意製造與既有場次重疊的預排並送出：應顯示錯誤訊息且不寫入成功。
5. 再次從 Dashboard 進入同一申請月曆：應載入已儲存預排。
6. 月曆按「返回修改資料」：應回到申請頁且原資料已回填（課程/授課型態/語言/日期時間/手動名單）。
7. **下一階段（Phase 5）候選**：管理端「客製需求審核」分頁、核准後自動建立 `local_tm_course_sessions` 與通知、業務 Dashboard 申請狀態列表。

## 24. 已實作更新（2026-04-16，Phase A+B：授課型態先選、課程連動分流）

- 課程連動設定（`settings/course_mapping.php`）新增：
  - `開放實體`、`開放視訊` 勾選欄位（每門課可獨立開關）。
  - `實體時數（小時）`、`視訊時數（小時）`，保留原 `預設課時` 欄位相容既有流程。

- 資料模型（`local_tm_enabled_courses`）新增欄位：
  - `allow_onsite`、`allow_online`
  - `default_duration_hours_onsite`、`default_duration_hours_online`
  - 升級版號 `2026040843`，舊資料會以既有 `default_duration_hours` 回填兩種授課型態時數。
  - **（2026-05-17 續，詳見 §47）** `online_classroomid`：視訊專屬開班預排教室（與 `allowed_classroomids` 分離）。

- 申請頁（`reservation/index.php`）流程調整：
  - 授課型態改為必填，且優先決定可選課程清單。
  - 課程勾選區會依授課型態即時過濾（只顯示該型態有開放的課程）。
  - 後端驗證同步限制：若所選課程未開放該授課型態，禁止送出。

- 預排時數來源分流：
  - 預排與 fallback 計算時數改依授課型態讀取對應時數（onsite / online）。

## 25. 已實作更新（2026-04-16，Phase C+D 續作：拖曳自動接續 + 可設定視訊最晚結束時間）

- 月曆拖曳自動接續（單一課程 / 視訊分段群組）：
  - 當課程拖曳到目標日期時，系統先以該申請的 `期望開始時間` 作為該日嘗試起點。
  - 若目標日為空檔，block 會回到該 `期望開始時間`（而不是沿用上次拖曳後時間）。
  - 若目標日有衝突（同申請其他課程時段或同教室既有場次），系統會自動往後吸附到當日最後可用時段後方。
  - 若自動接續後超過每日上限，拖曳會回彈並提示錯誤。

- 視訊跨日連續課程（group drag）：
  - 拖曳群組時，會以「連續工作日」重排所有分段。
  - 群組第一段的起始時間同樣先回到申請 `期望開始時間`；若當日衝突則自動接續。
  - 只要任一分段無法合法放置（週末、衝突、超時），整組回彈（全有或全無）。

- 視訊最晚上課時間改為系統設定：
  - 管理頁 `settings.php` 新增 `online_day_end_time`（預設 `22:30`，30 分鐘粒度）。
  - 月曆預排（`plan_events.php`）、前端拖曳判斷（`calendar.php`）、送出驗證（`reservation_plan_validator.php`）統一讀取此設定，不再硬編碼 22:30。
  - 錯誤訊息文案改為「超過目前設定的最晚結束時間」，避免與固定值綁死。

- 事件資料補強：
  - `extendedProps` 新增 `preferredStartHm`，確保拖曳空白日回復到申請期望時間時有穩定基準。

### 25.1 回歸測試建議（本輪）

1. 後台調整 `online_day_end_time`（例如 21:30）後，確認預排與拖曳都遵守新上限。
2. 拖曳到空白工作日：應回到申請期望時間。
3. 拖曳到有既有課程同日：應自動接到最後可用時段後方（不重疊）。
4. 單日加總超過上限：應回彈並提示。
5. 視訊跨日群組拖曳：應整組移動到連續工作日；任一段不合法時整組回彈。

## 26. 已實作更新（2026-04-16，審核模式月曆編輯）

- 系統管理員可直接編輯業務端已提交申請的「課程日期選定」：
  - 進入同一月視圖頁 `reservation/calendar.php?id={rid}&review=1` 後，可再次拖曳調整 block。
  - 儲存動作沿用既有 `save_calendar_plan.php` 驗證與持久化流程。

- 介面角色區分（避免業務端/管理端混淆）：
  - 管理員審核模式標題顯示為：`課程日期選定（系統管理員審核模式）`。
  - 管理員審核模式顯示專用提示訊息，明確說明目前為審核調整畫面。
  - 送出按鈕文案改為：`儲存審核排程調整`（非業務端「確認排程並送出」）。
  - 管理員審核模式不顯示「返回修改資料」按鈕，降低與業務申請流程混淆。

- 管理員審核模式體驗：
  - 不顯示首次 onboarding modal。
  - 儲存成功後停留在審核模式頁面並顯示成功提示，便於連續調整與審核。

## 27. 已實作更新（2026-04-16，Phase 5：報名與客製班整合審核）

- 審核入口整合為單一頁面：
  - 新增 `admin/review_center.php`，入口按鈕/導航文案統一為：`報名與客製班審核`。
  - 頁面內分兩個區塊：
    1. 既有課程報名審核（列出場次與待審數，導向既有 `admin/enrolments.php` 明細審核）
    2. 客製需求審核（待審/已核准/已駁回）

- 客製需求審核動作 + 備註：
  - 每筆客製需求可執行：
    - `儲存備註`
    - `核准客製需求`
    - `駁回客製需求`
  - 核准/駁回會更新 `local_tm_course_reservation.status` 與 `manager_note`。
  - 提供 `編輯課程日期選定` 按鈕，連到管理員審核月曆模式（`calendar.php?id={rid}&review=1`）。

- 核准後正式建場（Phase 5 核心）：
  - 核准時會讀取 `calendar_plan_json`，逐段建立正式 `local_tm_course_sessions`。
  - 會帶入授課型態、授課語言、時段、教室、容量等欄位。
  - 若客製申請有可對應 `userid` 的學員，會將學員批次寫入新場次為 `pending` 報名。
  - 流程採交易（transaction）：任一區段失敗則整筆核准不落地。

- 通知：
  - 客製需求核准/駁回後，系統會發送站內訊息給申請者（含審核備註）。

- 導航與 Dashboard：
  - 管理入口（settings/nav/frontpage dashboard）審核按鈕改導向 `admin/review_center.php`。

## 28. 已實作更新（2026-04-16，Phase 5 修正：待審預設 + 申請追蹤）

- 審核中心 filter 行為修正：
  - `既有課程報名審核` 與 `客製需求審核` 皆加入狀態 filter。
  - 兩區塊預設皆為 `待審`，避免管理員誤操作已核准/已駁回資料。

- 審核備註流程修正：
  - `action` 解析改為寬鬆安全白名單判斷（支援 `resv_note` / `resv_approve` / `resv_reject`）。
  - 備註儲存後會保留當前篩選條件並顯示成功訊息。
  - 待審狀態下可立即看到目前備註內容。

- 業務端新增「申請追蹤」：
  - 新增頁面 `reservation/tracking.php`，分兩區塊顯示：
    1. 批次報名追蹤（待審/核准/駁回統計）
    2. 客製班申請追蹤（申請時間、狀態、最近月曆更新）
  - `reservation/index.php` 新增「查看申請追蹤」按鈕。
  - Dashboard 也新增 `申請追蹤` 快速入口按鈕（業務/管理員可見）。

## 29. 已實作更新（2026-04-16，Phase 5 修正：追蹤明細與語系防呆）

- 業務端追蹤清單 drill-down：
  - `reservation/tracking.php` 兩個清單（批次報名、客製班申請）每列新增 `查看明細`。
  - 新增 `reservation/tracking_detail.php`：
    - `type=batch`：顯示該批次場次的學員審核狀態、審核結果備註、更新時間。
    - `type=custom`：顯示當時申請內容、審核狀態、管理員備註、提交的排程區塊、核准後建立的正式場次。

- 追蹤與審核頁語系 fallback：
  - `review_center.php`、`tracking.php`、`tracking_detail.php` 對新增字串統一採 `string_exists` fallback。
  - 若站點語系檔尚未完整同步，不再因 `get_string` 缺 key 造成整頁 Debug 亂碼。

- 通知穩定性：
  - 客製審核核准/駁回通知改為站內通知優先（`emailstop=1`），避免 email processor 在環境未配置時干擾審核流程。

## 30. 已實作更新（2026-04-16，清單欄位可點排序）

- 管理端/業務端清單表格支援表頭點擊排序：
  - 第一次點擊：降冪
  - 再點一次：升冪
  - 切換欄位時會重置其他欄位排序狀態

- 目前已套用欄位：
  - `admin/review_center.php`
    - 既有課程報名審核：`開始`
    - 客製需求審核：`申請時間`、`最近月曆更新`
  - `reservation/tracking.php`
    - 批次報名追蹤：`開始`
    - 客製班申請追蹤：`申請時間`、`最近月曆更新`

- 實作方式：
  - 欄位使用 `data-sortable` 標記可排序。
  - 日期/時間欄位使用 `data-sort-value`（timestamp）避免字串排序誤差。
  - 樣式於 `styles.css` 新增排序箭頭與互動提示。

## 31. 已實作更新（2026-04-16，Dashboard 擴充與場次批次刪除）

- 場次管理（`admin/sessions.php`）：
  - 新增系統管理員工具列按鈕：
    - `批次選定`
    - `刪除場次`
  - 支援勾選多個場次後一次刪除，送出前二次確認，並顯示刪除成功筆數。

- Dashboard 主標題與命名：
  - 所有「學習導航中心 / Learning navigation center」用詞改為：
    - `實體/線上課程報名系統`
    - `Onsite/Online Course Registration System`
  - Dashboard 主標題與 block 標題字體放大（約 2x）。

- Dashboard 新區塊（業務與管理端可見）：
  - 新增 `近期的開班申請`
    - 來源：`local_tm_course_reservation`（以 `timemodified/timecreated` 最新排序）
    - 最多顯示 5 筆
    - 提供明細連結至 `reservation/tracking_detail.php?type=custom&id=RID`
  - 新增 `近期的批次報名`
    - 來源：`local_tm_course_enrolments.batch_submittedby`
    - 以該批次最新狀態更新時間排序
    - 最多顯示 5 筆
    - 提供明細連結至 `reservation/tracking_detail.php?type=batch&id=SESSIONID`

- Dashboard 月視圖（Frontpage Dashboard）：
  - 新增月視圖區塊（使用 FullCalendar + `calendar_events.php`）。
  - 位置規則：
    - 一般使用者：在 `審核中的課程` 後方。
    - 業務/管理端：在 `近期的批次報名` 後方。

- 系統管理員控制 Dashboard 顯示內容：
  - 既有控制保留（整體顯示/隱藏、位置）。
  - 新增可逐項切換顯示：
    - `即將到來的課程`
    - `審核中的課程`
    - `近期的開班申請`
    - `近期的批次報名`
    - `課程月視圖`
  - 控制入口沿用 `dashboard_control.php`，由 site admin + sesskey 驗證。

## 32. 已實作更新（2026-04-16，Dashboard 集中設定頁）

- 新增後台設定頁：
  - 位置：`Site administration -> Plugins -> local_tm_course -> Dashboard 顯示設定`
  - 檔案：`settings.php`

- 可集中管理的設定項目：
  - `啟用首頁 Dashboard`（front_dashboard_visible）
  - `首頁 Dashboard 位置`（front_dashboard_position）
    - 首頁標題下 / 網站公告後 / 主內容底部
  - 區塊顯示開關：
    - `即將到來的課程`
    - `審核中的課程`
    - `近期的開班申請`
    - `近期的批次報名`
    - `課程月視圖`

- 管理原則：
  - 由系統管理員統一控制，所有使用者 dashboard 顯示內容依此設定生效。
  - 既有前台 quick toggle 仍可用；集中設定頁為主要維運入口。

## 33. 已實作更新（2026-04-16，Dashboard 角色化顯示與版面收斂）

- Dashboard 顯示設定改為「依角色獨立控制」：
  - 系統管理員可分別設定三組角色的區塊顯示：
    - 一般使用者（user）
    - 業務端（sales）
    - 系統管理員（admin）
  - 每組可獨立控制：
    - `即將到來的課程`
    - `審核中的課程`
    - `近期的開班申請`
    - `近期的批次報名`
    - `課程月視圖`
  - 讀取優先序：
    - 先讀角色專屬 key（`dashboard_widget_{role}_{widget}`）
    - 若缺值再回退舊全域 key（`dashboard_widget_{widget}`）以相容既有部署

- 業務端 dashboard 清單上限調整：
  - `近期的開班申請`、`近期的批次報名` 顯示上限由 5 改為 3（僅業務端）。
  - 一般使用者/系統管理員維持 5。

- Dashboard 卡片版面調整（避免頁面過長）：
  - `近期的開班申請`、`近期的批次報名`改為單排格線展示（最多 5 欄），避免垂直長列表。
  - 行動版 RWD 會自動改為單欄。

- Dashboard 設定頁語系穩定性：
  - `settings.php` 的 Dashboard 設定標題與描述採 `string_exists + fallback` 取得，避免語系 key 尚未同步時噴 `Invalid get_string()` debug。

## 34. 已實作更新（2026-04-21，出缺席整合穩定性與點名同步）

- 視訊課程與桌次：
  - 當 `delivery_mode = online` 時，前台/管理端/審核頁不再顯示或依賴桌次指派。
  - `approve()` 對視訊場次不要求桌次，實體場次桌次流程維持原規格。

- 批次報名備註：
  - 批次報名新增「批次備註」欄位，儲存在 `local_tm_course_enrolments.batch_submitter_note`。
  - 審核名單可見「批次備註」，供管理者參考。
  - 送出前 debrief modal 顯示備註預覽。

- 搜尋紀錄（業務可見範圍）：
  - `search.php` 可見範圍擴充為：
    - 具 `local/tm_course:viewall`，或
    - `permissions_manager::user_can_batch_enrol()` 為 true（業務）。
  - 業務可查所有報名紀錄，不再僅限 `e.userid = 當前登入者`。

- 出缺席活動建立與沿用規則：
  - 場次建立/更新與點名前皆會確保 attendance 資源存在（group/activity/session slot）。
  - 優先沿用：
    1. 場次已綁定 `attendance_cmid`
    2. 名稱為 `課程出缺席` 的活動
    3. 否則新建
  - 不再任意沿用同課程其他 attendance 活動，避免寫入錯目標。

- 出缺席活動設定預設：
  - 自動建立 activity 時，`course_modules.groupmode = 1`（分隔群組）。
  - 自動建立狀態集保留三種語意：`Present` / `Late` / `Absent`（description）。
  - acronym 依 DB 欄位限制做安全寫入，避免 `acronym` 欄位長度造成建立失敗。

- 點名同步（外掛 -> mod_attendance）：
  - 每次點名前會先 `setup_session()`，並回補同場次既有已標記資料（backfill）。
  - slot 對應採「同 attendance 活動 + 同日 + 同場次名稱」優先，避免同日多時段誤寫。
  - 若場次綁定 slot 遺失，會自動回查/重建並再同步。
  - 寫入 `attendance_log` 時同步帶入 `statusset`，提高 take 頁可見一致性。

- 管理端快捷入口：
  - `admin/attendance.php` 新增「開啟 Moodle 出缺席點名頁」按鈕，連結帶齊 `id`、`sessionid`、`grouptype=0`。
  - 避免 `take.php` 缺參數（`sessionid`/`grouptype`）錯誤。

- 站台噪音防護（不阻斷主流程）：
  - completion 與 attendance setup 的非關鍵訊息不再輸出到頁面造成 redirect 中斷。
  - 保留伺服器端記錄（`error_log`）供維運追查。

## 35. 已實作更新（2026-04-22，課前資料檢核流程上線）

- 新增「課前資料檢核」階段（專屬開班預約流程）：
  - 流程改為：
    - `基本資料 (1/3)` → `月曆編排 (2/3)` → `課前資料檢核 (3/3)`
  - 月曆頁送出按鈕改為「下一步」語意，不再誤導為流程結束。
  - 第三步「課前資料檢核」定位為**可先略過、後續補件**：
    - 當下可不填/不傳附件，仍可提出申請。
    - 是否補齊檢核資料，不得阻斷「提出申請」與「進入審核」。
  - 最終提交點為 `reservation/verification.php`，提交後設 `calendar_plan_submitted = 1`；
    - 此旗標僅代表申請已送出，不代表檢核資料已完整。

- 檢核題目來源與分群：
  - 題目依本次申請的「實際選課」與「授課型態（onsite/online/both）」載入。
  - 業務端顯示以「課程」分群，題目可明確對應到來源課程。

- 管理端檢核設定：
  - `settings/course_mapping.php` 每門課新增「⚙️ 檢核設定」按鈕。
  - 可維護題目文字、適用型態、是否必填、排序。

- 管理端檢核審核頁：
  - `admin/reservation_verification_review.php`
  - 圖片：縮圖顯示，可點擊新分頁開原圖。
  - 非圖片（Word/PDF 等）：提供下載。
  - 每個檔案可獨立標記：`通過` / `未通過`（逐檔審核）。
  - 頂部新增「檢核項目總覽表」統整所有題目的審核狀態。
  - 審核狀態持久化（離開再回來可續審，不會歸零）。

- 審核中心清單顯示策略：
  - `admin/review_center.php` 不再在清單列展開逐題附件，改由「課前資料檢核」按鈕進入專頁檢視。
  - 按鈕顯示條件：
    - `calendar_plan_submitted = 1`。

- 業務端追蹤明細（申請明細）：
  - `reservation/tracking_detail.php?type=custom`
  - 新增「課前資料檢核結果」區塊，顯示：
    - 題目
    - 目前檔案（圖片可直接看、文件可下載）
    - 審核狀態（待審核/通過/未通過）
  - 支援在同一筆申請明細直接重傳單題檔案（不需重開新申請）。
  - 業務可於送出申請後，在追蹤明細持續補件，不受申請狀態切換阻斷（除非該申請已結案或取消）。
  - 重傳後該題審核狀態自動重置為「待審核」。

- 客製需求審核備註可追蹤與可再編輯：
  - 核准/駁回時若備註欄空白，不覆蓋既有備註（避免誤清空）。
  - 管理員在已核准/已駁回狀態仍可修改備註並儲存。
  - 業務端「申請明細」固定顯示最新審核備註。

## 36. 已實作更新（2026-04-22，資料模型與相容性）

- `local_tm_course_vq_file` 擴充審核欄位：
  - `review_status`（0 待審核 / 1 通過 / 2 未通過）
  - `review_note`
  - `reviewedby`
  - `timereviewed`
- 升級版號：
  - `2026042103`：審核中心附件查詢穩定化、檢核專頁
  - `2026042104`：逐檔審核狀態欄位與業務端重傳

- Pluginfile 行為調整：
  - 不再強制 `forcedownload=true`，依呼叫參數決定；
  - 使圖片可 inline 預覽、文件仍可下載。

- 語系穩定策略：
  - 關鍵新頁（尤其審核/追蹤）採 `string_exists + fallback`，避免因語系檔未同步造成整頁 debug 亂碼。

## 37. 已實作更新（2026-04-27，通知中心與審核流程修正）

- 通知中心升級（M5）：
  - 新增獨立通知設定頁：`settings/notifications.php`。
  - 每個通知情境可分別設定：主旨模板、內文模板、預設收件者、額外系統角色收件者。
  - 通知頁改為 block + accordion：預設收合、一次只展開一個分區。

- 通知通道與穩定性：
  - 實作為「站內通知 + Email」雙通道。
  - 避免 email processor 錯誤中斷審核頁 redirect：站內通知與 Email 發送採分離處理。
  - 針對同事件收件者去重，避免同一人收到重複信件。

- 通知情境擴充：
  - 新增「新報名通知」觸發（學員提交報名時）。
  - 新增「取消報名通知」觸發（學員取消報名時）。
  - 客製需求核准/駁回通知改統一走 `notification_helper`。

- 客製審核與排程核准修正：
  - `save_calendar_plan.php` 儲存排程時同步寫入 `calendar_plan_submitted = 1`。
  - 核准客製需求時增加相容補救：若旗標未更新但 `calendar_plan_json` 已存在，仍允許核准並補寫旗標。
  - 修正同申請同日多課建立：保留「同教室同時段不可重疊」，放寬「同教室同日不可多堂」限制於客製核准建場路徑。

- 其他穩定性修正：
  - 修正審核中心核准時 `get_records()` 首欄非唯一導致的 debug 輸出（duplicate userid）。
  - 修正客製需求核准成功訊息參數替換（`{$a}` 正確帶入建立場次數）。

## 38. 已實作更新（2026-04-27，搜尋權限重構 + 我的紀錄 + 證書整合）

- 權限命名與入口調整：
  - `權限規則（批次報名）` 文案統一更名為 `權限設定`（導航、頁面標題、語系文案同步）。
  - `搜尋紀錄` 不再開放一般使用者；僅下列角色可使用：
    - 系統管理權限（`local/tm_course:manage`）
    - 或命中批次報名規則（`permissions_manager::user_can_batch_enrol()`）。

- 導覽與 Dashboard 入口調整：
  - 左側主選單新增並固定顯示 `我的上課與報名紀錄`（所有登入使用者可見，含管理者/業務）。
  - **`搜尋紀錄` 不顯示於左側導覽**（避免與「僅管理員可見左欄管理項」之站台慣例衝突；業務改由首頁 Dashboard 進入）。
  - 首頁 Dashboard 按鈕列：`我的上課與報名紀錄` 為全員；另當 `permissions_manager::user_can_batch_enrol()` 為 true 時，顯示 **`搜尋紀錄`** 按鈕（連至 `/local/tm_course/search.php`）。
  - Dashboard 原 `搜尋報名紀錄` 按鈕已改為 `我的上課與報名紀錄`（舊 spec §14 敘述以此為準）。

- 新增「我的上課與報名紀錄」頁（`my_records.php`）：
  - 保留既有兩個清單：
    - `課程紀錄`
    - `報名紀錄`
  - 兩清單皆忠實顯示現況狀態，不因審核/點名結果隱藏資料。

- 證書整合（customcert）— **初版行為；後續以 §54 為準**：
  - 新增 helper：`classes/certificate_helper.php`，統一處理 customcert 查詢與下載 URL 生成。
  - 新增代理下載端點：`certificate_download.php`，用於「TM 搜尋權限可見，但 customcert 原生他人下載權限不足」場景。
  - 搜尋頁（`search.php`）證書區塊：初版僅查 `customcert_issues`；§54 起改為「已核發 + 可領取未核發」合併清單，下載行為等同代為觸發 customcert「View certificate」。

- 證書清單獨立化（依最新需求）：
  - `我的上課與報名紀錄` 新增第三區塊 `證書清單`，欄位固定：
    - `課程名稱`
    - `證書發放時間`
    - `下載證書`
  - `課程紀錄` 內原有證書按鈕已移除，避免與「課程層級發證」語意混淆。
  - 證書清單資料來源：§54 起與搜尋頁相同，合併 `customcert_issues` 已核發與「符合領取資格但尚未按 View certificate」之課程證書（依發放時間倒序，未核發列時間顯示 `—`）。

- 資料查詢穩定性：
  - 修正 customcert issue 查詢在「同課程多筆證書」時的 `get_record_sql` 例外：
    - 改為 `get_records_sql(..., 0, 1)` 取最新一筆（單筆需求場景）。
  - 新增 `enrolment_manager::get_user_records($userid)`，供 `my_records.php` 穩定取個人全量紀錄。
  - §54 補強：證書候選查詢改 `get_recordset_sql` + `userid:courseid:customcertid` 去重，避免 `get_records_sql` 首欄重複觸發 debug。

- 語系與容錯策略（本輪強化）：
  - 新頁與新入口字串採 `string_exists + fallback` 策略，避免語系檔不同步造成 `Invalid get_string()` 直接中斷頁面。
  - 重點字串：`nav_my_records`、`dashboard_my_records`、`my_course_records`、`my_enrolment_records`、`my_certificate_records` 等。

## 39. 實務經驗與問題對策（文件分流）

- 使用者回報問題、根因與已驗證解法，集中維護於專案根目錄 **`lesson-learned.md`**。
- **`spec.md`** 描述應有行為與介面規格；**`lesson-learned.md`** 描述曾發生的缺陷與修復策略（含 Moodle 平台限制、部署語系、AJAX／FullCalendar、DB 命名等），避免同一教訓重複維護兩份。
- 開發與除錯時應優先查閱 **`lesson-learned.md`**（例如：`AJAX_SCRIPT` 須 `$PAGE->set_context()`、`get_records_sql` 第一欄須唯一、資料表名 ≤28 字元、FullCalendar `eventsSet` 內不可遞迴改事件、課前檢核與審核狀態顯示等）。

## 40. 已實作更新（2026-05-05，今日整合）

- 通知中心（M5）擴充：
  - 新增通知情境：`batch_enrol_completed`（某使用者完成批次報名、建立待審紀錄後觸發）。
  - 通知設定頁 `settings/notifications.php` 新增該情境區塊與副標說明。
  - 通知模板（繁中/英文）改為預設收合（`<details>`），避免同時展開所有語系內文。
  - 語系與 provider 同步：
    - `messageprovider:batch_enrol_completed`
    - `notify_event_batch_enrol_completed` / `_desc`

- 批次報名完成通知觸發：
  - `batch_enrol.php` 在 `batch_enrol_pending()` 成功且 `processed > 0` 時，呼叫 `notification_helper::notify_batch_enrol_completed(...)`。
  - 預設收件者：`approver` + `batch_submitter`（可於通知設定頁調整）。

- 申請追蹤明細（批次）欄位補強：
  - `reservation/tracking_detail.php?type=batch` 新增「桌次」欄。
  - 顯示規則：
    - 視訊場次：`—`
    - 實體且已核准且有桌次：顯示 `desk_assigned_to`
    - 其餘：`—`

- Dashboard 快捷入口分類（全站共用心智模型）：
  - 快捷入口改分組顯示（沿用既有配色風格）：
    - `學習與報名`
    - `申請與流程`
    - `營運與設定`
  - 角色差異僅影響可見群組/按鈕，不改分組語意本身。

- 專屬開班申請頁 UX 微調：
  - `reservation/index.php`：
    - `授課型態`、`授課語言` radio 選項間距加大（可讀性改善）。
    - 教室提示改為三態互斥：
      - 一般實體提示（交集過濾）
      - 維修課程提示（固定 TM HQ 維修教室，不可自選）
      - 視訊提示（不需教室）
  - 維修課程判斷沿用既有規則（課名含 `維修` / `maintenance`）。

- 專屬開班來源可追溯（A2 一次到位）：
  - `local_tm_course_sessions` 新增欄位：`source_reservation_id`（nullable）與索引 `idx_source_reservation`。
  - 核准客製建場時即寫入該欄位（`admin/review_center.php` -> `session_manager::create_session()`）。
  - 升級回填：舊場次若名稱符合 `| R{reservationid}-{n}`，自動補回 `source_reservation_id`。

- 月曆「我的專屬開班」識別（B~D）：
  - `calendar_events.php` 以 `source_reservation_id -> reservation.requesterid` 判斷：
    - `requesterid === current_user` 即 `ownDedicatedSession = true`（含管理員本人申請）。
  - `extendedProps` 新增：
    - `ownDedicatedSession`
    - `sourceReservationId`
    - `ownDedicatedSessionLabel`
    - `ownDedicatedBadge`
  - 前台月曆（`index.php`、Dashboard 月曆、`reservation/calendar.php`）同步套用：
    - 自己的班次：TM 綠
    - 其他班次：TM 藍
    - tooltip/卡片顯示「我的開班」語意與來源描述。

- 首頁月曆與開課清單連動：
  - `index.php`：
    - 當使用者在「即將開課 - 月視圖」切換月份（prev/next/today），下方「開課場次」清單同步只顯示該月份資料。
    - 若該月份無場次，顯示空清單提示。

- 清單篩選策略最終決策（本日結論）：
  - 曾短暫導入通用清單工具列（`list_controls.js`）供所有 `.tm-table` / `.tm-session-grid` 自動篩選排序。
  - 依需求回退：**移除通用工具列**，保留各頁既有的「頁面級正式篩選器（A）」。
  - 已刪除 `list_controls.js` 並移除所有掛載與樣式。

- 版本節點（本日主要）：
  - `2026050501`：批次完成通知 + 通知模板收合 + 批次明細桌次
  - `2026050502`：Dashboard 分組顯示
  - `2026050503`：專屬開班頁 radio 間距 + 維修教室提示
  - `2026050504`：`source_reservation_id` + 月曆 own session 標記
  - `2026050505`：首頁月曆 event title/aria 補強
  - `2026050506`：月曆自有班次顏色（TM 綠 vs TM 藍）
  - `2026050507`：首頁月曆切月同步開課清單
  - `2026050508`：通用清單工具列（後續回退）
  - `2026050509`：回退通用清單工具列，僅保留 A 類篩選器

## 41. 追加規格（2026-05-08，專屬開班檢核補件提醒）

- 核心原則（審核與補件解耦）：
  - 專屬開班申請是否可被管理員核准，理論上與「課前資料檢核是否補齊」無直接相依。
  - 檢核資料定位為風險提醒與作業追蹤，不作為系統硬性擋件條件。

- 業務端提醒（申請者）：
  - 在 `reservation/tracking.php` 與 `reservation/tracking_detail.php?type=custom` 顯示「檢核補件狀態」badge：
    - `未開始`（尚未上傳任何檔案）
    - `補件中`（部分題目有檔案但未齊）
    - `已補齊`（所有啟用題目皆有目前有效檔案）
  - 明細頁固定顯示提醒文案：`課程開始前 1 週需完成檢核資料，否則課程可能被取消。`
  - 申請送出後，業務仍可在追蹤明細持續補件，避免重新建立申請。

- 管理端提醒（系統管理員）：
  - 在 `admin/review_center.php` 的客製需求清單新增 `檢核狀態` 與 `開課倒數` 顯示。
  - 當任一核准後場次距離開始時間 <= 7 天，且檢核資料未補齊時：
    - 列上顯示高亮警示（warning / danger badge）。
    - 顯示提示：`檢核資料尚未補齊，已進入課前 1 週風險期（可取消課程）。`
  - 該提示屬於決策輔助，不強制系統自動取消；最終由管理員判斷是否取消。

- UI 呈現建議（本輪提案）：
  - 業務端採「溫和但持續」提醒：
    - 清單列 badge + 明細頁固定提示 + 補件 CTA（`前往補件`）。
  - 管理端採「時程風險」提醒：
    - 清單層級直接可見 `檢核狀態`、`剩餘天數`、`風險警示` 三元素，避免進明細才發現。
  - 色彩語意建議：
    - 灰：未開始；黃：補件中；綠：已補齊；紅：已進入 7 天風險且未補齊。

## 42. 已實作更新（2026-05-09，批次報名建帳整合）

- 批次報名區塊 B（完整資料）：
  - 當列資料同時具備 `姓名 + 機構 + Email` 時，送出批次報名會先做帳號處理：
    - Email 已存在：連動既有 Moodle 帳號。
    - Email 不存在：自動建立新 Moodle 帳號（manual auth），並標記首次登入需重設密碼。
  - 新建帳號後會發送「帳號建立成功通知」（站內 + Email），內容包含登入入口與忘記密碼入口。

- 批次報名區塊 B（資料不全）：
  - 若列資料未達 `姓名 + 機構 + Email`，系統不阻斷整批送出；該列自動回落為卡位 placeholder。
  - 回落列可於後續「申請追蹤明細」補齊資料後再完成連動/建帳。

- 卡位後補（追蹤明細）：
  - `reservation/tracking_detail.php?type=batch` 的後補區新增「名 / 姓 / 機構 / Email」欄位，供業務直接補件。
  - 補件送出時：
    - 若 Email 已存在：連動既有帳號。
    - 若 Email 不存在且補齊姓名與機構：自動建立新帳號並連動。
    - 若 Email 不存在但姓名/機構不足：保留待補狀態並提示需補齊資料。

- 追蹤狀態顯示：
  - 批次追蹤明細新增 `帳號狀態` 欄位，至少包含：
    - `卡位待補件`
    - `已記錄信箱（待建帳/綁定）`
    - `已連動/已建帳`

## 43. 已實作更新（2026-05-09，檢核逾期自動關閉）

- 自動關閉規則（排程）：
  - 新增排程任務：`close_incomplete_reservation_sessions`（每 30 分鐘）。
  - 針對 `source_reservation_id` 綁定的專屬開班場次，若符合：
    - 場次開始時間在未來 7 天內
    - 且檢核題目存在、但檔案仍未補齊
  - 系統自動將場次狀態改為 `已截止（STATUS_CLOSED）`。

- 規則邊界：
  - 若該申請沒有啟用任何檢核題目，視為不需檢核，不會因本規則被自動關閉。
  - 僅處理目前仍為 `OPEN/FULL` 的未開始場次；已截止或已開始場次不重複處理。

- 管理端可設定天數：
  - 後台 `settings.php` 新增設定 `reservation_verification_deadline_days`（預設 7）。
  - 自動關閉 task 會讀取此設定值，作為「距開課幾天內未補齊則關閉」的判斷門檻。

## 44. 已實作更新（2026-05-12，視訊課程手動切分時段）

- 規則目的：
  - 視訊客戶受時區限制無法配合台灣時間整天上課時，業務可在「專屬開班預約」申請表單，直接指定「每日最多上課時數」，由系統將每門課的總時數均分到連續工作日。
  - **不引入時區判斷**；切分純粹由業務人工指示，預設不切分，對不需要切分的申請零影響。

- UI（`reservation/index.php`，視訊型態才顯示）：
  - 新增欄位 `每日最多上課時數（小時）`（`online_daily_hours_limit`，name 同欄位名）。
  - **輸入單位限定為「整數小時」**（HTML `step=1`、`pattern=\d+`、後端正則 `/^\d+$/`）：
    - 業務口語多為「每天 4 小時」、「每天 6 小時」，不再開放分鐘級切分以避免誤填 `3.33`。
    - 系統 split 結果可能仍為非整數小時（例：8h ÷ 3 天 = 2.67h/天），這屬於均分演算法輸出，與輸入單位無關。
  - 留空或 `0` 表示不切分（沿用既有行為）。
  - 即時預覽：勾選課程或更動欄位時，畫面下方顯示每門課的切分試算（例 `課程 A：8h → 2 天 × 4.00h`）。
  - 切回實體時，欄位自動清空並停用，避免汙染。

- 後端切分邏輯（`reservation/plan_events.php`、`local_tm_course_build_online_blocks_average()`）：
  - 函式新增可選參數 `$forceddailyhours`：
    - `> 0`：days = `ceil(total_hours / forceddailyhours)`，每段 = `total_hours / days`（均分，沿用 §22 / §25 既有規則）。
    - `= 0`：行為與既有邏輯一致（以 `online_day_end_time` 反推每日可用時段）。
  - 多門課同申請：每門課**獨立套用**同一個每日上限，產生各自的 segments，最終依序排到連續工作日。

- 邊界與驗證（送出前 + 送出時）：
  - 欄位格式：**正整數小時**；非法值（含小數）回錯 `reservation_error_online_daily_hours_limit_format`。
  - 上限不可 `≥` 所選課程中時數最長的一門（否則切分無效；錯誤 `reservation_error_online_daily_hours_limit_too_large`，帶最長時數）。
  - 切分後仍受 `online_day_end_time` 硬性限制：若 `開始時間 + 每日上限 > 最晚結束時間`，送出阻擋（`reservation_error_online_daily_hours_limit_over_dayend`）。
  - 預排階段（plan API）與月曆驗證沿用既有規則：同教室不可重疊、同申請不可重疊、週末不可排，整組超時整組回彈。

- 月曆互動（`reservation/calendar.php`）：
  - 預排結果直接帶入正確段數；既有 group drag、同教室占用、weekend block、`online_day_end_time` 上限與審核者編輯（`review=1`）全部沿用。
  - 管理員審核模式**不需要**另開介面調整切分（業務送多少段、就審多少段，最簡單路線）。

- 編輯回填（`editrid` 模式）：
  - `reservation/index.php` 在回填現有申請時，若 `online_daily_hours_limit` 有值會回填到欄位，避免業務按「返回修改資料」後切分設定遺失。

- 資料模型：
  - `local_tm_course_reservation` 新增欄位 `online_daily_hours_limit`（NUMBER(5,2)，nullable）。`NULL` 或 `0` 視為不切分。
  - `install.xml`、`upgrade.php` 同步（版號 `2026051201`）。

- 語系：
  - 新增 `reservation_field_online_daily_hours_limit`、`reservation_field_online_daily_hours_limit_hint` 與三條錯誤字串（`zh_tw` + `en`），全部已採 `string_exists + fallback` 防護（§39 lesson learned）。

## 45. 已實作更新（2026-05-12，課前資料檢核改為「可後補 + 缺件確認」）

- 規格定位（呼應 §35）：
  - 「課前資料檢核 (3/3)」是**可先略過、後續補件**的階段，不能阻擋業務送出申請、不能阻擋進入審核。
  - 既有實作誤把 `validate_required_uploaded()` 當成送出門檻，導致業務必須當下傳完所有 required 檔案才能送出，與規格相違背 → 本次修正。

- 後端行為（`reservation/verification.php`）：
  - 移除送出時的 `validate_required_uploaded` 阻擋。
  - 收到 POST 後，無論檔案是否齊全：
    - 依現有欄位寫入已選的檔案（缺件就跳過該題）。
    - 設定 `calendar_plan_submitted = 1`、`timemodified = now()`。
    - 一律 redirect 回 `reservation/index.php?plansaved=1`，進入審核流程。
  - 是否真的會被取消，由 §43 的排程任務 `close_incomplete_reservation_sessions` 接手判斷（距開課 `reservation_verification_deadline_days` 天內仍未補齊 → 自動截止）。

- 缺件確認 modal（前端）：
  - 觸發條件：使用者按下「送出申請（完成 3/3）」當下，存在任何 `data-required="1"` 的題目同時滿足：
    - 既無**已上傳檔**（`stored_area_has_file()` 預先判定，rendered 為 `data-has-upload="1"`），
    - 本次也**未選新檔**（`input.files.length === 0`）。
  - 行為：
    - 攔截 submit、彈出 modal。
    - 內文動態組裝：若 `calendar_plan_json` 有最早段，顯示「若於 `<最早段開始時間 - deadline_days 天>` 前尚未由管理者審核完畢，則會取消此次課程」；無法計算時退化為「若於開課前 N 天內仍未由管理者審核完畢…」。
    - 「同意並送出」：將 hidden `acknowledge_incomplete` 設為 `1` 後重新 submit，後端不再二次擋。
    - 「取消」：關閉 modal，停留在當前頁面讓業務繼續補件。
  - 完整齊備時（無 missing required）：不彈 modal，正常 submit。

- UI 細節：
  - 頁面頂部新增提示條（`tm-alert-info`）：「業務可先送出申請後續再回到此頁補件；若於下方截止前未補齊並完成審核，該專屬班將被系統自動取消。」
  - Modal 使用單檔 inline `<style>` + `<script>`（與本頁既有風格一致），不引入新的 AMD 模組以降低變更面。

- 與既有機制協同：
  - §35：明訂可後補；本次只是把實作對齊。
  - §41：開課前 1 週需完成檢核（提醒用語）；modal 文案承接此「期限」語義。
  - §43：排程自動截止；modal 顯示的 deadline 計算公式（`最早段 start − deadline_days × 86400`）與排程實際使用的判定條件一致，避免業務「以為還有時間 → 實際已被關閉」的落差。

- 影響範圍：
  - 僅修改 `reservation/verification.php`，無 DB schema 變動、無語系新增（保持與本檔現有內嵌中文一致）、無版號變動。
  - 既有「重新進來補件覆蓋舊檔」行為（`linksbyquestion` 顯示「已上傳檔案（重新選擇可覆蓋）」）完全沿用。

- 管理端審核頁顯示缺檔（`admin/reservation_verification_review.php`）：
  - 既有邏輯以 `local_tm_course_vq_file` 反查為主，**沒有上傳的題目根本不會出現**，管理員看不到「業務還缺什麼」→ 本次一併修正。
  - 改為以 `verification_manager::get_questions_for_courses()` 取出本申請所有相關題目，再用 questionid 去查 `vq_file` 找實體檔。
    - 有檔案 → 渲染既有「縮圖 / 下載 + 通過 / 未通過」UI。
    - 無檔案 → 渲染「尚未上傳」徽章 + 警示框，不顯示 Pass/Fail 按鈕（沒檔案可審）。
  - 檢核項目總覽表把缺檔題目也列入，題目欄位加註 `(必填)` 標記，狀態欄位顯示「尚未上傳」。
  - 新增**進度提示條**：「已上傳：X / Y 題（必填 a / b）」；若必填尚未補齊則套 `tm-alert-warning`，已補齊或無必填套 `tm-alert-info`。
  - Empty state（`No stored attachments`）判定改成「沒有任何題目」而非「沒有任何已上傳檔」，避免題目存在但全部缺檔時被誤導為「無檢核項目」。
  - 新增 5 條 lang string（`zh_tw` + `en`）：`status_missing` / `required_label` / `summary_progress` / `missing_hint`（沿用既有 fallback 模式）。

- 審核狀態回流追蹤（避免「管理員按了未通過、業務還是看到待審核」的回報）：
  - 既有資料路徑（`vq_file.review_status` 寫入 → `get_reservation_file_links` 讀回）程式碼正確，**最常見的成因是業務頁面在管理員按下未通過之前載入、之後沒重整**，看到的是舊渲染（瀏覽器 bfcache / 同分頁未刷新）。
  - 防禦三件套：
    1. `reservation/tracking_detail.php` 新增 `Cache-Control: no-store, must-revalidate` 與 `Pragma: no-cache` header，確保業務每次回到頁面都拿最新資料。
    2. 「審核狀態」欄已審項目補上「**審核於 YYYY-MM-DD HH:MM**」小字，讓業務一眼判斷管理員是否真的有按過：時間有出現 → DB 寫過了；只有「待審核」沒時間 → 管理員根本沒按到這題。
    3. `admin/reservation_verification_review.php` 成功訊息從 `Verification result updated.` 改成 `「<question_text>」已標記為「通過 / 未通過」`，避免管理員誤觸別題或別申請而不自知；找不到 `vq_file` 紀錄時改回 `NOTIFY_ERROR` 並提示題目名稱（不再 silent return）。
  - 新增 3 條 lang string（`zh_tw` + `en`）：`reservation_verification_review_saved_detail` / `reservation_verification_review_save_no_file` / `reservation_tracking_verification_reviewed_at`。

## 47. 已實作更新（2026-05-17，視訊預排教室）

### 47.1 背景與目標

- **問題**：視訊專屬開班在智慧月曆預排時，若 fallback 到「適用教室第一間」或「教室表第一筆」，會與老師實際上課地點不符，教室占用／衝突檢查也會失真（教室可能沒有學員，但仍有老師在實體教室授課）。
- **目標**：
  - 由**系統管理員**在「課程連動」為每門課指定視訊預排時佔用的實體教室（老師所在地）。
  - **業務不可**在申請表自選視訊教室。
  - 預排、拖曳驗證、核准建場一律使用該設定教室；**禁止**對視訊 delivery 使用 `allowed_classroomids` 第一間或全系統預設教室 fallback。

### 47.2 與既有欄位分工

| 欄位 | 設定位置 | 用途 | 適用授課型態 |
|------|----------|------|----------------|
| `allowed_classroomids`（CSV，既有） | 課程連動「適用教室」多選 | 實體課可選／預排教室交集 | **實體** |
| `online_classroomid`（新增，單選） | 課程連動「視訊預排教室」 | 視訊課月曆預排佔用、衝突檢查、核准後 `sessions.classroomid` | **視訊** |

- 兩者**獨立**：一門課可「適用教室」含 A/B/C，但視訊預排固定為 D。
- 呼應 §18.2「業務端不需選擇教室」：視訊教室由管理員在課程連動決定，月曆階段自動帶入（§47 為實作細節）。

### 47.3 資料模型

- 表：`local_tm_enabled_courses`
- 欄位：`online_classroomid`（`INT`，nullable，FK 語意指向 `local_tm_classroom.id`）
- 版號：
  - `2026051704`：新增欄位與功能初版
  - `2026051705`：正式站 UI 亂碼緊急復原（`sessions.php` UTF-8 符號改 ASCII；**程式暫時移除**本功能；欄位可殘留 DB 不影響運作）
  - `2026051706`：測試站重新啟用本功能（保留 `sessions.php` 亂碼修復）
  - `2026051707`：申請表提前驗證、plan API 錯誤回傳、月曆阻擋與 tooltip 教室名稱（無 schema 變更）
- 外掛 release：`5.8.0`（`version.php` 以 `2026051707` 為準）

### 47.4 管理端：課程連動（`settings/course_mapping.php`）

- UI：表格在「**開放視訊**」右側新增欄「**視訊預排教室**」（單選下拉，資料來源 `local_tm_classroom`）。
- 頁面說明：`course_mapping_online_classroom_notice`。
- 儲存驗證：
  - 若該課程勾選「開放視訊」，**必須**選擇有效之 `online_classroomid`，否則 redirect 錯誤（`course_online_classroom_required` / `course_online_classroom_invalid`）。
- 讀寫：`enabled_course_manager::save_enabled_courses()` / `get_online_classroom_map()` / `get_online_classroom_id()`。

### 47.5 教室解析（核心 API）

- 方法：`enabled_course_manager::resolve_plan_classroom($courseid, $deliverymode, $preferredclassroomid, $fallbackclassroomid)`
- 規則：

```text
delivery_mode = online：
  → 僅讀 get_online_classroom_id(courseid)
  → 存在且 local_tm_classroom 有該筆 → 回傳該 ID
  → 否則 → 0（失敗，不 fallback）

delivery_mode = onsite：
  → 維持既有邏輯：適用教室 ∩ 申請期望教室 → 適用清單第一間 → preferred → fallback
  → 不讀 online_classroomid
```

### 47.6 業務端申請表（`reservation/index.php`）

- 選「視訊」時：
  - 「希望上課教室」下拉**停用**且值為 `0`；`preferred_classroomid`、`classroomid` 寫入 **`null`**（與實體分流）。
- 送出驗證（`2026051707` 起）：
  - 對所選 `courseids` 呼叫 `get_missing_online_classroom_course_ids()`；
  - 若有缺設定，阻擋送出並顯示 `reservation_error_online_classroom_unconfigured_list`（列出課程名稱）。

### 47.7 智慧月曆與預排 API

- **`reservation/plan_events.php`**：
  - 進入預排前：若為視訊且任一課程缺 `online_classroomid`，回傳 `{ ok: false, error: 'reservation_calendar_error_online_classroom_unconfigured', events: [] }`。
  - 每門課 segment 透過 `resolve_plan_classroom()` 取得 `classroomId`；若視訊且 `classroomId <= 0`，該課不產生合法 block。
  - 成功回傳：`{ ok: true, events: [...] }`（前端需處理 `ok: false`）。
  - 教室占用來源：`local_tm_course_sessions` 中 `classroomid > 0` 之既有正式場次（與 §22 相同）；預排時避開重疊時段。
- **`reservation/calendar.php`**：
  - 載入時若視訊且缺設定：顯示紅色 alert（`$onlineclassroomblocked`），**不**執行錯誤的 fallback 預排。
  - 前端 `onlineClassroomBlocked`：略過 plan fetch 或視同 API 失敗。
  - 事件 `extendedProps.classroomLabel`：tooltip 顯示教室名稱。
  - 若已存在 `calendar_plan_json`，優先載入已存預排（§23 行為不變）。
- **`classes/reservation_plan_validator.php`**：
  - 視訊 block 之 `classroomId` 必須等於該課 `get_online_classroom_id()`；
  - 不符 → `reservation_calendar_error_online_classroom`；
  - 未設定 → `reservation_calendar_error_online_classroom_unconfigured`。

### 47.8 審核建場與追蹤

- **`admin/review_center.php`**：核准時依 `calendar_plan_json` 各 block 的 `classroomId` 建立 `local_tm_course_sessions`；視訊場次 `classroomid` = 預排教室（老師佔用），視訊連結仍來自申請之 `preferred_meeting_link`。
- **`reservation/tracking_detail.php`**：預排區塊列表顯示各段教室名稱（自 `classroomId` 查 `local_tm_classroom`）。

### 47.9 語系字串（`zh_tw` + `en` 完整；其餘語系 `reservation_calendar_error_online_classroom` 仍可能為舊文案）

| Key | 用途 |
|-----|------|
| `course_online_classroom` | 課程連動欄位標題 |
| `course_online_classroom_help` | 欄位說明 |
| `course_online_classroom_required` | 儲存必填 |
| `course_online_classroom_invalid` | 無效教室 |
| `course_mapping_online_classroom_notice` | 頁面頂部說明 |
| `reservation_error_online_classroom_unconfigured_list` | 申請表列出缺設定課程 |
| `reservation_calendar_error_online_classroom_unconfigured` | 月曆／API 單課未設定 |
| `reservation_calendar_error_online_classroom` | 預排教室與設定不符 |

### 47.10 建議測試（已於測試站驗證通過）

| # | 項目 | 預期 |
|---|------|------|
| A | 課程連動：開放視訊必選視訊預排教室 | 儲存阻擋／設定保留 |
| B | 視訊申請：教室欄停用 | 業務無法自選 |
| C | 月曆預排 | block 教室 = 課程連動設定；tooltip 顯示名稱 |
| D | 教室 X 已有重疊正式場次 | 預排避開或往後工作日 |
| E | 手動拖到衝突時段 | 無法儲存預排 |
| F | 實體課回歸 | 仍用適用教室 + 期望教室，不讀 `online_classroomid` |
| G | 核准建場 | `sessions.classroomid` = 視訊預排教室 |
| H | 場次管理 `sessions.php` | 按鈕無 `??` 亂碼（部署須 UTF-8 無 BOM） |

### 47.11 部署注意

- 上線前於**課程連動**為所有「開放視訊」課程補齊視訊預排教室。
- PHP 原始碼以 **UTF-8（無 BOM）** 上傳；`admin/sessions.php` 避免使用易在錯誤編碼下損壞的特殊符號（§47.3 `2026051705` 教訓）。
- 升級後執行「清除所有快取」。

### 47.12 刻意不在本版範圍（後續可開需求）

- 預排占用**未納入**其他「待審」專屬開班已存之 `calendar_plan_json`（僅查正式 `sessions`）；兩筆待審案仍可能排到同一教室同一時段，核准後才衝突。
- **講師／老師個人**時段衝突（非教室占用）未實作。
- 申請表未唯讀顯示「將使用教室 XXX」（僅月曆 tooltip／追蹤明細可見）。

---

## 48. 已實作更新（2026-05-19，場次座次總覽）

- **目標**：讓系統管理員與具業務批次報名資格者，以唯讀方式查看各場次**已核准**學員的桌次／機構／姓名（卡位無姓名時顯示 `卡位 #N`）。
- **頁面**：`admin/session_roster.php?sessionid={id}`
- **權限**（唯讀）：`is_siteadmin()` 或 `local/tm_course:manage` 或 `permissions_manager::user_can_batch_enrol()`。
- **資料範圍**：僅 `ENROL_APPROVED`；姓名與機構沿用 `enrolment_manager::format_attendance_roster_cells()`（與出缺席名冊一致）。
- **報名來源**（`2026051710`）：`format_enrol_source_label()` — `batch_submittedby` 與學員 `userid` 相同 → **自主報名**；否則 → **批次報名（業務姓名）**。
- **實體課**：依 `num_desks` 顯示桌次卡片格，每格列出學員名 + 機構；`desk_number` 未設定者列入「未分配桌次」。
- **視訊課**：無桌次，以表格依機構列出學員名。
- **入口連結**：`admin/sessions.php`、`admin/enrolments.php`（場次明細）；業務主要從前台 `index.php` 場次卡片「🪑 桌次」旁 **查看報名狀況**（點月曆 block 會捲動至該卡片）。
- **顯示名稱**：`session_roster_button` = **查看報名狀況**（非「座次總覽」）。
- **版號**：`2026051708`（無 DB schema 變更）。

## 49. 已實作更新（2026-05-19，取消候補／軟性建議名額）

- **決策**：不再使用「候補中」(`ENROL_WAITLISTED`)；`num_desks × persons_per_desk` 為**建議名額**，僅供 UI 與場次 `STATUS_FULL` 提示，**不阻擋**核准、批次報名或前台自主報名。
- **升級遷移**（`2026051711`）：既有 `status = 4`（候補中）全部改為 `0`（待審核），由管理員重新核准。
- **`enrolment_manager::approve()`**：移除「名額已滿 → 候補」與「單桌人數 ≥ persons_per_desk」錯誤；實體課仍須指定有效桌號。
- **`batch_enrol_pending()`**：僅在場次 `STATUS_CLOSED`（或報名截止規則）時拒絕；不因 `STATUS_FULL` 或 `remaining_persons` 截斷名單。
- **前台／月曆**：`index.php`、`enrol_form.php`、`enrol_apply_step.php`、`calendar_events.php` — 僅 `STATUS_CLOSED` 與報名截止阻擋；`STATUS_FULL` 仍可報名／顯示報名 CTA（與批次一致）。
- **批次 UI**：`batch_enrol.php` / `batch_enrol.js` 移除剩餘名額上限攔截與截斷預覽。
- **保留**：`recalculate_status()` 仍可依建議名額將場次標為「額滿」供顯示；出缺席／Moodle 群組仍僅含**已核准**學員。
- **版號**：`2026051711`（無 DB schema 變更）。

## 50. 已實作更新（2026-05-19，實體額滿依桌次／視訊僅截止）

- **實體課 — 額滿**：`recalculate_status()` 改為「`num_desks` 每一桌皆至少有一位**已核准且已分配桌號**學員」→ `STATUS_FULL`；**不再**以「已核准人數 ≥ 建議名額（桌×每桌人數）」判斷額滿。
- **實體課 — 阻擋報名**：`STATUS_FULL` 或 `已截止`（含開課前一日 00:00 自動截止、手動截止）時，**自主報名與業務批次皆不可**；建議人數僅 UI 參考，不阻擋核准或單桌超額。
- **視訊課**：**不使用**額滿擋報名；僅 `已截止`（自動／手動）關門；`recalculate_status()` 不將視訊標為 `FULL`。
- **狀態時間軸（實體）**：開放 →（每桌都有學員）→ 額滿 →（開課前一日 00:00）→ 已截止。
- **管理員**：`local/tm_course:manage` 仍可對**已截止**場次批次加入（`allowclosed`）；**額滿**不開放任何人批次。
- **版號**：`2026051712`（升級時重算 OPEN/FULL）。

## 51. 已實作更新（2026-05-19，課前通知）

- **設定頁**：`settings/notifications.php` 頂部區塊 **課前通知**（與既有事件模板分開）。
- **範圍**：僅 **明日**、**授課型態＝實體** 之正式場次（排除教室關閉區塊）；**視訊課**不寄此信。
- **收件人**：依角色（多選）＋手動 **額外 Email**（多組）＋可選「明日場次批次業務」自動加入。
- **發送時間**：管理員自訂每日時:分（伺服器時區）；排程 `send_pre_class_notification` 每 5 分鐘檢查，**每日最多一封**。
- **內容**：HTML 郵件；**單一合併表**（含場次、報名方式欄）；欄位標題可由管理員自訂（留空用預設）；資料來源為 **已核准** 報名。
- **主旨／開頭**：可編輯，支援 `{{date}}`。
- **收件人 UI**：與其他通知相同—「通知對象」勾選（審核者、批次提交者）＋額外 Email；**不再**使用依角色多選。
- **內文模板**：主旨／內文可編輯；`{{date}}`、`{{sessions_table}}`。
- **Mock 預覽**：按「Mock 預覽」以示意資料（自主報名、批次完整名單、批次卡位）顯示於**單一表格**；多個正式場次亦合併同一表，以「場次」欄區分。
- **測試信**：按「發送測試信」寄至所有已設定信箱（額外 Email ＋ 若勾選審核者）；內文為 Mock 資料。
- **通知設定 UI**：首頁區塊僅顯示標題；說明文字在點開後的區塊標題列顯示。
- **語系**：缺字串時使用內建 fallback，避免頁首 debug 訊息。
- **版號**：`2026051715`。

## 46. 近期開發功能、介面與機制總覽（2026-04～2026-05）

本章為 **閱讀用索引**：細部行為仍以 §1～§54 與各節「已實作更新」為準；維運除錯請搭配 **`lesson-learned.md`**。

### 46.1 首頁 Dashboard 與導覽（`block_tm_dashboard` + `lib.php` hooks）

| 項目 | 說明 |
|------|------|
| 注入機制 | `local_tm_course_before_standard_top_of_body_html()` 注入首頁主內容區；**不在**該 hook 使用 `$PAGE->requires`（改 inline／延遲載入），見 `lesson-learned.md`。 |
| 標題與區塊 | 「實體/線上課程報名系統」、快捷按鈕分組（學習與報名／申請與流程／營運與設定）。 |
| 位置與可見性 | `dashboard_control.php`（sesskey + site admin）；集中設定：`settings.php` → Dashboard 顯示設定。 |
| 角色化區塊 | 一般使用者／業務／管理員各組獨立 `dashboard_widget_{role}_{*}`，缺值回退舊全域 key。 |
| 區塊內容 | 即將到來的課程、審核中的課程、近期開班申請、近期批次報名、課程月視圖（FullCalendar + `calendar_events.php`）。 |
| 導覽 | 左側固定「我的上課與報名紀錄」(`my_records.php`)；業務「搜尋紀錄」以 Dashboard 按鈕為主（`search.php` 權限另定）。 |

### 46.2 前台報名、衝突防呆、葷素與取消

| 項目 | 說明 |
|------|------|
| 月曆與清單 | `index.php`：月視圖切月同步下方「開課場次」清單；M6 實心卡片、FullCalendar 初始化須等 footer JS。 |
| 時段衝突 | 同使用者已核准場次時間重疊阻擋（報名／核准／批次 pending 路徑一致）。 |
| 自主報名確認 | `enrol_diet.php`（報名確認：機構必填 + 實體課葷素；視訊課葷素反灰）；詳 §53。 |
| 葷素／取消 | `enrol_diet_edit.php`、`enrol_cancel.php`；Dashboard 與前台卡片入口一致。 |
| 批次備註 | `local_tm_course_enrolments.batch_submitter_note`；debrief 與審核列表可見。 |

### 46.3 管理與審核整合

| 項目 | 說明 |
|------|------|
| 審核中心 | `admin/review_center.php`：既有課程報名審核 + 客製需求審核；預設 filter 待審；表頭可排序。 |
| 場次管理 | `admin/sessions.php`：批次勾選刪除；視訊場次不強制桌次（`approve()` 等路徑一致）；**座次總覽**見 §48 `session_roster.php`。 |
| 出缺席 | `attendance_manager`：優先綁定場次 `attendance_cmid`、具名活動、必要時新建；點名連結補齊 `sessionid`/`grouptype`；同步 `attendance_log` 與 slot 對應規則。 |
| 搜尋權限 | `search.php`：`manage` 或 `user_can_batch_enrol()`；證書區塊見 §54（已核發 + 可領取未核發；`certificate_download.php` 代為核發並下載）。 |

### 46.4 專屬開班預約（Reservation）端到端

| 階段 | 主要檔案／機制 |
|------|----------------|
| 課程連動 | `settings/course_mapping.php`：`allowed_classroomids`（實體）、**`online_classroomid`（視訊預排教室，§47）**；開放視訊必填。 |
| 申請表單 | `reservation/index.php`：免責 modal、課程複選、實體/視訊、`course_mapping` 分流時數、期望日期 ≥ 今日+14 天、視訊可選 `online_daily_hours_limit`（整數小時切分）；視訊不選教室、缺設定阻擋送出（§47）。 |
| 月曆排程 | `reservation/calendar.php`、`plan_events.php`：`resolve_plan_classroom()`、教室占用（正式場次）、同申請不重疊、週末阻擋、`physical_daily_limit` / `online_day_end_time`；管理員 `review=1` 審核編輯；`save_calendar_plan.php` + `reservation_plan_validator.php`（§47）。 |
| 課前檢核 | `reservation/verification.php`（3/3）：可後補、缺件確認 modal；`settings/course_mapping.php` 題目設定；`admin/reservation_verification_review.php` 逐檔審核；`local_tm_course_vq_*` 資料表。 |
| 審核建場 | `review_center.php` 核准後建立 `local_tm_course_sessions`，帶 `source_reservation_id`；學員寫入 pending；通知走 `notification_helper`。 |
| 追蹤 | `reservation/tracking.php`、`tracking_detail.php`（batch/custom）；檢核補件 badge、開課倒數風險提示（§41）。 |
| 自動化 | `close_incomplete_reservation_sessions` task：開課前 N 天內檢核未齊 → 場次 `STATUS_CLOSED`；`reservation_verification_deadline_days` 可設定。 |

### 46.5 月曆資料與「我的專屬開班」

| 項目 | 說明 |
|------|------|
| API | `calendar_events.php`：`extendedProps` 含 `ownDedicatedSession`、`sourceReservationId` 等；申請者本人班次 TM 綠、其餘 TM 藍。 |
| 適用頁 | 前台 `index.php`、Dashboard 月曆、預約月曆共用資料語意。 |

### 46.6 批次報名與帳號建置（2026-05-09）

| 項目 | 說明 |
|------|------|
| 完整列 | 姓名+機構+Email → 連結既有帳號或 manual 建帳 + 通知。 |
| 不全列 | placeholder 卡位；`tracking_detail.php?type=batch` 補件後建帳／綁定；`帳號狀態` 欄位。 |

### 46.7 通知中心（M5）

| 項目 | 說明 |
|------|------|
| 設定頁 | `settings/notifications.php`：accordion、模板變數、預設與額外角色收件者、去重。 |
| 通道 | 站內 + Email 分離處理；關鍵流程不因 email processor 失敗而中斷。 |
| 情境擴充 | 含 `batch_enrol_completed`、新報名、取消報名、客製審核結果等（細項見 §37、§40）。 |

### 46.8 我的紀錄與證書

| 項目 | 說明 |
|------|------|
| `my_records.php` | 課程紀錄、報名紀錄、證書清單（課程層級；§54 資料來源與搜尋頁證書區塊一致）；`classes/certificate_helper.php`。 |
| `search.php` | 使用者搜尋結果下方獨立「證書紀錄」區塊；與課程紀錄表分離；細項見 §54。 |
| 權限語意 | 證書與場次解耦，避免「場次駁回卻顯示可下載證書」誤解；證書資格以 Moodle customcert 課程活動為準，非 TM 報名審核狀態。 |
| 下載行為 | 本人走 customcert `downloadown=1`；業務搜尋他人未核發時走 `certificate_download.php` 代為 `issue_certificate` 後產 PDF。 |

### 46.9 Cron／排程任務（摘要）

| 任務 | 用途（摘要） |
|------|----------------|
| `remind_pending_enrolment` | 逾時未審提醒（`reminder_threshold`）。 |
| `auto_close_sessions` | 開課日前一日 00:00 截止場次。 |
| `send_pre_class_notification` | 課前通知：明日實體課程摘要信（見 §51）。 |
| `close_incomplete_reservation_sessions` | 專屬開班檢核逾期自動關閉場次。 |
| `audit_approved_enrolment_sync`（若有啟用） | 核准報名與 Moodle 選課一致性稽核。 |

---

## 52. 已實作更新（2026-05-29，出缺席改版＋便當需求發送）

**狀態**：已實作（版號 `2026052901`）。  
**與課前通知（§51）關係**：完全獨立；課前通知為「明日多場次、已核准」排程信；本功能為「當日單場次、已出席」講師手動寄信。

### 52.1 業務流程

1. 講師於開課當日在 **出缺席** 頁完成點名（出席／缺席）。
2. 點 **便當需求發送** → 開啟可編輯之郵件摘要 Modal（收件者、主旨、內文）。
3. 內文含依機構彙總之便當統計表（僅統計 **已標記出席** 之學員）。
4. 講師確認後按 **送出** → 真實寄出 Email 至行政等收件人。

### 52.2 出缺席清單 UI（`admin/attendance.php`）

| 項目 | 規格 |
|------|------|
| 實體課版面 | 沿用 §48 `session_roster.php` 之 **桌次卡片 grid**（`.tm-roster-grid`） |
| 視訊課版面 | **機構表** ＋ 每列姓名旁出席／缺席按鈕（與查看報名狀況一致） |
| 每筆學員顯示 | 姓名、機構（小字）、**報名來源**（`format_enrol_source_label()`，含批次業務姓名） |
| 不顯示 | 序號 #、Email |
| 操作 | 姓名旁 **出席**／**缺席** 按鈕（維持 `action=present\|absent` + `enrolid`） |
| 未分配桌次 | 實體課保留「未分配桌次」區塊，規則同上 |
| 保留 | 建立／**重新建立分組與出缺席**、全部標記出席、Moodle 點名連結、出席統計列（已報名／出席／缺席／未記錄） |
| 資料 | 共用 `build_session_roster_view()`（或等價 attendance view）＋ 併入 `attended`；僅 `ENROL_APPROVED` |

**實作建議**：抽出 roster 與 attendance 共用 partial，避免 `session_roster.php` 與 `attendance.php` 雙份 HTML。

### 52.3 出席便當統計卡（頁面上方）

- **保留**現有統計卡區塊，標題改為「出席便當統計」或同等語意。
- 統計範圍改為僅 **`attended = 出席`** 之學員：
  - **葷食**：`diet_choice = 'A'`
  - **素食**：`diet_choice = 'B'`
  - **未填**：`diet_choice` 非 A/B（多為舊資料或異常；正常報名流程極少空白，見 §52.6）
- 與便當郵件內表格使用相同計數邏輯。

### 52.4 便當需求發送（僅實體課）

| 項目 | 規格 |
|------|------|
| 顯示條件 | `delivery_mode = onsite`（實體）才顯示按鈕；視訊課不顯示 |
| 權限 | 與出缺席相同（`user_can_attendance()`） |
| 觸發 | 按 **便當需求發送** → Modal（非跳頁、非排程） |
| Modal 欄位（皆可編輯） | **收件者**、**主旨**、**內文** |
| 預填來源 | `settings/notifications.php` 內 **獨立區塊「便當需求通知」**（config：`bento_notify_*`，與 `preclass_*` 分開） |
| 內文 | 預設說明文字 ＋ `{{bento_summary_table}}`（或同等 token） |
| 送出 | 按 **送出** 真實寄信（多收件解析模式可參考課前測試信） |
| 建議 UX | 若出席人數為 0，按鈕 disabled 並提示「請先完成點名」 |

### 52.5 便當統計表（郵件內 `{{bento_summary_table}}`）

- **資料範圍**：**本場次**、**僅出席**（`attended = 出席`）。
- **HTML 樣式**：沿用課前 `institution_summary_table` 之表格樣式（邊框、表頭色）；**不**共用課前「明日多場次」payload。
- **建議欄位**：機構｜出席人數｜葷食｜素食｜批次業務｜業務機構｜業務電話（業務欄邏輯可複用 `pre_class_notification_manager::format_sales_cells()`，僅掃出席列）。
- **表尾**：合計列（總出席、總葷、總素）。
- **出席但葷素未填**（`diet_choice = ''`）：**不**併入葷／素欄；於表下備註「出席但未填葷素：N 人（請人工確認）」。

### 52.6 葷素「未填」之資料說明（供維運參考）

| 情境 | 是否會出現 `diet_choice` 空白 |
|------|--------------------------------|
| 實體自主報名（無 VQ） | 否（確認頁必填；見 §53） |
| 實體自主報名（有 VQ） | 否（確認頁必填；VQ 後沿用 pending；見 §53） |
| 視訊課自主報名 | **是**（確認頁不收集葷素；見 §53） |
| 批次卡位／不全列卡位 | 否（預設 **A**，業務可於追蹤頁修改） |
| 批次完整列（實體） | 否（必填葷或素） |
| 舊資料／異常 | 可能為 `''` |

### 52.7 管理員通知設定（`settings/notifications.php`）

新增收合區塊 **便當需求通知**（與課前通知並列），至少包含：

- 預設收件者 Email（多筆）
- 預設主旨模板（支援場次變數，如 `{{session_name}}`、`{{date}}`）
- 預設內文模板（含 `{{bento_summary_table}}`）
- （可選）測試寄信／預覽

### 52.8 建議實作模組

| 檔案 | 用途 |
|------|------|
| `classes/bento_notification_manager.php` | 單場次出席彙總、渲染表、寄信 |
| `classes/enrolment_manager.php` | attendance roster view（擴充 `attended`） |
| `admin/attendance.php` | 新 UI、Modal、`action=send_bento` |
| `settings/notifications.php` | `bento_notify_*` 設定區 |
| `lang/zh_tw`, `en` | 新字串 |

**刻意不做**：與課前通知合併、視訊課便當按鈕、依「已核准未出席」訂便當。

---

## 53. 已實作更新（2026-06-08，場次先修條件）

**狀態**：已實作。  
**核心類別**：`classes/prerequisite_manager.php`；場次欄位 `local_tm_course_sessions.prerequisite_rules`（JSON）；課程連動欄位 `local_tm_enabled_courses.default_prerequisite_rules`。

### 53.1 先修規則模型

| 項目 | 規格 |
|------|------|
| 儲存 | `prerequisite_rules` JSON；舊欄 `prerequisite_courseid` 升級時遷移為「整門課程完成」單一規則 |
| 規則組合 | **AND**（須全部符合）或 **OR**（符合任一即可） |
| 單條規則—課程 | 僅 **TM 啟用課程**（`local_tm_enabled_courses`） |
| 單條規則—驗證 | **整門課程完成**，或 **指定活動完成**（活動須有 Moodle 完成度追蹤） |
| 活動條件 | **全部指定活動** 或 **任一指定活動** |
| 驗證來源 | Moodle `completion_info`（整課／活動完成狀態） |

### 53.2 管理端設定

| 入口 | 說明 |
|------|------|
| 編輯場次 | `admin/edit_session.php`：規則編輯 UI（課程、驗證方式、活動多選） |
| 課程連動 | `settings/course_mapping.php` → **先修課程預設**：新建場次自動帶入；管理員可於各場次修改或清除 |
| 活動清單 AJAX | `prerequisite_activities.php`（僅列出有完成度追蹤的模組） |

**注意**：TM 自動建立的出缺席活動預設**未**啟用活動完成度；若要以「出席」代表先修，管理端宜設 **整門課程完成**（TM 標記出席會觸發課程完成），或手動為出缺席啟用完成度後再選「指定活動」。

### 53.3 有先修條件時—批次報名模式

| 項目 | 規格 |
|------|------|
| 區塊 A（多公司卡位） | **關閉**（前端隱藏 + 後端 `error_batch_seat_prerequisite`） |
| 區塊 B（完整名單） | **唯一**可用模式 |

### 53.4 自主報名（學員端）

| 項目 | 規格 |
|------|------|
| 場次卡片 | 顯示 **⚑ 需完成先修課程** 標籤；其下**每門先修課程各一行**（僅課程全名，不顯示活動細節） |
| 按報名前 | 若使用者未達先修，卡片上可見上述資訊；**不**顯示長篇警告條 |
| 按「申請報名／立即報名」 | 未達先修時彈出 **Debrief 風格 Modal**（標題：先修課程提醒），內文為正式說明（例：報名此場次前，請先完成以下其中一門先修課程：XXX。）；按 **確認** 關閉，**不進入**飲食／驗證流程 |
| 送出後檢查 | `enrolment_manager::enrol()` 仍會再驗證；未達先修拋出錯誤（訊息僅含**先修課程名稱**） |
| 學員不可見 | 先修活動名稱、AND/OR 內部邏輯、Key point review 等活動層級文案 |

### 53.5 批次報名 Debrief（業務／管理員）

| 項目 | 規格 |
|------|------|
| 先修狀態欄 | 場次有先修時，Debrief 表格固定顯示 **先修狀態** 欄 |
| 顯示值（僅兩種） | **符合**（綠色）／ **不符合**（黃色） |
| 不提供 | 「無法驗證」「尚未註冊，無法驗證先修」等第三狀態；未註冊或查詢失敗一律視為 **不符合** |
| 資料來源 | `batch_lookup.php` 帶 `sessionid` 參數，與帳號查詢同一請求回傳 `prereq_met`（避免獨立 `batch_prereq_check.php` 失敗導致無法顯示） |
| 頂部摘要 | **不顯示**僅含數字之「先修狀態：1」摘要區塊；以表格逐人狀態為準 |

### 53.6 批次報名送出後—先修與角色

| 角色 | 未達先修者 |
|------|------------|
| **業務**（`user_can_batch_enrol()`，非 Site Admin） | **略過（skipped）**；符合者照常 pending；成功訊息含略過筆數與原因 |
| **Site Admin** | **可 bypass**：仍建立 pending；成功訊息另列「未達先修仍已建立報名」摘要（僅列**先修課程名稱**，不列活動細節） |

### 53.7 技術備註（維運）

- 呼叫 `fullname()` 前須載入完整 `user` 列（`*`），避免 AJAX／redirect 時 debugging 污染 JSON（見 `lesson-learned.md`）。
- `batch_prereq_check.php` 仍保留供相容；Debrief 以 `batch_lookup.php?sessionid=` 為準。
- 相關語系：`prerequisite_learner_*`、`batch_prereq_met`、`batch_prereq_not_met`、`prerequisite_learner_card_label` 等（`zh_tw` + `en`）。

## 53. 已實作更新（2026-06-08，自主報名確認頁：機構與葷素整合）

> **適用範圍**：僅 **自主報名**（具 `local/tm_course:enrol` 且通過 `permissions_manager::user_can_self_enrol_by_role()` 之使用者）。**批次報名**流程不變。

### 53.1 背景與目標

- 使用者不應自行至 Moodle 個人資料填寫機構；機構應在報名當下於前台完成。
- 原設計將機構分散於課程列表（`index.php`，僅帳戶機構為空時）與 session pending，且視訊課／有驗證問卷場次會略過或延後收集，導致日曆報名等路徑可能進入葷素頁後才因機構空白失敗。
- 新設計將 **機構名稱** 與 **飲食習慣** 整合為單一 **報名確認** 頁，作為自主報名之統一資料收集點。

### 53.2 頁面與路由

| 項目 | 說明 |
|------|------|
| 頁面 | `enrol_diet.php`（語意擴充為自主報名確認頁；檔名沿用） |
| 頁面標題 | `enrol_confirm_title`（繁中：**報名確認**） |
| 入口 | `index.php`（列表報名）、`enrol_form.php`（月曆自主報名）→ `enrol_apply_step.php` → **一律**導向 `enrol_diet.php` |
| 不再於列表顯示機構欄位 | `index.mustache` 已移除 `show_institution` 區塊 |

**自主報名流程（簡圖）**：

```
點擊報名
  → 建立 SESSION pending（僅 sessionid）
  → enrol_apply_step.php（場次可報名檢查）
  → enrol_diet.php（報名確認：機構 + 葷素）
       ├─ 有場次驗證問卷（VQ）→ enrol_session_verification.php → 完成報名
       └─ 無 VQ → 直接完成報名
```

### 53.3 報名確認頁欄位規則

| 欄位 | 實體課 | 視訊課 |
|------|--------|--------|
| **機構名稱** | 必填；可編輯文字欄 | 必填；可編輯文字欄 |
| **飲食習慣**（葷／素、特殊備註） | 必填（葷或素擇一） | 整區 **反灰**（`fieldset disabled`），顯示 `enrol_confirm_diet_online_hint`；**不**收集、**不**送預設葷食值 |

- **機構預填**：若 Moodle 帳戶 `user.institution` 已有值，帶入欄位；使用者可清空重打後送出。
- **機構寫回帳戶**：送出確認頁時呼叫 `enrolment_manager::sync_user_institution()`，**每次**更新 `user.institution`（含修改既有值）；`enrol()` 亦透過同一方法同步。
- **SESSION pending**（`$SESSION->local_tm_course_diet_pending`）於確認頁送出後寫入：
  - `sessionid`、`institution`、`diet_choice`、`diet_special_note`、`confirmed = true`、`timecreated`
  - 視訊課：`diet_choice` / `diet_special_note` 為空字串。

### 53.4 與場次驗證問卷（VQ）之銜接

- 機構與葷素均在 **報名確認頁**（`enrol_diet.php`）填寫，**非**驗證問卷頁、**非**課程列表。
- 順序：**報名確認 →（若有 VQ）驗證問卷上傳 → 完成報名**。
- `enrol_session_verification.php`（自主報名）：
  - 若 pending 不存在 → 回首頁，`error_enrol_flow_expired`
  - 若 `confirmed` 為 false → 導回 `enrol_diet.php`
  - 驗證送出完成報名時，使用 pending 內之 `institution`、`diet_choice`、`diet_special_note`（**不再**預設葷食 A）

### 53.5 相關程式與字串

| 檔案 | 變更要點 |
|------|----------|
| `enrol_diet.php` | 報名確認 UI；機構 + 葷素；VQ 分流 |
| `enrol_apply_step.php` | 移除視訊課略過確認頁之直接 `enrol()`；一律導向確認頁 |
| `enrol_form.php` / `index.php` | pending 僅存 `sessionid`（不預帶 institution） |
| `classes/enrolment_manager.php` | 新增 `sync_user_institution()`；`enrol()` 一律同步機構至 profile |
| `enrol_session_verification.php` | 檢查 `confirmed`；報名時讀取 pending 葷素 |
| `styles.css` | `#tm-enrol-diet-fieldset:disabled` 反灰樣式 |
| `lang/*` | `enrol_confirm_title`、`enrol_confirm_diet_section`、`enrol_confirm_diet_online_hint`、`error_enrol_flow_expired` |

### 53.6 葷素「未填」資料說明（更新 §52.6）

| 情境 | 是否會出現 `diet_choice` 空白 |
|------|--------------------------------|
| 實體自主報名（無 VQ） | 否（確認頁必填葷或素） |
| 實體自主報名（有 VQ） | 否（確認頁必填；VQ 完成後沿用 pending 值） |
| 視訊課自主報名 | **是**（確認頁不收集葷素，不寫入預設 A） |
| 批次卡位／不全列卡位 | 否（預設 **A**，業務可於追蹤頁修改）— 不變 |
| 批次完整列（實體） | 否（必填葷或素）— 不變 |
| 舊資料／異常 | 可能為 `''` |

### 53.7 刻意不變

- 已報名使用者變更葷素：`enrol_diet_edit.php`（不變）。
- 批次報名之機構／葷素驗證與建帳邏輯（不變）。
- 無自主報名權限者仍顯示「請洽原廠業務」等聯絡提示（不變）。

---

## 54. 已實作更新（2026-06-09，搜尋紀錄證書：可領取未核發 + 代為 View certificate）

**狀態**：已實作。  
**相關檔案**：`classes/certificate_helper.php`、`search.php`、`my_records.php`、`certificate_download.php`。

### 54.1 背景：customcert 核發時機

- Moodle `mod_customcert` 的 `customcert_issues` **不會**在學員僅進入活動頁時自動建立。
- 學員在課程內按 **「View certificate」**（`view.php?id={cmid}&downloadown=1`）時，若尚無 issue，才呼叫 `certificate::issue_certificate()` 寫入 `customcert_issues` 並產生 PDF。
- 初版 TM 搜尋／我的紀錄僅查 `customcert_issues`，導致「課程內已可領證、但從未按過 View certificate」的學員在 TM **查不到證書列**。

### 54.2 搜尋紀錄（`search.php`）證書區塊規格

**區塊位置**：搜尋成功後，依序為「使用者搜尋結果」→「課程紀錄」→ **「證書紀錄」**（獨立表格；課程紀錄表**不含**證書欄位）。

**資料來源**（`certificate_helper::get_certificates_by_user_ids()`）：

1. **已核發**：`customcert_issues` 中，本次搜尋命中使用者之全部 issue（JOIN `customcert`／`course`／`course_modules`）。
2. **可領取未核發**：上述使用者已選課且符合 customcert 領取資格、但尚無 issue 之課程證書活動。
3. 兩者合併後依 `timecreated` 倒序；未核發列排在已核發之後（`timecreated = 0`）。

**領取資格判定**（`certificate_helper::user_can_receive_certificate()`，與 customcert `email_certificate_task`／`view.php` 一致）：

| 條件 | 說明 |
|------|------|
| 已選課 | `is_enrolled()` 於該課程 |
| 活動可見 | `get_fast_modinfo()` 下該 `customcert` 之 `uservisible` 為 true（含 availability／完成條件） |
| 可領證能力 | 具 `mod/customcert:receiveissue` |
| 排除管理者 | 不具 `mod/customcert:manage`（與 customcert 報表排除邏輯一致） |
| 課程停留時間 | 若活動設 `requiredtime`，須達標（`certificate::get_course_time()`） |

**與 TM 報名狀態無關**：證書為 **課程層級**（user × course × customcert 活動），不因單一場次審核駁回／缺席而隱藏（與 §38 證書清單獨立化語意一致）。

**表格欄位**：

| 欄位 | 已核發 | 可領取未核發 |
|------|--------|----------------|
| 學員姓名 | 顯示 | 顯示 |
| 課程名稱 | 顯示 | 顯示 |
| 證書發放時間 | `userdate(timecreated)` | `—` |
| 證書代碼 | 顯示 `code` | `—` |
| 下載證書 | 顯示按鈕 | 顯示按鈕（資格符合時） |

**筆數上限**：與搜尋其他區塊相同，預設最多 100 筆；達上限顯示 `search_user_limit_note`。

### 54.3 下載按鈕與 URL 路由（`get_download_url_for_viewer()`）

不可將業務搜尋結果直接連到 `downloadown=1`（該參數僅對**目前登入者**核發）。路由如下：

| 情境 | URL／行為 |
|------|-----------|
| 檢視者 = 證書當事人 | `/mod/customcert/view.php?id={cmid}&downloadown=1`（與課程內 View certificate 相同） |
| 檢視者為他人、**已核發**且具 `mod/customcert:viewreport` 或 `mod/customcert:manage` | `/mod/customcert/view.php?id={cmid}&downloadissue={targetuserid}` |
| 檢視者為他人、**未核發**或無上述 customcert 權限 | `/local/tm_course/certificate_download.php?courseid=&userid=&cmid=` |

**代理下載**（`certificate_download.php`）：

- 進入權限與 `search.php` 相同：`local/tm_course:manage` 或 `permissions_manager::user_can_batch_enrol()`。
- 以 `find_receivable_course_certificate($courseid, $userid, $cmid)` 解析目標活動。
- 呼叫 `ensure_certificate_issued_for_user()`：若尚無 issue 且資格符合，代為執行 `certificate::issue_certificate()`（等同學員自行按 View certificate）。
- 成功後 `template::generate_pdf(false, $userid)` 輸出 PDF。

### 54.4 我的上課與報名紀錄（`my_records.php`）

- 第三區塊「證書清單」改呼叫 `certificate_helper::get_user_certificates()`（內部委派 `get_certificates_by_user_ids()`）。
- 資料語意與 §54.2 相同：已核發 + 可領取未核發；未核發列之發放時間顯示 `—`。
- 本人下載按鈕維持 `downloadown=1`（進入 customcert 原生核發流程）。

### 54.5 資料查詢穩定性（`certificate_helper`）

- **禁止**在可能重複首欄的證書候選 SQL 上使用 `get_records_sql()`（Moodle 要求首欄唯一；否則正式站 `debugdisplay` 開啟時會輸出 `Duplicate value ... in column 'userid'` 等訊息）。
- 改以私有方法 `fetch_sql_rows()`（`get_recordset_sql`）逐筆讀取。
- 以 `dedupe_certificate_rows()` 依 **`userid:courseid:customcertid`** 去重（涵蓋：同一用戶多種選課、同課程多個 `course_modules` JOIN _fan-out_ 等情況）。
- 單筆 issue 查詢（如 `find_receivable_course_certificate`）亦改 `fetch_sql_rows()`，避免 LEFT JOIN 多筆 issue 時首欄重複。

### 54.6 相關 API 摘要（`certificate_helper`）

| 方法 | 用途 |
|------|------|
| `get_certificates_by_user_ids()` | 搜尋頁證書區塊；合併已核發與可領取未核發 |
| `get_user_certificates()` | `my_records.php`；委派上者 |
| `user_can_receive_certificate()` | 是否等同可見 View certificate 按鈕 |
| `find_receivable_course_certificate()` | 解析課程／使用者（可選 `cmid`）之證書活動槽位 |
| `ensure_certificate_issued_for_user()` | 代為核發（無 issue 時） |
| `get_download_url_for_viewer()` | 搜尋頁下載按鈕 URL |
| `is_customcert_installed()` | 未安裝 `mod_customcert` 時回傳空清單 |

### 54.7 刻意不變

- 證書與 TM 場次／報名紀錄表欄位仍解耦（證書不顯示於「課程紀錄」列）。
- customcert 活動本身之 availability、requiredtime、模板內容等仍由 Moodle 管理；TM 不另建平行核發規則。
- 除錯細節與部署踩雷記錄維護於 **`lesson-learned.md`**（`get_records_sql` 首欄唯一、證書 course-level 語意等）。

## 55. 已實作更新（2026-06-16，專班初審學員 vs 後續批次報名分流）

### 55.1 背景

專屬開班核准後建立的正式場次，可能同時帶入申請時填寫的學員名單（待審）。若後續業務再以「批次報名」加人，管理員不應在「專屬開班審核」再次看到這些後續報名，以免與開班審核流程混淆。

### 55.2 規則

| 報名來源 | 審核入口 |
|----------|----------|
| 專班申請表單／核准建場時一併寫入的學員 | **專屬開班審核** →「學員報名審核（申請時帶入）」 |
| 場次建立後，再以批次報名加入的學員 | **既有課程報名審核**（與一般開課場次相同） |

- 專班區塊的「學員報名審核」僅統計、列出 `reservation_initial_enrol = 1` 的報名。
- 初審學員全部處理完（無待審）後，專班列不再展開場次審核清單；該專班申請可視為學員初審完成（仍可在「已核准」filter 查閱開班紀錄）。
- 後續批次報名之待審僅出現在上方「既有課程報名審核」。

### 55.3 資料模型

- `local_tm_course_enrolments.reservation_initial_enrol`（`0`/`1`，預設 `0`）。
- 核准建場時由 `batch_enrol_helper::pending_entries_from_reservation_learners()` 帶 `reservation_initial` 旗標寫入。
- 一般 `batch_enrol.php` 路徑不設此旗標。
- 升級 `2026061600`：依 `source_reservation_id` 場次 + `local_tm_course_resv_learner` 回填既有資料。

### 55.4 相關檔案

- `admin/review_center.php`：專班區塊計數／列表僅初審學員；初審完成後隱藏「學員報名審核」子區塊。
- `admin/enrolments.php?from_resv=`：僅列出初審學員，並顯示提示文案。
- 語系：`reservation_review_enrol_sessions_title`、`reservation_batch_full_hint`、`reservation_review_enrol_initial_only_hint`（`zh_tw` / `en`）。
