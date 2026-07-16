# TM Course Management Plugin (`local_tm_course`)

這個外掛提供 Techman 實體/視訊課程的場次管理、報名審核、批次報名、課程連動與自動時段計算。

## 近期重點（2026-04-09）

- 實體課程切換為 Auto 模式時：
  - 自動帶入開始時間 `09:30`
  - 依 `關聯 Moodle 課程` 預設課時 + 午餐 1 小時計算結束日期/時間
  - 套用 `physical_daily_limit` 進行跨日切分
- `課程連動設定` 的 `預設課時` 儲存後可正確回顯（不再回退舊值）
- 前台開課場次卡片（實體）顯示備註：`備註：包含用餐時間 1 小時`
- 跨日場次在前台顯示含日期的起訖時間，避免誤讀

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

- 規格主文件：`spec.md`（Single Source of Truth）
- 維運教訓：`lesson-learned.md`

## 近期重點（2026-04-22）

- 專屬開班預約流程升級為 3 階段：
  - `基本資料 (1/3)` → `月曆編排 (2/3)` → `課前資料檢核 (3/3)`
- 課前資料檢核題目依「所選課程」分群呈現，並套用授課型態分流（onsite/online/both）。
- 管理端新增獨立「課前資料檢核」審核頁：
  - 圖片縮圖 + 新分頁開原圖
  - Word/PDF 等檔案下載
  - 每檔可標記 `通過 / 未通過`
  - 狀態持久化（離開再回來可續審）
- 業務端「申請明細」新增課前資料檢核結果區塊：
  - 顯示每題檔案與審核狀態
  - 支援同一申請直接重傳單題檔案（重傳後狀態重置為待審核）
- 客製需求審核備註優化：
  - 核准/駁回不再因空值覆蓋既有備註
  - 管理員在已核准/已駁回狀態仍可編輯並儲存備註
  - 業務端明細可持續看到最新審核備註
- 語系穩定性提升：
  - 多語系 key 已與 `en` 對齊補齊
  - 關鍵頁面採 `string_exists + fallback`，避免部署未同步時噴 `Invalid get_string`
