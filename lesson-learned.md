# Lesson Learned (local_tm_course)

## 2026-05-12 — AJAX endpoint 缺 `$PAGE->set_context()` 造成 format_string() 噴錯

### 問題現象
- 視訊「專屬開班預約」設定「每日最多上課時數 = 4」後，月曆顯示的 block 仍為單段 8 小時（未切分）。
- 表單預覽（前端 JS 試算）顯示正確：`8h → 2 天 × 4.00h`，但 plan API 出來的事件沒切。
- 直接打開 `plan_events.php?id=...&sesskey=...` 的 response 看到：
  - `error: $PAGE->context was not set. You may have forgotten to call require_login() or $PAGE->set_context()`
  - stacktrace 指向 `format_string()` 呼叫。

### 根因判斷
- `plan_events.php` 是 `AJAX_SCRIPT` 端點，只呼叫 `require_login()` 但**沒有**呼叫 `$PAGE->set_context()`。
- 一旦該檔內部用到 `format_string()`（或任何依賴 `$PAGE->context` 的 helper），就會直接拋 `coding_exception`。
- 前端 `fetch().then(res.json())` 把錯誤 JSON 當資料 parse，發現沒有 `events` 欄位就**靜默 fallback** 到月曆頁的 `$fallbackevents`（前端備援預排）。
- 而備援 fallback 當初並沒有接 `forceddailyhours`，所以使用者看到的就是「設定切分被吃掉、顯示原本不切分的結果」這個假象。

### 解決方式
1. `plan_events.php`：`require_login()` 之後立刻 `$PAGE->set_context($context);`。
2. `calendar.php`：把 fallback 用的 `$buildonlineavgblocks` closure 也加上 `$forceddailyhours` 參數，並從 `reservation->online_daily_hours_limit` 帶入。即使未來 AJAX 端點再壞，月曆 fallback 也會做出正確切分。
3. 暫時在 plan API 的 JSON 加 `debug` 欄位用來確認 DB 端值是否正確（驗證後即刻移除，不留到正式版）。

### 開發規範（必遵守）
- **任何**新增的 `AJAX_SCRIPT` 端點，`require_login()` 之後**必須**立刻 `$PAGE->set_context($context);`，否則只要呼叫到 `format_string()` / `format_text()` / 任何 `$OUTPUT-*` 介面，就會直接爆 500/coding error。
- AJAX 端點 + 前端 fallback 是**兩條獨立路徑**，新增邏輯時兩邊都要寫，否則一旦 AJAX 掛了會以「靜默回退舊行為」的形式騙過工程師。
- 對任何「設定有效但功能沒生效」的回報，第一個檢查的不是業務邏輯，而是「對應 AJAX 端點是否真的回傳正常 JSON」。

---

## 2026-04-07 — 升級成功後的變更邊界控管

### 事實紀錄
- 本次外掛升級已驗證成功。

### 風險提醒
- 問題修復期間若順手修改「需求未提及」或「與本次 issue 無關」的功能，容易引入非預期回歸，造成後續除錯成本上升。

### 開發規範（必遵守）
- 僅修改本次需求明確涵蓋的功能與檔案範圍。
- 未被需求提及或與當前問題不相干的功能、文案、邏輯、資料結構，不得任意更動。
- 如確有必要擴大修改範圍，需先在需求/PR 說明中明確註記「原因、影響面、回滾方式」。

---

## 2026-04-02 — Session time-conflict check: SQL LIMIT 重複導致語法錯誤

### 問題現象
- 新增/編輯場次時，出現類似：
  - `You have an error in your SQL syntax... near 'LIMIT 0, 1' ... SELECT ... LIMIT 1 LIMIT 0, 1`

### 根因判斷
- `record_exists_sql()` 本身會包裝 `LIMIT 0, 1`。
- 我們在自建 SQL 內又手動加入 `LIMIT 1`，導致 SQL 變成 `LIMIT 1 LIMIT 0, 1`。

### 解決方式
- 移除衝突檢查 SQL 中的手動 `LIMIT 1`。
- 讓 `record_exists_sql()` 單獨負責 limit。

### 未來如何避免
- 使用 Moodle 的 `$DB->record_exists_sql($sql, ...)` 時，SQL 內不要自加 `LIMIT`。

---

## 2026-04-02 — Session time-conflict message 顯示 key（未翻譯字串）

### 問題現象
- 新增場次出現僅顯示：
  - `local_tm_course/error_classroom_time_conflict`

### 根因判斷
- 觸發了 `moodle_exception('error_classroom_time_conflict', 'local_tm_course')`，
- 但 Moodle 實際顯示時找不到對應翻譯字串（通常是 ZIP 內 lang 檔未生效/快取未更新/語系未正確載入）。

### 解決方式
- 補齊所有語系檔的 key：`error_classroom_time_conflict`。
- 為了強制 Moodle 重新走升級流程並重新載入外掛檔案：
  - `version.php` 版本號增加（2026040217）
  - `db/upgrade.php` 新增對應 savepoint（2026040217）
- 測試時同時建議清除快取（至少 purge caches）。

