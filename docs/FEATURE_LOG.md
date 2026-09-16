# FEATURE_LOG — 功能開發進度與決策紀錄

> **文件角色：** 記錄每次開發的需求、討論後的產品／技術決策、與落地版本。  
> **不是** CHANGELOG 逐檔 diff；重點是「為何這樣做」。  
> **相關：** [`SPEC.md`](SPEC.md)／[`BUGFIX_LOG.md`](BUGFIX_LOG.md)／[`DEV_WORKFLOW.md`](DEV_WORKFLOW.md)

---

## 如何更新本檔

每次需求討論結束並開始實作時，追加一節：

```
## YYYY-MM-DD — <短標題>
- 需求：…
- 決策：…
- 影響範圍：…
- 版本／狀態：…（進行中 / 已驗收）
```

依 [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md)：規格確認且使用者說「開始開發」→ 先開 Draft PR 記錄；測試無誤後再 push 實作。過程中規格變更同步更新 FEATURE_LOG／SPEC／同一支 PR。

---

## 2026-09-16 — 講師端設備檢查操作 UX（同排展開／本桌批次）

- **需求：** 同一排桌次共用展開／收合（依實際 CSS grid row，非硬編碼欄數）；每桌「本桌全部正常／完成」僅改 status/task、保留 remark、不立即寫 DB；進度即時更新；禁止全部桌次一次填寫。
- **決策：**
  1. 以桌次卡片 `offsetTop` 判斷同一視覺列，`details` toggle 時同步同列 `open`。
  2. 「本桌全部正常／完成」為前端表單快填：`status`→正常、`task`→完成；不碰 remark；仍靠既有「全部儲存」送出。
  3. 進度依目前表單狀態重算（非 unset 即計入）。
- **影響範圍：** `equipment_check_partial.php`、`equipment_check.js`、lang、styles；version **5.23.2**。
- **版本／狀態：** **5.23.2；進行中（待測試環境人工驗收）**

## 2026-09-16 — 設備檢查清單維護：課程總覽展開

- **需求：** 管理頁直接顯示每課檢查項目數量，並可折疊展開唯讀清單。
- **決策：** 一次 `get_items_by_courses()` batch 載入避免 N+1；展開內容唯讀；修改仍只走「設定檢查項目」Modal；含停用項目計數。
- **影響範圍：** `equipment_check_manager.php`、`equipment_check_items.php`、lang、styles；version **5.23.0**。
- **版本／狀態：** **5.23.1；進行中（待測試環境人工驗收）**
- **5.23.1 修正：** 「設定檢查項目」Modal 項目過多時無法捲動——panel 無 max-height；改為 viewport 限制＋中間可捲＋底部按鈕固定（對齊 bento modal 模式）。交付增加 `tools/package_local_tm_course.ps1` 產出 `dist/local_tm_course.zip`。

---

## 2026-09-15 — 設備檢查 Excel 批次匯入（單課 append-only）

- **需求：** 在「設備檢查清單維護 → 設定檢查項目」Modal 內，用公版 .xlsx 批次追加檢查項目；禁止選完檔案直接寫 DB。
- **決策：**
  1. 匯入目標固定為目前 Modal 的 `courseid`；Excel「課程」欄忽略（不做 fullname mapping）。
  2. 「分類」「備註」本階段忽略，不改 schema。
  3. 只寫入 `itemname`／`scope`／`checktype`／`enabled`；嚴格 mapping，非法值算錯誤且禁止 commit。
  4. **Append-only**：新增 `create_item`／`append_items`；**不**呼叫會整課 delete+reinsert 的 `save_items_for_course`。
  5. Duplicate key = `courseid + itemname + scope + checktype`；Excel／DB 重複標 ⚠ 並跳過，不覆蓋。
  6. XLSX 以 ZipArchive + SimpleXML 最小解析（無新 Composer 依賴）；session token 預覽後再 commit。
