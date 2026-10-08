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

## 2026-10-08 — survey_viz html_writer namespace

- **現象：** 5.28.6 即時結果頁 `Class 'local_tm_course\html_writer' not found`。
- **根因：** `survey_viz.php` 在 `namespace local_tm_course` 內未加 `\`，PHP 把全域 `html_writer` 解析成外掛類別。
- **修正：** 全部改 `\html_writer::`。無 DB。
- **版本／狀態：** **5.28.7／2026100802**。不 merge `main`。

## 2026-10-08 — 問卷結果圖表共用模組

- **需求：** Admin 結果頁與本場次即時結果共用圖表：單選／複選可切圓餅與長條；量表直條＋平均；自由文字雲與完整清單。不靠 CDN。
- **決策：** `survey_viz` 出卡片 HTML；`survey_viz.js` 畫本機 SVG／CSS。詞頻：英文單字＋中文詞組／二字。即時頁仍只吃 session snapshot。Excel／filter 流程不改。
- **版本／狀態：** **5.28.6／2026100801**。PHPUnit 本機未跑。不 merge `main`。

## 2026-10-08 — 本場次即時問卷結果

- **需求：** class_prep 課後問卷可看「目前 session」即時統計（四題型）；約 9 秒輪詢；回覆數不再用總回覆／應填當完成率。
- **決策：** 權限沿用 `user_can_attendance()`；`survey_live.php` 只接受 sessionid，聚合走 `survey_stats::session_live_snapshot()`（不含 email／userid／個別 response）。Admin `survey_results` 不改。無 DB。
- **版本／狀態：** **5.28.5／2026100800**。PHPUnit 本機未跑。不 merge `main`。

## 2026-10-07 — Bento history fullname() debug warning

- **現象：** 5.28.3 驗收時歷史顯示正常，但 `fullname()` 因 user 缺姓名欄位噴 debug warning。
- **修正：** `get_send_history()` 改用 `get_all_user_name_fields(true)` 選取欄位。無 DB schema 變更。
- **版本／狀態：** **5.28.4／2026100704**。不 merge `main`。

## 2026-10-07 — 便當發送歷史＋問卷安全刪除

- **需求：** (1) 便當需求成功發送後在 `class_prep` 顯示多筆歷史（時間／發送者）；(2) Survey List 可刪未使用問卷，有 pin／response 則阻擋並提示停用。
- **決策：** 新增表 `local_tm_course_bento_log`（sessionid／userid／timecreated／recipientcount）；僅 `sent > 0` 寫入。刪除：`can_delete_survey`／`delete_survey` 級聯清 structure＋`svcrs`，有 `svpin`／`svresp` 拒絕。
- **影響範圍：** `db/install.xml`／`upgrade.php`、`bento_notification_manager`、`survey_manager`、`class_prep`、`admin/surveys.php`、lang、tests、SPEC、version **5.28.3／2026100703**。
- **版本／狀態：** **5.28.3** 功能 OK；fullname warning 見上則 5.28.4。不 merge `main`。

## 2026-10-07 — Survey UX／管理流程（不 silent 搶課、複製、層級 UI）

- **需求：** (1) 指定課程不得 silent 搶走其他問卷；(2) Admin 編輯器資訊層級（基本設定＋摺疊題卡）；(3) 清單複製問卷（僅結構、不複製課程／回覆／token）；(4) 學員填答 UI；(5) `class_prep` 課前作業／課後問卷分區。
- **決策：** `assign_course`／`set_course_assignments` 衝突時丟 `survey_error_course_assigned`，儲存前先 `assert_courses_assignable`；`copy_survey`＋`unique_copy_name`（`(副本)`／`(副本 N)`）；不改 DB schema、不碰 Excel／excellib；`class_prep` 開／關呼叫既有 token API。
- **影響範圍：** `survey_manager`、`admin/surveys.php`、`survey.php`、`admin/class_prep.php`、`styles.css`、lang en/zh_tw、tests、SPEC §59、version **5.28.2／2026100702**。
- **版本／狀態：** **5.28.2** Owner UX 驗收 OK；後續見上則 5.28.3。不 merge `main`。

## 2026-10-07 — Phase 4 Excel Export FAIL → Moodle excellib

- **Owner 驗收（5.28.0）：** Phase 3 **PASS**；Phase 4 篩選／題型統計 **PASS**；Phase 4 Excel **FAIL**（`Call to undefined function send_file()` at `survey_export.php`）。
- **根因：** 匯出腳本呼叫 `send_file()` 但未載入 Moodle `filelib.php`；且未使用規格要求的 `lib/excellib.class.php`。
- **修正：** `survey_export.php` 改 `MoodleExcelWorkbook` + `survey_stats::fill_moodle_excel_workbook()`；兩 sheet、同 filter；無頁面輸出以免損壞 xlsx。
- **版本／狀態：** **5.28.1／2026100701**；Excel 待 Owner 重新下載實測（不標 Owner PASS）。不 merge `main`。

## 2026-10-07 — 課程問卷 Phase 3+4（Email Quick Access／投影／統計）

- **需求：** QR 免登入以 email 填答；投影板與即時人數；管理端篩選／題目統計／Excel；補釘排程。
- **決策：** `svresp` 改 `(sessionid,versionid,email)` 唯一，`enrolid` 可 0＋`mapped`；新表 `svtok`（一場次一 token，regenerate 換字串）；`survey.php?t=` 免登入；FILL 需 token 啟用；QR 用 qrserver 圖（無 Composer）；xlsx 自寫 ZipArchive。不改 batch-account email／force-password。
- **影響範圍：** `survey_manager`、`survey.php`、`survey_stats`／`survey_xlsx_writer`／`qrcode_svg`、admin board／progress／results／export、`class_prep`、`lib` nav、`db/*`、`version` **5.28.0／2026100700**、tests、SPEC §59。
- **版本／狀態：** **5.28.0** — Owner：Phase 3 PASS；Phase 4 filter/stats PASS；Excel FAIL（見上則 5.28.1）。不 merge `main`。

## 2026-10-06 — Email Logo 改 Moodle 原生 theme/image.php

- **需求：** 多輪 Logo 破圖；停止 pix 直連／email_logo.php／pluginfile emaillogo／CID／data-URI；改用 Moodle `$OUTPUT->image_url`。
- **根因：** 先前 `<img src>` 指向非 Moodle 原生公開圖路徑，或站上 `pix/email` 未實際落地；data-URI 則被 Gmail 等客戶端擋掉／破圖。
- **決策：** Logo 走 `$OUTPUT->image_url('email/…', 'local_tm_course')` → `/theme/image.php/...`；TC 檔名改 `.jpg`（內容未重壓）；清掉上述 workaround；寄信維持 `email_to_user`。
- **版本／狀態：** **5.27.6（`2026100606`）；theme image URL 需站上檔案落地後 HTTP 實測 PASS，再請實寄。**

## 2026-10-06 — 5.27.4 信發不出去 → 恢復 email_to_user + data-URI Logo

- **需求：** 5.27.4 實寄後信件未送達。
- **根因：** 自訂 `get_mailer()` CID 路徑中 `$mail->send()` 失敗時多為回傳 false、不丟例外，未觸發 fallback。
- **決策：** 寄信改回 `email_to_user()`；Logo 用 HTML data-URI（內嵌原始圖 bytes），不依賴公開 URL／CID mailer。
- **版本／狀態：** **5.27.5（`2026100605`）；待實寄複測。**

## 2026-10-06 — Email Logo pluginfile filenotfound → CID 內嵌

- **需求：** 點開 pluginfile Logo URL 仍 `filenotfound`（stack：`lib.php` → `send_file_not_found`）。
- **決策：** 實寄改 **CID embed**（`get_mailer` + `AddStringEmbeddedImage`），信件不依賴公開 HTTP；並加固 `emaillogo` pluginfile（先於 login／context 檢查）。
- **版本／狀態：** **5.27.4（`2026100604`）；待實寄複測。**

## 2026-10-06 — Email Logo 第二輪 FAIL（endpoint 404）

- **需求：** 實寄仍破圖；強制改密碼已 PASS、勿動。
- **實測：**  
  `…/email_logo.php?name=tm_robot_logo` → **HTTP 404**、`Content-Type: text/html`、body 同不存在的 PHP（無 MoodleSession）→ **檔案未部署到站台磁碟**。  
  `styles.css`／`batch_enrol.php` 為 200。
- **決策：** Logo 改走核心 `pluginfile.php` + `lib.php` `emaillogo`（免登入）；`email_logo_assets` 內嵌原始圖 bytes 作後備；依 magic 回傳 `image/png` 或 `image/jpeg`（Training Center 檔名 png、內容 JPEG）。
- **版本／狀態：** **5.27.3（`2026100603`）；待實寄複測。**

## 2026-10-06 — Email 驗收 FAIL 修正（Logo 404 + 強制改密碼）

- **需求：** 整合 ZIP 人工驗收：兩 Logo 破圖；初始密碼可登入但未強制改密碼。
- **決策／根因：**
  1. 實測 `https://…/mymoodle/local/tm_course/pix/email/*.png` → **HTTP 404**（非 redirect login）；`styles.css`／`batch_enrol.js` 可 200。改以無登入的 `email_logo.php?name=…` 白名單讀取原始 `pix/email` PNG。
  2. Moodle 3.10 強制改密碼用 preference `auth_forcepasswordchange`，非 `mdl_user.forcepasswordchange`。新建帳呼叫 `set_user_preference(..., 1)`；link 既有帳不設。
- **影響範圍：** `email_logo.php`、`batch_account_created_email.php`、`enrolment_manager.php`、tests、verify script；version **5.27.2**。不改 Survey。
- **版本／狀態：** **5.27.2（`2026100602`）；待 Email 複測。**

## 2026-10-06 — 整合問卷 + 批次建帳 HTML Email

- **需求：** `feature/course-survey-admin`（5.27.0）與 `feature/batch-account-html-email`（5.25.1）平行 diverged；需單一 ZIP 同時含問卷 Phase 1/2 與品牌 HTML 建帳信。
- **決策：** 以 survey tip 建 `feature/course-survey-admin-integrated`，merge email 分支；`version` 升至 **5.27.1 / 2026100601**；`upgrade.php` 依序保留 `2026100152`（email）→ survey `2026100200`／`2026100600` → 整合 savepoint。
- **影響範圍：** merge 衝突解於 `version.php`、`upgrade.php`、`FEATURE_LOG.md`；lang／功能檔並存。
- **版本／狀態：** **5.27.1 整合完成，待 Owner 用 ZIP 人工驗收。** 不 merge `main`。

## 2026-10-06 — 課程問卷 V1 階段 2（學員填答）

- **需求：** Phase 1 Owner 驗收 PASS 後，依 SPEC §59 實作學員填答：`my_records` 入口（含 `linked_userid`）、`survey.php`、開放／釘選、`enrolid` 唯一提交、送出後唯讀。
- **決策：** 不以出席為條件；開放看 `svpin.opens_at`／`starttime`；`ensure_session_survey_pin` 掛在問卷頁與 `my_records`；`lock_pin_before_starttime_edit` 接到 `edit_session` 存檔前。不做 QR／投影／統計／Excel。
- **影響範圍：** `survey_manager`、`survey.php`、`my_records.php`、`enrolment_manager::get_user_records`、`edit_session.php`、tests、語系、`version.php`。無新資料表。
- **版本／狀態：** **5.27.0（`2026100600`）交付待 Owner Phase 2 人工驗收。** PHPUnit 本機無 Moodle 環境則不得記 PASS。不合併 `main`。

## 2026-10-06 — 批次建帳通知 HTML Email

- **需求：** `batch_account_created` 純文字信要求「先登入再改密碼」卻未顯示初始密碼；改為品牌化 HTML（雙 Logo、帳號／密碼醒目、按鈕），並保留 plain-text。
- **決策：**
  1. 固定系統 HTML layout + plain fallback；主旨／收件仍可由 Admin 設定；既有 body config 不刪、寄信不再用。
  2. Logo 放 `pix/email/`，以 `/local/tm_course/pix/email/...` 公開 URL 載入（不需登入）。
  3. 不改建帳、隨機密碼、`forcepasswordchange`、learner＋submitter 同信含密碼。
- **影響範圍：** `batch_account_created_email.php`、`notification_helper.php`、`settings/notifications.php`、語系、`pix/email/*`、測試、version **5.25.1**。
- **版本／狀態：** **5.25.1（`2026100152`）；進行中（待 Email 實寄驗收）**

## 2026-10-06 — 問卷階段 1 Owner 驗收 PASS

- **需求：** Phase 1 管理端人工驗收（含選項多行、題型動態欄位、「其他」不重複）。
- **決策：** 記錄為 **PASS**。已知：本機未跑 PHPUnit；版本凍結／場次釘選完整整合驗收留待 Phase 2 有真實開放與提交流程後一併做。
- **影響範圍：** 僅文件（FEATURE_LOG／SPEC 狀態）。
- **版本／狀態：** **Phase 1 已驗收（5.26.0）。** 進入 Phase 2。

## 2026-10-05 — 問卷階段 1 驗收 UI 修正（選項多行／依題型顯示）

- **需求：** Phase 1 人工驗收：選項「一行一個」實為單行 input；所有題型同時顯示量表／其他／選項欄位。
- **決策：** 選項改 textarea（後端按行解析；「其他」仍只靠 allowother，不寫進選項列）。管理 UI 依題型即時顯示／隱藏欄位（隱藏不 disabled，避免誤清值）。單選與複選皆可允許「其他」（同步更新 SPEC §59）。
- **影響範圍：** `admin/surveys.php`、`survey_manager`、tests、SPEC／FEATURE_LOG。無 DB／version 變更。
- **版本／狀態：** **5.26.0；Owner 複測後於 2026-10-06 記 PASS。**

## 2026-10-05 — 問卷分支整合 main 5.25.0

- **需求：** `feature/course-survey-admin` 落後 `main` 21 commits；測試部署前必須帶入設備檢查／午休／TCMS 授課語言等既有功能，並保留問卷階段 1。
- **決策：** merge `origin/main`；`version` 改 **5.26.0 / 2026100200**（高於 main 的 `2026100151`）。升級順序保留 main 的 2026091700–2026100100，再跑問卷建表 2026100200。`install.xml` 同時保留 equipment resolution 欄位與 11 張 survey 表。
- **影響範圍：** `version.php`、`db/upgrade.php`、`db/install.xml`、FEATURE_LOG／SPEC；問卷與 main 功能碼並存。
- **版本／狀態：** **整合完成，待測試站人工驗收。** 未合併 `main`、未開階段 2。

## 2026-10-02 — 課程問卷 V1 階段 1（管理端與版本）

- **需求：** 可設定的課程問卷。規格見 [`SPEC.md` §59](SPEC.md)。本階段只做 Admin 管理、題型、課程指定、啟用停用、版本與資料表。
- **決策：** 題目不寫死；不以已出席作為填寫條件（填答在階段 2）；送出後不可修改（階段 2）。一門課一筆指定。版本被釘選或已有提交後凍結，再儲存開新版本。釘選函式與開始時間稽核已實作並測試，尚未接到場次編輯頁。
- **影響範圍：** `survey_manager`、`admin/surveys.php`、`db/install.xml`、`db/upgrade.php`、`version.php`。不含學員頁、QR、統計、Excel。
- **版本／狀態：** **階段 1 實作完成；分支已 rebase／merge 於 main 5.25.0 之上，整數版號為 5.26.0（`2026100200`）。** 待 Owner 決定是否進入階段 2。不合併 `main`。

## 2026-10-02 — TCMS 同步新增授課語言

- **需求：** Moodle 場次已有 `teaching_language`（`zh_tw` / `en`），同步到 TCMS 的 payload 沒有帶。要在既有 `POST /api/integrations/moodle/sessions` 加上 `teachingLanguage`，原值傳送，不另做語言欄位、資料表或設定畫面。
- **決策：**
  1. 只改 `tcms_sync_manager::build_payload()`，把場次 `teaching_language` 放進 `$core` 的 `teachingLanguage`，因此會納入 `_hash`；送出前仍拿掉 `_hash`。
  2. 既有重送沿用：場次建立／修改後的 `push_session()`、場次列表單筆 `tcms_resync`、立即對帳與排程 `reconcile_all()`。失敗仍標 `error`，對帳會再送。不另做批次同步。
  3. 身份仍是 `moodleSessionId`，不因新欄位新增場次。
- **影響範圍：** `tcms_sync_manager.php`、`tcms_endpoint.php`（必送欄位清單）、`tests/tcms_sync_test.php`、SPEC §0.4a、CHANGELOG。不改 TCMS。
- **版本／狀態：** **已驗收，合進 `main` 為 5.25.0（`2026100151`）。** `main` 當時已是 5.24.9（`2026100100`）。這個整數高於 5.24.9，也高於先前 ZIP 的 `2026100150`，並低於問卷分支的 savepoint `2026100200`。不包含問卷，也不改既有場次與報名資料。

## 2026-09-18 — class_prep 語系切換丢失 sessionid

- **需求：** Language menu 切換後不應 missingparam sessionid。
- **決策：** `$PAGE->set_url()` 帶入 `sessionid`（Moodle 語系導向用 `$PAGE->url`）。
- **影響範圍：** `admin/class_prep.php`；version **5.24.5**。
- **版本／狀態：** **5.24.5；進行中（待測試環境人工驗收）**

## 2026-09-18 — 設備檢查：儲存改 AJAX、不整頁 reload

- **需求：** 儲存後保持展開桌次／scroll／表單狀態；短 toast「已儲存」；失敗不清表單。
- **決策：** `equipment_save` / `equipment_save_all` 支援 `ajax=1` JSON；前端 fetch；「套用到其他桌」仍 POST+redirect。
- **影響範圍：** `class_prep.php`、`equipment_check.js`、styles、lang；version **5.24.4**。
- **版本／狀態：** **5.24.4；進行中（待測試環境人工驗收）**

## 2026-09-18 — 設備檢查：異常才展開備註／Checklist／ⓘ；後台 textarea 不消失

- **需求：** 正常不顯示備註；異常才 Checklist + ⓘ + 備註；後台編輯匯入文字不可一輸入就清空。
- **決策：**
  1. status 項把 remark／resolution checklist／ⓘ 收進 `data-equip-abnormal-panel`，僅 abnormal 顯示；切回正常不清 DOM。
  2. ⓘ 移到「異常排除」標題旁（無 checklist 時仍可單獨顯示）；不做 checkbox。
  3. 後台 textarea：改 DOM `.value` 綁定；placeholder 改短提示，避免與 Excel 範例混淆。
- **影響範圍：** `equipment_check_partial.php`、`equipment_check.js`、`equipment_check_items.php`、styles、lang、tests；version **5.24.3**。
- **版本／狀態：** **5.24.3；進行中（待測試環境人工驗收）**

## 2026-09-17 — 午休 12:00–13:00 + 文案方案 A

- **需求：** 試算若與台灣 12:00–13:00 重疊則 +1h，否則不加；場次資訊僅實際含午餐時顯示備註（A）。
- **決策：** 共用 `interval_overlaps_onsite_lunch()`（Asia/Taipei）；`session_includes_lunch_note()` 依牆鐘區間。
- **影響範圍：** `session_manager.php`、`index.php`、tests、lang；version **5.24.2**。
- **版本／狀態：** **5.24.2；進行中（待測試環境人工驗收）**

## 2026-09-17 — 下午場 Auto 誤加午休（bugfix）

- **需求：** 13:30 起、課時 2.5h 不應結束於 17:00。
- **決策：** `calculate_session_times()` 與 segment planner 共用 `onsite_segment_lunch_hours()`（跨 12:30 才 +1h）。
- **影響範圍：** `session_manager.php`、`duration_calc`／編輯場次 Auto、reservation `build_reservation_onsite_block`；tests；version **5.24.1**。
- **版本／狀態：** **5.24.1；進行中（待測試環境人工驗收）**

## 2026-09-17 — 設備檢查：排除方法 Checklist + 外單位支援

- **需求：** Excel 新增「排除方法」「外單位支援」；status 異常時展開排除 Checklist；當次勾選寫入 log；ⓘ 顯示支援；Modal 可維護兩欄；不得因 wipe+reinsert 清空。
- **決策：**
  1. item：`resolution_methods`（JSON 字串陣列）+ `external_support`（TEXT）。
  2. log：`resolution_checked`（JSON 文字快照）；僅 ABNORMAL 保存，NORMAL/UNSET 清空。
  3. 排除方法依公版 LF + `1.` 編號拆行；超長明確 validation error，不 silent truncate。
  4. 前端切回正常暫不清 DOM checkbox；全部儲存再依最終狀態落庫。
- **影響範圍：** install/upgrade、manager、import、class_prep、partial/JS、settings modal/API、lang、styles、fixture `Moodle_equip_check_template_20260916.xlsx`；version **5.24.0**。
- **版本／狀態：** **5.24.0；進行中（待測試環境人工驗收）**

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

## 2026-09-23 — 批改申請：測驗「已評分」改看最新 attempt

- **問題：** 學員有較新、尚未人工評分的 quiz attempt 時，外掛仍讀成績簿舊分／繳交時間，誤顯示已評分（例如 90/90 + 繳交時間）。
- **決策：** quiz 以最新 finished attempt 的 `sumgrades` 為準（空＝待評；有值＝已評）；不沿用 gradebook 舊分。`requires_manual_grading()` 僅作輔助（明確 true 才壓成待評）；載入 attempt 失敗時不可整排待評。assign 仍用 gradebook。排程同步含已完成單據。
- **影響：** `grading_request_manager`、`request.php` 顯示、搜尋預覽、取消檢查、完成通知；SPEC §58.5。
- **版本：** 5.24.8。

---

## 2026-09-10 — 業務批改申請（作業／測驗派工）

- **需求：** 業務現況用郵件請 admin 去 Moodle 找某客戶的作業／測驗繳交、複製連結再轉給課程管理員批改，改完再用截圖回報。改成外掛派工：業務自己查已繳交並申請 → admin 分派（也可自己改）→ 同事從外掛進 Moodle 評分 → 成績回外掛給業務看。
- **決策：**
  1. 作業本體仍是 Moodle `mod_assign`／`mod_quiz`；外掛不做新題、不重做上傳／評分 UI。
  2. **一張單 = 多名學員 × 同一個**作業或測驗；範圍僅課程連動啟用課；活動清單只含**未對學員隱藏**的模組（不管開放時間／完成條件）。
  3. 沒交不能列入；搜尋姓名／email 才出已繳交名單（預設空），已勾的進購物車可累加。選填備註。
  4. 分派與「開始批改」獨立：分派＝信＋Dashboard／導覽數字；開始批改＝看得到該單的 admin 或被分派人跳 Moodle 原生評分。不搶單、不默默分派給自己。未分派單只在 admin 佇列。詳情狀態列顯示目前負責人與分派時間；改派後保留先前與目前紀錄。
  5. 分派對象＝該 Moodle 課已有批改權限者。Admin（`manage` 或 site admin）可分派／改派／駁回／自己改；**被分派同事不可再分派給別人**（即使帶有 `manage`，網站管理員除外）。同事不能關單。
  6. 完成＝每一列**目前繳交**已評分（測驗：最新 attempt 不可停在待人工評分；勿只看成績簿舊分）或「查無」終態；中間態 `3/5`。頁面重整即同步，另加排程（含已完成單重掃）。壞掉的人／活動只顯示查無，禁止開出 Moodle error。
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