### 未來如何避免
- 新增 `moodle_exception` key 時，務必同時：
  1. 把 key 加到所有目標語系檔
  2. bump `version.php` +（必要時）在 `db/upgrade.php` 加 savepoint，確保測試站真的載入更新
  3. 出現 key 顯示時優先做 cache purge，並確認測試站使用的語系是否對應到你有更新的 lang

---

## 2026-04-02 — Approval mode 前後端脫鉤：按鈕/狀態被硬編碼

### 問題現象
- `approval_mode`（手動/自動）由後端場次設定提供，但學員端 UI（例如按鈕文字與狀態標籤）曾出現硬編碼預設行為，導致：
  - 手動模式仍顯示「立即報名」
  - 狀態標籤使用硬寫英文（如 Pending Review / Rejected），未完全由後端狀態 + 語系字串驅動

### 根因判斷
- 前端渲染邏輯未把後端的 `approval_mode` 與 enrolment 狀態做「對應映射」，而是以硬編碼字串/預設文字顯示。

### 解決方式
- 強制 UI 完全由後端配置字段驅動：
  - `local_tm_course/index.php`
    - 按鈕文字：依 `$s->approval_mode` 切換
      - Manual：顯示「申請報名」
      - Automatic：顯示「立即報名」
    - 狀態標籤：改用 `get_string('enrol_*')` 對應 `enrolment.status`，移除硬編碼英文

### 未來如何避免（核心準則）
- 「前端 UI 狀態必須完全由後端配置欄位驅動，禁止硬編碼預設行為。」
- 每當新增/調整後端配置項（例如 `approval_mode`），在前端必須同步完成完整 mapping 檢查（按鈕文字、提交行為後的狀態呈現、badge/標籤內容）。

---

## 全域維運規定（文件同步）

- 每當開發新的模組/頁面/流程（包含新增入口 URL）時，必須自動評估是否需要更新 `links.md`。
- 若新模組提供可操作入口（一般使用者或管理者），必須在 `links.md` 新增對應連結與使用角色分類。
- 每當未來發生新的 issue，必須在 `lesson-learned.md` 新增一條「開發問題摘要」，至少包含：
  1. 問題現象
  2. 根因判斷
  3. 解決方式
  4. 預防準則（下次如何避免）

---

## 2026-04-02 — 報名互斥與手動審核流程異常（邏輯脫鉤）

### 問題現象
- 學員報名某場次後，其他場次被前端直接顯示為「已額滿」，而不是保留報名按鈕並在提交時提示互斥原因。
- 手動審核模式下，學員送出後仍被自動核准。
- 場次管理缺少「同日同教室」防呆提示。

### 根因判斷
- 前端把「同課程互斥」硬套成 UI 隱藏/滿額狀態，與真實容量條件混在一起。
- `approval_mode` 比較使用嚴格型別比較（`===`）時，DB 回值型別不一致導致判斷失效。
- 先前僅做時間區段衝突，缺少「同日同教室」的業務規則檢查。

### 解決方式
- 同課程互斥：前端保留報名按鈕，改由後端防呆拋錯並回傳「已報名日期」提示。
- 手動審核：`approval_mode` 先轉 `(int)` 再判斷，確保 manual 進入待審核。
- 新增同日同教室檢查，儲存場次時若同日同教室已有場次直接阻擋。
- 管理端補上手動核准時的桌次分配欄位與後端桌次容量檢查。

### 未來如何避免
- 互斥規則與容量規則分離：互斥不可用「已額滿」假象表示。
- 所有 DB 設定值在業務判斷前先做型別正規化（例如 `(int)`）。
- 新增審核流程時，必做端到端驗證（學員提交狀態、管理端核准操作、桌次顯示同步）。

---

## 2026-04-07 — 取消報名按鈕缺乏防呆，易誤觸造成不可逆狀態變更

### 問題現象
- 學員端原本按下「取消報名」會直接送出。
- 雖有簡單 confirm，但沒有結構化原因收集，且誤觸成本高。

### 根因判斷
- 取消流程未採用「二階段確認 + 必填理由」模式。
- 前後端對取消原因沒有資料欄位與驗證規範。

### 解決方式
- 前端改為 Modal 流程：先選擇取消原因，再執行提交。
- 原因為單選必填；選「其他」時文字輸入必填。
- 後端新增驗證，避免繞過前端直接送空值。
- 資料表新增欄位儲存取消原因：`cancel_reason_code`, `cancel_reason_text`。

### 未來如何避免
- 對「取消、刪除、拒絕、關閉」等不可逆或破壞性操作，預設必須有二次確認。
- 若屬使用者主動取消流程，應要求最少一個可統計的原因欄位。
- PR 檢查清單加入一條：`destructive action requires confirmation + server-side validation`。

---

## 2026-04-08 — 審核核准後分組未必同步、zh_tw 字串覆蓋不完整

### 問題現象
- 管理者核准報名後，部分情境下學員沒有被自動加入該場次既有分組。
- 畫面仍出現英文文案，表示 `zh_tw` 沒有完整覆蓋語言 key，系統回退到 `en`。