- **影響範圍：** `equipment_check_manager.php`、`equipment_check_import_manager.php`、`equipment_check_xlsx_reader.php`、`equipment_check_items.php`、import API、lang、styles、tests；version **5.22.0** → **5.22.2**（相容公版標題列／順序欄／啟用選填；修 sharedStrings namespace）。
- **版本／狀態：** **5.22.2；進行中（待測試環境人工驗收）**
- **5.22.1 修正：** 公版 `bt_check.xlsx` 第 1–3 列為標題／說明／空白、第 4 列表頭、無「啟用」欄；parser 改為自動尋找表頭，「啟用」改選填（預設 1），支援「順序」排列。
- **5.22.2 修正：** `read_shared_strings()` SimpleXML 子節點 xpath 未繼承 namespace，導致 sharedStrings 全空、表頭被丟棄；改以 `children($ns)` 讀取 `<t>`／`<r><t>`。

---

## 2026-09-15 — Attendance 二元成績（有 Present＝100%）

- **需求：** 外掛點名同步 Attendance log 後，Gradebook 不要用原生累計平均（缺→參=50%）；改為「該活動只要有一筆 Present → 100%，否則 0%」。每次異動須重掃全部 log。
- **決策（本階段方案 A）：**
  1. 維持 `sync_to_mod_attendance` 寫 log；成功後呼叫 `sync_binary_attendance_grade_for_user`。
  2. Present 以 status acronym／English description 穩定識別（不含 Late／Absent／Excused）。
  3. 用 `grade_update('mod/attendance', …)` 更新**既有** Attendance grade item；不呼叫 `attendance_update_users_grade`；不做事件回補／override／No grade／第二成績項／cron；不改 core。
  4. 前提：TM 出缺席只由此外掛操作。
- **影響範圍：** `attendance_manager.php`、`tests/attendance_binary_grade_test.php`、version 5.21.0。
- **可移植摘要：** [`ATTENDANCE_BINARY_GRADE.md`](ATTENDANCE_BINARY_GRADE.md)（給其他外掛套用同一規則）。
- **版本／狀態：** **5.21.0；已 merge `main`（PR #6），測試站已驗收。**

---

## 2026-09-10 — 業務批改申請（作業／測驗派工）

- **需求：** 業務現況用郵件請 admin 去 Moodle 找某客戶的作業／測驗繳交、複製連結再轉給課程管理員批改，改完再用截圖回報。改成外掛派工：業務自己查已繳交並申請 → admin 分派（也可自己改）→ 同事從外掛進 Moodle 評分 → 成績回外掛給業務看。
- **決策：**
  1. 作業本體仍是 Moodle `mod_assign`／`mod_quiz`；外掛不做新題、不重做上傳／評分 UI。
  2. **一張單 = 多名學員 × 同一個**作業或測驗；範圍僅課程連動啟用課；活動清單只含**未對學員隱藏**的模組（不管開放時間／完成條件）。
  3. 沒交不能列入；搜尋姓名／email 才出已繳交名單（預設空），已勾的進購物車可累加。選填備註。
  4. 分派與「開始批改」獨立：分派＝信＋Dashboard／導覽數字；開始批改＝看得到該單的 admin 或被分派人跳 Moodle 原生評分。不搶單、不默默分派給自己。未分派單只在 admin 佇列。詳情狀態列顯示目前負責人與分派時間；改派後保留先前與目前紀錄。
  5. 分派對象＝該 Moodle 課已有批改權限者。Admin（`manage` 或 site admin）可分派／改派／駁回／自己改；**被分派同事不可再分派給別人**（即使帶有 `manage`，網站管理員除外）。同事不能關單。
  6. 完成＝每一列 gradebook 已有成績（測驗不可停在待人工評分）或「查無」終態；中間態 `3/5`。頁面重整即同步，另加排程。壞掉的人／活動只顯示查無，禁止開出 Moodle error。
  7. 同一活動＋學員不能同時在兩張未完成單；完成／駁回／取消後可重評（新單）。業務僅在未分派且尚無成績時可取消。Admin 可把已駁回／已取消的單復原（同一學員已在另一張未完成單則擋下）。
  8. 外掛成績只給業務／admin／同事看（格式化分數＋時間，無評語）。**學員在外掛零入口**，回 Moodle 作業／測驗看結果。
  9. 入口：業務「作業/測驗批改申請」＋「申請追蹤」（自己的單）；admin／被分派「待批改 (N)」。N：有分派給自己的待評分列時用列數（含同時具 manage 的人）；否則 admin 才用未分派單張數。被分派的課程管理員即使是一般使用者 audience 也要看到按鈕／導覽數字。不靠全站鈴鐺、不做新 block。搜尋／勾選學員時即預覽該份作業／測驗是否已評分與分數。
  10. 通知併入現有 `settings/notifications.php` 四事件（送出／分派／駁回取消／完成）。完成信給業務預設開；**學員收件可勾、預設關**。
