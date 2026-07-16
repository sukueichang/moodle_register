# Changelog

本檔案記錄 `local_tm_course`（TM Course Management Plugin）的版本變更。
格式參考 [Keep a Changelog](https://keepachangelog.com/)，版本號對應 `local_tm_course/version.php` 的 `$plugin->release`。

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