### 根因判斷
- 核准流程雖有 `add_to_group()`，但當場次 `groupid` 尚未建立時會直接跳過，缺乏「先確保資源存在」步驟。
- `lang/zh_tw/local_tm_course.php` 先前僅是部分覆寫，非完整語言包，導致未定義 key 自動回退英文。

### 解決方式
- 在核准流程（含自動核准分支）先呼叫 `attendance_manager::setup_session()`，確保分組資源存在，再執行 `add_to_group()`。
- 補齊 `zh_tw` 全量字串鍵，對齊英文語言包 key，移除介面英文回退。

### 未來如何避免
- 所有「核准後要同步資源」的流程都應採用「先 ensure，再 sync」模式，避免依賴先前狀態。
- 每次新增語言 key 必須同步盤點目標語系覆蓋率，避免只補部分 key 造成 UI 混語系。

---

## 2026-04-08 — XMLDB CHAR 欄位以空字串作為預設值，導致升級警告

### 問題現象
- 外掛升級（資料庫更新）後出現 XMLDB debugging 訊息：
  - `CHAR NOT NULL column (...) with '' as DEFAULT value`
- 升級流程仍可完成，但會在管理端顯示警告訊息，增加維運判讀成本。

### 根因判斷
- 在 `local_tm_course_perm_rule` 的欄位定義中，`ruletype` 與 `pattern` 使用了：
  - `TYPE=char`
  - `NOTNULL=true`
  - `DEFAULT=''`
- Moodle XMLDB 對此組合會視為不建議，並在升級時自動改寫為 DB 可接受值，同時拋出 debugging 提示。

### 解決方式
- 修正 `install.xml`：
  - 將 `ruletype`、`pattern` 改為可空（`NOTNULL=false`），且不使用空字串預設值。
- 修正 `upgrade.php`：
  - 建表路徑移除 `''` 預設。
  - 新增 upgrade step（2026040801），對既有站台調整欄位 notnull/default，避免後續再出現同警告。
- 版本號同步提升至 `2026040801`。

### 未來如何避免
- 設計 `CHAR/VARCHAR` 欄位時，避免 `NOT NULL + DEFAULT ''` 這種容易觸發 XMLDB 警告的組合。
- 欄位若無明確「業務上有意義」的預設值，優先採用可空並由應用層驗證必填。
- 每次新增資料表/欄位後，務必在測試環境實跑一次升級流程，檢查是否有 XMLDB debugging 訊息。

---

## 2026-04-08 — 通知發送觸發 email processor debugging（Error calling message processor email）

### 問題現象
- 管理者核准/拒絕後，頁面會出現：
  - `Error calling message processor email`
- 業務流程本身可繼續，審核狀態仍正常更新。

### 根因判斷
- M5 通知透過 `message_send()` 發送時，站台 email processor 端（寄件設定或收件條件）出現錯誤，導致 Moodle 印出 debugging 訊息。
- 原先使用當前登入者作為 `userfrom`，不同站台設定下穩定性較差。

### 解決方式
- 通知 helper 改為：
  - `userfrom` 一律使用 `core_user::get_noreply_user()`。
  - `userto` 改為完整 user object，並在通知物件中設定 `emailstop=1`，避免觸發 email processor 失敗噪音，同時保留 Moodle 站內通知能力。
  - `message_send()` 加入 try/catch，確保通知異常不阻斷審核主流程。

### 未來如何避免
- 新增通知功能時，先確認測試站 SMTP / message processor 設定是否完整。
- 對關鍵業務流程（核准、拒絕、報名）中的通知發送採「失敗不阻斷主流程」策略。
- 發送方優先使用系統 no-reply 帳號，避免使用者個人資料不完整造成處理器錯誤。

---

## 2026-04-09 — 課程連動預設課時「有存成功但頁面回顯舊值」

### 問題現象
- 在 `課程連動設定` 修改某門課的 `預設課時` 並儲存後，重新進入頁面仍顯示上一次數值，造成誤判為「沒存進 DB」。

### 根因判斷
- `settings/course_mapping.php` 的分類遞迴渲染 closure 未帶入 `$duration_by_course`，UI 回顯時取不到最新映射值，退回預設顯示。

### 解決方式
- 修正 closure `use (...)`，將 `$duration_by_course` 明確納入渲染作用域，確保每個 course row 顯示正確已儲存值。

### 未來如何避免
- PHP closure 內若需使用外層資料來源，必須在 code review 檢查 `use (...)` 是否完整。
- 對「設定儲存頁」新增最小驗證清單：`儲存成功提示`、`重新載入後回顯值一致`、`跨分類/遞迴列同樣一致`。

---

## 2026-04-09 — Auto 跨日情境下課時與時間顯示容易誤讀

### 問題現象
- 實體 Auto 場次跨日時，管理者容易看到「課時」與「起迄時間」感覺矛盾（例如課時 8h，但起訖跨日）。

### 根因判斷
- 需求上同時存在兩種時間概念：
  - `教學時數`（課程預設時數）
  - `日曆跨度`（包含午餐 + 跨日切分造成的實際起迄）
- 若 UI 未明示，使用者會把兩者視為同一指標而產生誤解。