- **影響範圍：** 新 `grading/*` 頁、`grading_request_manager`、資料表（表名 ≤28）、Dashboard／導覽、`notification_helper` 事件、排程同步成績；SPEC §58。
- **版本／狀態：** **5.20.8；進行中（待驗收）**。

---

## 2026-09-03 — 業務可看全部視訊課連結按鈕

- **需求：** 有批次報名權限者（含指定權限規則），即使本人未被安排／未報名該場次，前台仍要能看到所有視訊課的「加入視訊課程」按鈕。
- **決策：** `user_can_always_view_meeting_link()` 除 `manager`／`coursecreator` 外，也納入 `permissions_manager::user_can_batch_enrol()`；場次仍須為視訊且已填 `meeting_link`。一般學員條件不變（本人已核准報名）。
- **影響範圍：** `user_dashboard_helper.php`、`index.php` 場次列表按鈕、SPEC §13、FEATURE_LOG／CHANGELOG。
- **版本／狀態：** **5.19.3；已驗收**

---

## 2026-09-01 — 逾期提醒可啟用／關閉

- **需求：** 「逾期提醒時間閾值」要能整段啟用或關閉，不要只能調時間。
- **決策：**
  1. 在「通知與自動化」新增 checkbox `reminder_threshold_enabled`（預設開啟，維持既有行為）。
  2. 關閉時 task 與 `notify_pending_overdue_*` 直接 return，不發信。
  3. 未設定時視為啟用（升級相容）。
- **影響範圍：** `settings.php`、`notification_helper.php`、`remind_pending_enrolment.php`、語系、`upgrade.php`、SPEC／CHANGELOG。
- **版本／狀態：** **5.19.2；待驗收**

---

## 2026-08-27 — 點名畫面學員姓名連到 Moodle 個人檔

- **需求：** 講師在點名時能快速從學員名單進到基本資料（尤其信箱）；不要改其他名冊頁。
- **決策：**
  1. 方案 A：連 `/user/profile.php?id=`（開新分頁），不另做自訂詳情頁。
  2. **僅** `admin/class_prep.php` → `attendance_roster_partial.php`；`session_roster`／批次／審核桌次不改。
  3. 權限靠點名頁既有 `user_can_attendance()`；連結不出現在無點名權限的畫面。
  4. `build_session_attendance_view()` 補 `profile_userid`（一般用 `userid`；卡位用 `linked_userid`；無帳號不連）。
- **影響範圍：** `enrolment_manager.php`、`attendance_roster_partial.php`、`styles.css`、`lang` en/zh_tw、SPEC／CHANGELOG。
- **版本／狀態：** **5.19.1；待驗收**

---

## 2026-08-26 — TCMS 同步改指向公司 VM（非 Firebase）

