# Changelog

本檔案記錄 `local_tm_course`（TM Course Management Plugin）的版本變更。
格式參考 [Keep a Changelog](https://keepachangelog.com/)，版本號對應 `local_tm_course/version.php` 的 `$plugin->release`。

## [5.19.3] - 2026-09-03 - 業務可看全部視訊連結按鈕

### Changed
- 具批次報名權限者（capability 或指定權限規則）可於前台場次列表直接看到所有「視訊且已填連結」場次的「加入視訊課程」按鈕，不需本人已報名。

### Docs
- `docs/SPEC.md` §13、`docs/FEATURE_LOG.md`、`README.md` 角色權限。

## [5.19.2] - 2026-09-01 - 逾期提醒可啟用／關閉

### Added
- 通知與自動化設定新增「啟用逾期提醒」checkbox（`reminder_threshold_enabled`，預設開啟）。
- 關閉後，`remind_pending_enrolment` 排程與通知 helper 不會發送逾時未審提醒；開啟時仍依「逾期提醒時間閾值」判斷。

### Docs
- `docs/SPEC.md` M5-N3、`docs/FEATURE_LOG.md`。

## [5.19.1] - 2026-08-27 - 點名名單姓名可連到 Moodle 個人檔

### Added
- 上課準備／點名畫面（`class_prep.php`）：有真實帳號的學員姓名改為連結，開新分頁至 `/user/profile.php`，方便講師查看信箱等基本資料。
- 僅出現於點名畫面（頁面已以 `user_can_attendance()` 把關）；「查看報名狀況」等其他名冊不變。
- 純卡位（無 `linked_userid`）維持純文字、不設連結。

### Docs
- `docs/SPEC.md` §57、`docs/FEATURE_LOG.md`。

## [5.19.0] - 2026-08-26 - TCMS 同步改指向公司 VM

### Changed
- TCMS 預設 API base URL 改為 `https://tcms.tm-robot.com`（API 根網址；不可含前端 `/Project/`）。
- 升級時若仍為舊 Firebase 預設或含 `tcms-e49a5`／誤填 `/Project/`，自動遷移／正規化。
- CORS 白名單改允許 VM origin `https://tcms.tm-robot.com`（移除 Firebase Hosting 網域）。
- 補同步排程 `sync_tcms_sessions` 改為每小時 `:15` 醒來，仍由 `tcms_sync_reconcile_interval` 決定是否真正對帳（「每小時／每 6 小時」設定才有效）。
- Payload 增補 `source=moodle`；持續傳送 `customerNames`／`customerCount`／`studentCount`／`studentsReached` 等既有欄位。
- Schema 讀取失敗仍走快取／fallback，不阻擋場次同步。

### Added
- `classes/tcms_endpoint.php`：URL／Bearer header／必填 payload key 純函式。
- `tests/tcms_sync_test.php`、`scripts/tcms_sync_unit_test.php`。

## [5.17.5] - 2026-08-10 - 實體額滿改回人數制／Admin 可無視額滿

### Changed
- 實體場次 `STATUS_FULL` 改以「已核准人數 ≥ 建議名額（桌×每桌人數）」判斷，不再以「每桌至少一人」關門。
- 學員自主報名與業務批次：人數尚未額滿即可報名；額滿後關閉。
- 具 `local/tm_course:manage` 的管理員路徑（手動批次、專屬班核准寫入等）可無視額滿與截止／關閉。
- 升級 `2026081001`：重算既有 OPEN/FULL 場次狀態。

### Docs
- `docs/SPEC.md` §56。

## [5.16.2] - 2026-07-16 - 首次匯入 GitHub（Baseline）

這是本外掛第一次納入 Git 版本控制，過去的開發歷史都記錄在
`local_tm_course/db/upgrade.php`（目前累積 120+ 個版本節點，自 2026-03-31 起）。
以下為匯入當下的功能總覽與近期重點，之後每次更新版本，請在本檔案最上方新增一個條目。

### 功能總覽

- **場次管理**：實體/線上課程場次建立、批次建立、跨日切分、教室與桌位管理
- **報名流程**：一般報名、批次報名、報名審核（核准/拒絕）、取消/重新報名
- **前置課程規則**：AND/OR 條件、指定活動、TM 已啟用課程的前置檢查
- **專屬開班預約（Dedicated Class Reservation）**：3 階段流程（基本資料 → 月曆編排 → 課前資料檢核），支援草稿/正式送出
- **課前資料檢核**：依課程分群出題、授課型態分流、圖片/文件審核（通過/未通過、狀態持久化）
- **出缺席管理**：簽到名冊、Bento 通知整合、午餐登記
- **通知系統**：Email + Bento 通知、課前提醒、範本編輯器
- **憑證（Certificate）**：整合 `mod_customcert` 產生課程證書
- **TCMS 整合**：欄位對應、Phase 1 同步欄位、下拉選單對應課程類型/教室，管理端「立即同步至 TCMS」按鈕
- **權限管理**：角色（Admin / Sales / Learner）與自訂規則（idnumber / institution / name_contains）
- **多語系**：en / zh_tw / zh_cn / de / fr / ja / ko

### 近期重點（節錄自開發紀錄）

- 2026-07：TCMS 同步 Phase 1 欄位、下拉選單對應、立即同步按鈕
- 2026-06：前置課程規則 JSON 化、專屬開班批次報名審核連結、出缺席名冊 + Bento 午餐登記
- 2026-05：預約行事曆排程鏈接（chain-after-previous）、線上教室對應、場次名冊查看
- 2026-04：實體課程 Auto 模式自動排課、課程連動預設課時、跨日場次顯示優化

### 環境資訊

- Moodle 相容性：3.9+（已於 3.10.4 / PHP 7.2.24 測試）
- 授權：GNU GPL v3 or later

---

## 2026-04-22

### Added
- 專屬開班預約流程升級為 3 階段：`基本資料 (1/3)` → `月曆編排 (2/3)` → `課前資料檢核 (3/3)`
- 課前資料檢核題目依「所選課程」分群呈現，並套用授課型態分流（onsite/online/both）
- 管理端新增獨立「課前資料檢核」審核頁：圖片縮圖 + 新分頁開原圖、Word/PDF 等檔案下載、每檔可標記 `通過 / 未通過`、狀態持久化（離開再回來可續審）
- 業務端「申請明細」新增課前資料檢核結果區塊：顯示每題檔案與審核狀態，支援同一申請直接重傳單題檔案（重傳後狀態重置為待審核）

### Changed
- 客製需求審核備註優化：核准/駁回不再因空值覆蓋既有備註；管理員在已核准/已駁回狀態仍可編輯並儲存備註；業務端明細可持續看到最新審核備註
- 語系穩定性提升：多語系 key 已與 `en` 對齊補齊；關鍵頁面採 `string_exists + fallback`，避免部署未同步時噴 `Invalid get_string`

---

## 2026-04-09

### Added
- 實體課程切換為 Auto 模式時：自動帶入開始時間 `09:30`；依「關聯 Moodle 課程」預設課時 + 午餐 1 小時計算結束日期/時間；套用 `physical_daily_limit` 進行跨日切分
- 前台開課場次卡片（實體）顯示備註：`備註：包含用餐時間 1 小時`

### Fixed
- `課程連動設定` 的「預設課時」儲存後可正確回顯（不再回退舊值）
- 跨日場次在前台顯示含日期的起訖時間，避免誤讀

---

## 版本紀錄格式（未來更新請依此格式新增條目）

```
## [x.y.z] - YYYY-MM-DD

### Added
- 新功能

### Changed
- 既有功能的調整

### Fixed
- Bug 修正
```