### 解決方式
- 前台卡片在實體課時數下方加註：`備註：包含用餐時間 1 小時`。
- 跨日時段改顯示含日期的起訖格式，避免只看 `09:30 - 11:30` 造成同日誤判。

### 未來如何避免
- 涉及「教學時數 vs 日曆跨度」的場景，需求與 UI 必須同時定義術語與顯示規則。
- PR 驗收需包含跨日案例（start/end 跨天、前後台一致、文案提示是否可理解）。

---

## 2026-04-10 — 多外掛上架相依與打包層級不一致，導致 block 依賴檔案找不到

### 問題現象
- 上架 `tm_dashboard` 後出現 fatal：
  - `Failed opening required '/local/tm_course/classes/user_dashboard_helper.php'`
- 造成首頁區塊無法渲染。

### 根因判斷
- `block_tm_dashboard` 依賴 `local_tm_course`（helper 在 local plugin）。
- 部署時若先上 block、或 local plugin 實際目錄名稱/層級不符（例如不是 `local/tm_course`），就會找不到依賴檔案。
- 開發交付時缺少「打包哪一層」與「上架先後順序」的明確指示。

### 解決方式
- block 端增加依賴檢查與降級處理（避免直接 fatal）：
  - 優先找 `local/tm_course/classes/user_dashboard_helper.php`
  - 次要兼容找 `local/local_tm_course/classes/user_dashboard_helper.php`
  - 找不到時顯示可讀提示訊息。
- 版本號提升，確保站點升級時載入新保護邏輯。

### 未來如何避免（必做交付規範）
- 每次開發完成，回報中必須固定附上「部署指示」段落，至少包含：
  1. **打包對象與根層級**（zip 最外層資料夾名稱）
  2. **上架順序**（含相依關係）
  3. **升級後動作**（通知頁升級、清快取）
  4. **驗收清單**（上架後 2-3 個必驗項目）
- 涉及跨外掛依賴時，順序預設：
  - 先上被依賴外掛（例如 `local/tm_course`）
  - 再上依賴方外掛（例如 `blocks/tm_dashboard`）

---

## 2026-04-16 — `get_records_sql()` 首欄非唯一造成 Duplicate value notice

### 問題現象
- 月曆頁載入時出現：
  - `Duplicate value 'x' found in column 'classroomid'`

### 根因判斷
- Moodle `get_records_sql()` 會使用查詢結果「第一欄」作為回傳陣列 key。
- 原查詢第一欄誤用 `classroomid`（非唯一），導致重複 key notice。

### 解決方式
- 將查詢調整為第一欄固定使用唯一值（`id`），`classroomid` 保留為一般欄位。
- 同步修正 `reservation/calendar.php`、`reservation/plan_events.php` 相關查詢。

### 未來如何避免
- 所有 `get_records_sql()` 必加檢查：第一個 select 欄位必須為唯一鍵（通常 `id`）。
- code review 清單加入「Moodle DB API keying 規則」檢查項。

---

## 2026-04-16 — PHP Closure 作用域遺漏造成 `Function name must be a string`

### 問題現象
- 進入月曆直接報錯：
  - `Function name must be a string`
  - 並伴隨 `Undefined variable: nextweekdaystart`

### 根因判斷
- 匿名函式內呼叫外層 closure，未在 `use (...)` 中引入，變數在函式內不可見。

### 解決方式
- 補上 `use (&$nextweekdaystart)`（需要引用時使用 `&`），並重新驗證月曆載入流程。

### 未來如何避免
- 新增 closure 時，若內部需要外層變數，務必在 PR 檢查 `use (...)` 是否完整。
- 對關鍵頁面新增 smoke test：頁面可載入、按鈕可點擊、事件可拖曳。

---

## 2026-04-16 — FullCalendar `eventsSet` 內增刪事件造成 UI 卡死

### 問題現象
- 月曆載入後持續轉圈、按鈕無反應。

### 根因判斷
- 在 `eventsSet` callback 內呼叫會新增/刪除事件的函式，觸發 `eventsSet` 連鎖回呼，形成無限循環。

### 解決方式
- 移除 `eventsSet` 內的重建呼叫，改在 `events` 載入成功後以 `setTimeout(..., 0)` 單次觸發。

### 未來如何避免
- 禁止在 FullCalendar 的事件集合回呼中直接做 add/remove event。
- 若需重建 display-only 事件，改走「資料載入完成後單次重建」路徑。

---

## 2026-04-16 — 視覺展示事件誤參與衝突判斷，導致假衝突

### 問題現象
- 課程拖曳到看似可排的時段卻被阻擋（例如同日加總未超上限仍回彈）。

### 根因判斷
- `reservation_plan_group_display` 屬於視覺層事件，不應參與排程衝突；但先前未完整排除，造成 false positive。

### 解決方式
- 在 `collectDayClassroomIntervals`、`hasClassroomConflict`、`hasOverlapWithExcludes` 等判斷函式中統一排除 display-only event type。

### 未來如何避免
- 建立規則：`eventType=*_display` 一律不得參與業務邏輯運算。
- 新增拖曳回歸案例：同日接續、群組拖曳、display event 存在時仍可正確判斷。