- **需求：** TCMS 改由公司內部 VM 對外提供（`https://tcms.tm-robot.com`），Moodle 場次正式改同步到新站；前端 SPA 路徑 `/Project/` 不是 API root。
- **決策：**
  1. 預設／說明改為 `https://tcms.tm-robot.com`；`normalize_base_url` 會 `rtrim('/')` 並剝除誤填的 `/Project`。
  2. Token 仍只讀 `local_tm_course/tcms_sync_token`，須與 VM `TCMS_MOODLE_SYNC_TOKEN` 一致；不寫死、不提交真實 Token。
  3. POST/DELETE 路徑不變：`/api/integrations/moodle/sessions[+/{id}]`；payload 保留 KPI／學員統計欄位並加 `source=moodle`。
  4. Schema API 可能受登入保護 → 快取／fallback；失敗不擋同步。
  5. `sync_tcms_sessions` 每小時 `:15` 醒來 + `tcms_sync_reconcile_interval` 閘門，讓「每小時／每 6 小時」有效。
- **影響範圍：** `tcms_endpoint`、`tcms_sync_manager`、`tcms_cors`、`settings`、語系、`db/tasks.php`、`upgrade.php`、SPEC／CHANGELOG、單元測試。
- **版本／狀態：** **5.19.0；待驗收**（請在 Moodle 填新 URL + VM Token 後測同步）。

---

## 2026-08-10 — 先修放寬：時序 approved 報名／專班共包＋連帶取消

- **需求：** 來台連上課（如 Beginner’s → AI）不應只因「先修尚未完成」被擋；現場到達前需能先報後課。先修若後來取消，依賴它的後課不可殘留。
- **決策：**
  1. 先修通過（整課完成型）= **已完成** ∨ **有 approved 先修場次且該場次 starttime < 目標場次 starttime**（同日上下午可；多日先修以開始較早為準）∨ **專班同一次申請共包該先修課**。
  2. 「已報名」**只認 approved**；pending 不算。
  3. 放寬**只套用 `verify_type = course`（整課）**；活動完成／成績規則仍須真實達成（可與整課規則並存於 AND／OR）。
  4. 先修報名被取消／駁回（且因此失去唯一時序依據）→ **自動取消**依賴之後課報名，並 **寄信通知學員 + 報名業務**（`batch_submittedby`；若無則專班申請人／相關業務）。
  5. 公開場次／批次／專班申請／審核入學皆共用同一評估語意；專班申請階段以共包＋完成／既有 approved 為主（尚無場次時間則不做時序比對）。
  6. **報名檢查以課程連動先修預設為準（5.17.8）**；場次舊快照不再覆蓋連動（避免只有 grades、沒有整門完成）。場次明確清除仍可關閉先修。
- **影響範圍：** `prerequisite_manager.php`、`enrolment_manager.php`、`reservation_application.php`、`batch_lookup.php`、`notification_helper.php`、語系、SPEC §53／§0.5、DEV_WORKFLOW。
- **版本／狀態：** **5.17.10；已驗收主路徑**（先修「符合」已確認）；連帶取消於 5.17.9 加強；同課程重報於 5.17.10 修正（已結束場次不擋）。

---

## 2026-08-10 — 實體額滿改回人數制／Admin 可無視額滿

- **需求：** 桌次都有人但建議人數尚有餘額時，業務／Admin 仍被「額滿」擋住無法加人。
- **決策：**
  - 額滿改以「已核准人數 ≥ 桌×每桌人數」判定；桌次佔用不擋報名。
  - 業務／學員：人數額滿或已截止不可報。
  - Admin（manage／allowclosed 路徑）：可無視額滿與截止。
- **影響範圍：** `session_manager`、`enrolment_manager`、`batch_enrol`、前台入口、SPEC §56。
- **版本／狀態：** **5.17.5 起（隨 5.17.8 分支一併收斂）；已驗收**（使用者確認語意）。

---

