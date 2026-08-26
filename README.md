# TM Course Management Plugin (`local_tm_course`)

這個外掛提供 Techman 實體/視訊課程的場次管理、報名審核、批次報名、課程連動與自動時段計算。

> 📋 完整版本異動歷史請見 [`CHANGELOG.md`](CHANGELOG.md)；GitHub 上的 [Releases](../../releases) 也會對應每個版本的發布說明與打包檔案。

## 功能總覽

- 場次管理：實體/線上課程場次建立、批次建立、跨日切分、教室與桌位管理（含實體 Auto 模式自動排課、跨日切分、含用餐時間計算）
- 報名流程：一般報名、批次報名、報名審核（核准/拒絕）、取消/重新報名
- 專屬開班預約：3 階段流程（基本資料 → 月曆編排 → 課前資料檢核），支援草稿/正式送出、依課程分群出題、審核狀態持久化
- 出缺席管理、通知系統（Email + Bento）、憑證產生、TCMS 整合、權限管理、多語系（en / zh_tw / zh_cn / de / fr / ja / ko）

詳細功能清單與每次更新內容，請見 [`CHANGELOG.md`](CHANGELOG.md)。

## 角色與權限區分（目前版本）

### 管理者（Admin / Manager）

主要能力（通常具備）：

- `local/tm_course:manage`：場次管理（新增/編輯/刪除/狀態/批次建立）
- `local/tm_course:approve`：審核報名（核准/拒絕）
- `local/tm_course:viewall`：查看全部報名紀錄
- `local/tm_course:batchenrol`：批次報名

管理者特權：

- 可對已截止場次執行批次加入（admin override）
- 可使用權限規則管理頁：`/local/tm_course/settings/permissions.php`

### 業務（Sales）

主要判定：

- `permissions_manager::user_can_batch_enrol()` 為 `true`
  - 來源可為直接 capability，或命中規則（idnumber / institution / name_contains）

可做：

- 使用批次報名入口執行批次加入（開放中場次）

限制：

- 無 `manage` 時不可使用管理後台
- 已截止場次不可批次加入（僅管理者可 override）
- 一般情境不可查看全部報名（除非另授 `viewall`）

### 一般使用者（Learner）

主要能力：

- `local/tm_course:enrol`

可做：

- 前台瀏覽場次
- 報名 / 重新報名 / 取消報名

限制：

- 不可審核、不可批次報名、不可進入管理設定頁
- 已截止場次不可報名

## 文件

| 檔案 | 用途 |
|------|------|
| [`docs/SPEC.md`](docs/SPEC.md) | 規格書（Single Source of Truth） |
| [`docs/FEATURE_LOG.md`](docs/FEATURE_LOG.md) | 功能開發進度與決策 |
| [`docs/BUGFIX_LOG.md`](docs/BUGFIX_LOG.md) | Bug 回報與處理／回歸檢查（原 `lesson-learned.md`） |
| [`docs/DEV_WORKFLOW.md`](docs/DEV_WORKFLOW.md) | 開發流程 SOP（含驗收後 PR／push、Windows 打包 zip） |
| [`CHANGELOG.md`](CHANGELOG.md) | 版本異動歷史 |
| [`links.md`](links.md) | 入口 URL |