---

## 2026-04-16 — 新增字串 key 未同步到伺服器，導致審核/追蹤頁出現 `[[key]]` 與 debug 訊息

### 問題現象
- 審核頁/追蹤明細頁出現：
  - `Invalid get_string() identifier: 'reservation_review_courses'`
  - `Invalid get_string() identifier: 'classroom'`
  - `Invalid get_string() identifier: 'label_end'`
- 畫面顯示 `[[reservation_review_courses]]`、`[[classroom]]` 等占位字串。

### 根因判斷
- 新增頁面直接使用 `get_string()`，但部署時語系檔可能未完全同步或快取尚未刷新。
- 一旦 key 缺失，Moodle 會在 debug 模式輸出錯誤並污染頁面。

### 解決方式
- 新頁面統一改為 `string_exists()` + fallback 文案策略。
- 針對高風險共用字串（如 `classroom`、`label_end`）同樣使用 fallback，避免單點缺字串炸頁。

### 未來如何避免
- 新增頁面上線前，先做「語系缺 key 模擬」測試（故意關閉新 key）確認頁面仍可用。
- 對關鍵流程頁（審核、追蹤、送出）預設採 fallback-safe 字串取得方式。

---

## 2026-04-16 — 表單 action 參數命名/型別不一致，造成「按鈕按了沒反應」

### 問題現象
- 客製審核頁按 `儲存備註/核准/駁回` 後僅重整或無明顯行為，狀態未更新（或看似未更新）。

### 根因判斷
- action 值使用自訂字串（如 `resv_note`）時，若參數清洗型別或判斷白名單不一致，可能導致分支不命中。

### 解決方式
- action 解析改為明確白名單判斷（`resv_note`/`resv_approve`/`resv_reject`）。
- 保留當前篩選參數回導，並在成功後顯示可見訊息，避免使用者誤判「沒作用」。

### 未來如何避免
- 所有多按鈕表單 action 必做：
  1. 白名單驗證
  2. 非法 action 顯式錯誤提示
  3. 成功訊息與狀態回顯

---

## 2026-04-16 — `Error calling message processor email` 干擾審核流程體驗

### 問題現象
- 核准/駁回後狀態實際已更新，但頁面跳出 email processor debug stack trace。

### 根因判斷
- 站點 message/email processor 設定不完整；`message_send()` 嘗試走 email 通道時觸發錯誤。

### 解決方式
- 審核通知改為站內通知優先，對接收者設 `emailstop=1`，避免 email processor 噪音。
- 保持「通知失敗不阻斷主流程」策略。

### 未來如何避免
- 通知相關功能預設採降級策略：
  - 先保證站內通知可達
  - email 通道視站台設定可用性啟用
- 核心業務流程永遠以資料更新成功為第一優先，通知為附加能力。

---

## 2026-04-16 — 升級前 `cURL Error 6` 容易被誤判為 DB 升級失敗

### 問題現象
- 升級頁顯示：
  - `cURL: Error 6 when calling https://download.moodle.org/api/...`

### 根因判斷
- Moodle 無法連線遠端插件資訊 API（DNS/網路問題），屬「遠端版本檢查」失敗，不是本地 DB migration 錯誤。

### 解決方式
- 可繼續執行本地升級；升級後再做功能回歸驗證。

### 未來如何避免
- 升級判斷規則要區分：
  - 遠端檢查錯誤（可繼續）
  - DB/XMLDB/SQL 錯誤（必須中止並修復）

---

## 2026-04-16 — 在 `before_standard_top_of_body_html` 使用 `$PAGE->requires` 會直接觸發 coding error

### 問題現象
- 首頁重整直接報錯：
  - `Cannot require a CSS file after <head> has been printed.`
- stack trace 指向 `local_tm_course_before_standard_top_of_body_html()` 內的 `$PAGE->requires->css(...)`。

### 根因判斷
- Moodle 的 `before_standard_top_of_body_html` hook 執行時機在 `<head>` 已輸出之後。
- 此時再呼叫 `$PAGE->requires->css/js` 會違反輸出時序，屬於硬性 coding error。

### 解決方式
- 在該 hook 中改用 `html_writer` 直接輸出 `<link>` / `<script src>`，並以前端延遲初始化（retry）方式等待 FullCalendar 可用。
- 嚴禁在此 hook 呼叫 `$PAGE->requires`。

### 未來如何避免
- 規則化：
  - `before_standard_html_head`：可以放 `$PAGE->requires` 或 head 資源。
  - `before_standard_top_of_body_html`：只輸出 HTML/inline script，不用 `$PAGE->requires`。
- 若有 JS/CSS 依賴，優先在一般頁面 controller（`$PAGE->set_*` 同區）載入。

---

## 2026-04-16 — Dashboard 設定頁新增字串 key，部署不同步時容易噴 `Invalid get_string()`

### 問題現象
- 升級可完成，但進入 `Dashboard顯示設定` 時出現大量：
  - `Invalid get_string() identifier: dashboard_role_user/sales/admin`
  - `Invalid get_string() identifier: setting_dashboard_role_heading`