## 2026-08-10 — DEV_WORKFLOW：先開 PR 記錄再實作

- **需求：** 規格確認並說「開始開發」後，先發 PR 留痕；測完再 push 實作；規格變更改同一 PR。
- **決策：** 見更新後 [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md) §1／§3a／§3b。
- **影響範圍：** `docs/DEV_WORKFLOW.md`、FEATURE_LOG 更新約定。
- **版本／狀態：** **已驗收**（本輪已依此流程執行）。

---

## 2026-07-29 — 專屬開班：申請時先修過濾與學員數語意

- **需求：** 「學員數」與審核報名名單不一致；申請批次未檢查先修。
- **決策：**
  - 申請階段即依課程連動預設先修檢查（多課 AND）。
  - 帳號不存在 → 不符合（原因：帳號不存在）；`create_missing_users = false`。
  - 僅符合者寫入 `resv_learner`；全部不符合允許學員數 = 0。
  - 舊已核准單不做資料清理腳本。
- **影響範圍：** `reservation_application.php`、`batch_enrol_helper.php`、`prerequisite_manager.php`、`batch_lookup.php`、`batch_enrol.js`、語系。
- **版本／狀態：** ~5.17.2 起；已驗收（語意確認後續接月曆修補）。

---

## 2026-07-29 — 專屬開班視訊月曆：期望開始時間鎖定

- **需求：** 視訊時數總量與月曆分段正確；拖曳／自動預排不得因教室衝突而改動申請的期望開始時分；衝突日應跳到下一工作日同一時分。
- **決策：**
  - 課程對應「視訊時數」= 總授課時數；月曆依線上日末／每日上限切段。
  - Preferred start time **immutable**；禁止 `findAvailableSlotSameDay` 式接檔改時。
  - 週末略過必須保留 clock time（不可重置為 00:00）。
- **影響範圍：** `reservation/calendar.php`、`plan_events.php`、`session_manager`、語系提示字串。
- **版本／狀態：** 5.17.3–5.17.4；**已驗收**（使用者：測試無誤了）。

---

## 2026-07 — 課前準備／設備檢查（Equipment Check）

- **需求：** 場次課前設備檢查清單與管理設定。
- **決策（摘要）：** 獨立 manager／admin 頁／設定項 API；表名遵守 Moodle XMLDB ≤28；避免 CHAR NOTNULL 空預設。
- **影響範圍：** `equipment_check_*`、`class_prep.php`、`db/install.xml`、`upgrade.php`、settings。
- **版本／狀態：** 進行中／隨主線合併（見 git 工作區）；細節問題見 [`BUGFIX_LOG.md`](BUGFIX_LOG.md)「表名 28 字元」條目。

---

## 歷史功能線（對照 SPEC／CHANGELOG）

| 領域 | 摘要 | 規格錨點 |
|------|------|----------|
| 專屬開班申請／審核 | 預約、學員、審核中心、日曆排課 | SPEC §專屬開班 |
| 先修／批次註冊 | 課程預設先修、批次查核與註冊 | SPEC／prerequisite |
| 出席／點名 | attendance manager、admin | SPEC／attendance |
| 課前準備／設備檢查 | class prep、equipment check items、Excel 批次匯入（5.22.0） | 本檔 2026-07／2026-09-15 |
| 業務批改申請 | 作業／測驗派工單（assign／quiz） | SPEC §58 |
| 排課規則（面授） | 教室／時段約束 | `local_tm_course/docs/SCHEDULING_REQUIREMENTS.md` |

細部版本號與檔案級變更請對照根目錄 `CHANGELOG.md`（若有）與 `local_tm_course/version.php`。

---

## 待決策／待辦（可勾）

- [ ] Equipment check 全流程產品驗收後標記已驗收並收斂 PR 描述
- [ ] 其餘進行中工作區變更一併對齊 SPEC 章節編號
- [x] 業務批改申請（§58）：規格確認並開始開發