### 根因判斷
- `settings.php` 直接呼叫新增 key 的 `get_string()`。
- 若伺服器語系檔（尤其 `lang/en`）未同步到同一版，或快取尚未刷新，就會在 admin settings load 時噴 debug。

### 解決方式
- `settings.php` 對 Dashboard 相關字串改採 `string_exists + fallback`（以 `$str()` 包裝）：
  - key 存在就用語系字串
  - key 缺失時顯示 fallback 文案，避免炸頁
- 部署後仍建議 `purge caches`。

### 未來如何避免
- 任何新增 admin setting 頁的 key，預設採 fallback-safe 取得方式。
- 發版自檢至少比對：
  1. `settings.php`
  2. `lang/en/local_tm_course.php`
  3. `lang/zh_tw/local_tm_course.php`
- 若三者不同步，優先修正檔案覆蓋，不要誤判為需重跑 DB 升級。

---

## 2026-04-21 — mod_attendance 整合：slot 對應、參數缺失與 acronym 長度限制

### 問題現象
- 外掛管理頁點「開啟 Moodle 出缺席點名頁」時，偶發：
  - `missingparam (sessionid)` 或 `missingparam (grouptype)`。
- 外掛頁面點「出席/缺席」後，本地狀態有更新，但課程 attendance 頁看不到勾選。
- 點名動作後頁面被 debug 輸出打斷，自動 redirect 失效（需按「繼續」）。
- 建立預設狀態時出現：
  - `Data too long for column 'acronym'`（不同站點欄位長度限制不一致）。

### 根因判斷
- `take.php` 連結參數不完整（未帶 `sessionid` 或 `grouptype`）。
- 同日多時段/多活動情境下，slot 或 activity 對應不夠精準，可能寫到錯目標。
- 使用 `debugging()` 直接輸出訊息，會破壞 Moodle redirect。
- `attendance_statuses.acronym` 在不同環境長度限制不同，完整字串（如 `Present`）可能超長。

### 解決方式
- 管理頁連結補齊 `id + sessionid + grouptype=0`。
- 點名前強制 `setup_session()`，並在同步時採：
  - 同 activity + 同日 + 同場次名稱優先找 slot。
  - 找不到則回查/重建 slot 後再寫入。
- 移除會污染畫面的 `debugging()`，改 `error_log()` 保留可追蹤性。
- 預設狀態建立採「description 保留 Present/Late/Absent 語意，acronym 依欄位限制安全降級」策略。

### 未來如何避免
- 所有整合到 core/mod 頁面的外連 URL，必做必填參數盤點（含語言切換情境）。
- 任何「同日可多時段」模組，DB 寫入鍵不得只靠日期，需加入可辨識場次的第二鍵（如 description/session key）。
- 關鍵操作流程（核准、點名、提交）禁止直接把 debug 輸出到頁面，避免中斷 redirect。
- 跨站部署需預設欄位長度差異，字串寫入策略要有 fallback（尤其第三方外掛資料表）。

---

## 2026-04-21 — Moodle XMLDB 表名長度上限（28）未先檢查，導致升級中斷

### 問題現象
- 升級時直接中斷並報錯：
  - `Invalid table name ... name is too long. Limit is 28 chars.`
- 錯誤發生於 `db/upgrade.php` 的 `create_table()` 階段，屬於硬性 coding error。

### 根因判斷
- 新增資料表時使用了過長名稱（`local_tm_course_check_questions`、`local_tm_course_resv_check_files`），超過 Moodle XMLDB 允許的 28 字元限制。
- 開發時未執行「DB 命名規則預檢」，導致問題到升級階段才暴露。

### 解決方式
- 將新表改為短表名並同步全域引用：
  - `local_tm_course_vq_q`
  - `local_tm_course_vq_file`
- 升級版號提升，確保修正後流程可重新執行。

### 未來如何避免（強制規則）
- **任何新增資料表/索引/欄位命名，實作前必做長度檢查**（特別是 table name <= 28）。
- 開發流程新增固定 Gate：`命名規範檢查 -> install.xml/upgrade.php 審核 -> 語法/升級測試`，任一未通過不得交付。
- 每次開始新需求前，先逐條檢查 `lesson-learned.md` 的既有規則，若有衝突必須先調整設計再動工。

---

## 2026-04-22 — `get_records_sql()` 與多筆附件列：第一欄非唯一導致除錯訊息與資料遺漏

### 問題現象
- 審核列表頁出現：`Duplicate value '54' found in column 'reservationid'`。
- 管理端看似只能看到「第一張」附件（其餘列被同一 key 覆蓋或未完整載入）。

### 根因判斷
- `get_records_sql()` 會以查詢結果**第一欄**作為回傳陣列的 key。
- 驗證附件查詢第一欄使用 `reservationid`，同一申請有多筆檔案列時 key 重複，觸發 debugging 並造成資料列丟失。

### 解決方式
- 改為 `get_recordset_sql()` 逐筆處理，或將第一欄改為唯一值（例如 `f.id`）；本次採用 recordset 載入後再組陣列。
- 另新增獨立「課前資料檢核」頁供管理員檢視全部附件（圖片內嵌、文件下載）。

### 未來如何避免
- 任何可能「一鍵多列」的 SQL，若使用 `get_records*`，第一欄必須唯一；否則一律用 `get_recordset_sql()` 或 `get_records_menu` 等適合 API。

---

## 2026-04-22 — 新增檔案上傳頁時的 Moodle 相容性與初始化時序問題

### 問題現象
- 檢核頁先後出現：
  - `Failed opening required .../lib/form/filelib.php`
  - `Class 'form_filemanager' not found`
  - `$PAGE->context was not set`（由 `format_string()` 觸發）
- 導致頁面顯示亂碼、redirect 中斷。

### 根因判斷
- 使用了錯誤的 core include 路徑與不相容 class（不同 Moodle 版本可用性不一）。
- 在 `$PAGE->set_context()` 之前先呼叫 `format_string()`，觸發 page context 未初始化。

### 解決方式
- 檢核上傳改採相容性較高的標準 file input + File API 儲存流程。
- 任何 `format_string()` 之前，先完成 `$PAGE->set_context()/set_url()` 初始化。

### 未來如何避免
- 新增頁面需先跑「初始化順序」檢查：
  1. `require_login()`
  2. `$PAGE->set_context()`
  3. 才允許呼叫 `format_string()` 等依賴 page context 的函式
- 新增檔案上傳 UI 時，優先使用跨版本穩定方案，避免依賴特定 class renderer。

---

## 2026-04-22 — 客製審核備註在狀態變更時被空值覆蓋

### 問題現象
- 管理員先前已填的審核備註，在核准/駁回操作後可能消失。
- 業務端追蹤明細看不到預期備註。

### 根因判斷
- 核准/駁回分支直接以當次輸入覆寫 `manager_note`，空輸入導致舊值被清空。
- 已核准/已駁回狀態缺少備註再編輯入口。

### 解決方式
- 狀態變更時若本次備註為空，保留既有 `manager_note`。
- 管理端在非 pending 狀態仍提供「備註編輯 + 儲存」。
- 業務端明細固定顯示 `審核備註` 欄位（空值顯示 `—`）。

### 未來如何避免
- 任何「狀態流轉 + 備註」資料欄位都要採「空值不覆蓋既有資料」預設策略（除非需求明確要求可清空）。
- 重要審核欄位需同時驗證：
  - pending -> approved/rejected 不遺失
  - approved/rejected 可再編輯
  - 申請端可正確回顯。

---

## 2026-05-07 — 新增資料表再次踩到命名長度上限（重複失誤）

### 問題現象
- 升級時發生：
  - `Invalid table name ... name is too long. Limit is 28 chars.`
- 造成管理端升級流程中斷，需緊急修表名並重跑升級。

### 根因判斷
- 雖然文件已有規範，但實作新表時未先做「表名 <= 28 字元」預檢，屬流程未落實。

### 解決方式
- 將過長表名 `local_tm_course_perm_att_rule` 改為 `local_tm_perm_att_rule`。
- 同步修正 `install.xml` / `upgrade.php` / 程式引用，確保升級可通過。

### 未來如何避免（強制 pre-flight，每次 DB 變更都要跑）
- 在開始任何 DB 變更（新增表、欄位、索引）前，**先跑這 5 點檢查**：
  1. 表名長度 <= 28（Moodle XMLDB 限制）
  2. 索引名長度與可讀性檢查
  3. `install.xml` 與 `db/upgrade.php` 命名一致
  4. 新舊升級路徑都可執行（全新安裝 / 舊版升級）
  5. 版本號與 savepoint 對齊
- 若任何一點未通過，禁止交付。

---

## 2026-04-27 — Moodle Admin tree 重複註冊（Duplicate admin page name）

### 問題現象
- 將 admin 選單抽成單一來源並在 `settings.php` 迴圈註冊時，曾出現 `Duplicate admin page name`，甚至連鎖影響 core 管理頁初始化。

### 根因判斷
- Moodle admin tree 初始化對「重複 `$ADMIN->add`」非常敏感；迴圈或條件分支稍有不慎就會重複註冊同一 page name。

### 解決方式
- `settings.php` 採 `$ADMIN->locate(...)` 等方式做**防重複註冊**保護。
- 優先使用顯式、可追蹤的 `$ADMIN->add(...)` 寫法，降低副作用。

### 未來如何避免
- 新增 admin 子頁前，先搜尋是否已有同名 node；合併設定頁時以「單次註冊 + locate 重用」為預設模式。

---

## 2026-04-27 — 導航 hook 內 `get_string()` 缺 key 導致全站關鍵頁初始化失敗

### 問題現象
- 新增導覽字串（例如 `nav_my_records`）後，主機語系檔未同步即出現 `Invalid get_string()`，首頁或管理頁無法正常載入。

### 根因判斷
- `extend_navigation` 等 hook 在每次請求早期執行；此處 `get_string()` 失敗會直接中斷導覽樹建立。

### 解決方式
- 導覽與關鍵頁標題改採 `string_exists()` + fallback 文案。
- 新增關鍵字串時至少同步 `lang/zh_tw` 與 `lang/en`，並保留 runtime fallback 作為部署空窗保險。

### 未來如何避免
- 任何會在 navigation hook 顯示的字串，預設走 fallback-safe；發版檢查清單含「故意刪除新 key 仍不炸頁」。

---

## 2026-04-27 — customcert：同課程多筆 issue 不可假設唯一

### 問題現象
- 同課程、同使用者可能有多筆已核發證書；使用 `get_record_sql()` 時拋出 `more than one record found`。

### 根因判斷
- 證書資料在實務上常有重發、多活動來源或歷史資料，**唯一鍵假設不成立**。

### 解決方式
- 單筆場景改 `get_records_sql(..., 0, 1)` 取最新一筆（或明確 `ORDER BY` + limit）。
- 清單場景提供全量查詢（例如依使用者列出 `customcert_issues`）。

### 未來如何避免
- 凡第三方模組「一對多」資料，禁用 `get_record*` 除非 SQL 保證唯一；code review 標註「證書 = course-level 多筆可能」。

---

## 2026-04-27 — 證書 UI 與業務語意不一致（場次列顯示下載）

### 問題現象
- 證書為課程層級成就，若放在「場次／報名」列，使用者易誤解為「該場次通過才可下載」，甚至出現「場次駁回卻仍可下載」的矛盾感受。

### 根因判斷
- UI 未反映資料模型（course-level vs session-level）。

### 解決方式
- `my_records.php` 獨立「證書清單」區塊；課程紀錄／報名紀錄與證書下載解耦。
- 搜尋頁證書欄位維持「有 issue 才顯示按鈕」，必要時走 `certificate_download.php` 代理權限。

### 未來如何避免
- 新列表欄位先問：資料主體是誰（user×course×session）？再決定欄位放置區塊，避免跨層級綁死。

---

## 2026-04-27 — Boost 左側導覽可見性：業務找不到「搜尋紀錄」入口

### 問題現象
- 部分站台僅管理員可見左側「管理」區塊；業務帳號左欄沒有預期選單，回報找不到搜尋／營運功能。

### 根因判斷
- 站台主題與角色導覽設定不一，**不可假設**所有登入者都看到同一套左側節點。

### 解決方式
- 業務常用入口改放在首頁 Dashboard 按鈕（具 `user_can_batch_enrol()` 時顯示「搜尋紀錄」）；`search.php` 權限邏輯與後端一致。
- 左側保留全員「我的上課與報名紀錄」等低爭議入口。

### 未來如何避免
- 角色專屬能力預設放在「首頁可見區」或明確 URL 說明文件；左欄僅作輔助，不作唯一入口。

---

## 2026-05-12 — 課前資料檢核：後端誤擋送出、管理端看不到缺檔、業務端審核狀態看似未更新

### 問題現象（使用者回報彙整）
1. **規格要求「可先略過、後續補件」**，但送出第 3 步時被後端擋下，必須當下傳齊必填檔才能進審核。
2. 管理端檢核頁只列出已有檔案的題目，**缺檔題目完全不顯示**，無法掌握「業務還缺什麼」。
3. 管理員已按「未通過／通過」，業務在追蹤明細仍看到「待審核」或舊畫面，懷疑寫入失敗。

### 根因判斷
1. `verification.php` 誤把 `validate_required_uploaded()` 當成送出門檻，與 §35「不阻擋申請」規格衝突。
2. `reservation_verification_review.php` 以 `vq_file` 反查為主，沒有檔案的題目不進渲染迴圈。
3. 多數為 **瀏覽器快取／bfcache／同分頁未重新整理**：DB 已寫入 `review_status`，但業務頁仍是進入審核前載入的快照；另需區分「管理員其實未成功存到該題」（silent fail 或誤觸別題）。

### 解決方式
1. **後端**：移除送出時以「必填檔案齊全」阻擋；一律寫入已選檔、`calendar_plan_submitted = 1` 並導回申請列表；未齊檔由 §43 排程與 §41 提醒承接。
2. **前端**：若有必填題既無已存檔、本次也未選新檔，攔截 submit 顯示**缺件確認 modal**（同意後帶 `acknowledge_incomplete` 再送）；與「自動取消」期限文案對齊 deadline 計算。
3. **管理端**：改以 `verification_manager::get_questions_for_courses()` 列出全部題目，無檔案列顯示「尚未上傳」與總覽進度條；empty state 改為「無任何題目」才顯示空狀態。
4. **業務端可觀測性**：`tracking_detail.php` 回應加 `Cache-Control: no-store`；已審項目顯示「審核於 YYYY-MM-DD HH:MM」；管理端成功訊息帶**題目名稱 + 通過/未通過**，找不到檔案列時改 `NOTIFY_ERROR` 並帶題名。

### 未來如何避免
- 「可選／可後補」流程不得在後端用「必填上傳」當唯一 gate；gate 應與規格文件同一詞（送出 vs 開課前補齊）。
- 管理列表若資料來自「有子表才出現」，必問：空集合時父層題目是否仍應顯示？
- 跨角色協作頁面：預設加 no-store 或版本參數，並在 UI 露出「最後審核時間」以利排除快取誤判。

---


