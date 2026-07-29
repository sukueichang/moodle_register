# DEV_WORKFLOW — 開發／修 Bug SOP

> **文件角色：** 每次提需求或修 bug 時雙方（你與 AI）應遵循的標準流程。  
> **驗收訊號：** 你回覆 **`ok`**／**`測試無誤`**／**`測試無誤了`** → AI 才更新 PR 並 push 到 GitHub。  
> **相關：** [`SPEC.md`](SPEC.md)／[`FEATURE_LOG.md`](FEATURE_LOG.md)／[`BUGFIX_LOG.md`](BUGFIX_LOG.md)

---

## 0. 開工前（必做）

1. 讀 [`BUGFIX_LOG.md`](BUGFIX_LOG.md) 頂部「強制檢查清單」與近期條目。
2. 確認需求落在 [`SPEC.md`](SPEC.md) 或需先改規格再寫碼。
3. 在 [`FEATURE_LOG.md`](FEATURE_LOG.md) 追加「進行中」條目（需求＋初步決策）。

---

## 1. 提需求（功能）

| 步驟 | 你做 | AI 做 |
|------|------|--------|
| 1 | 說明目標、角色、驗收標準 | 釐清歧義；必要時對照 SPEC |
| 2 | 確認決策（或授權 AI 提方案） | 寫入 FEATURE_LOG；必要時更新 SPEC |
| 3 | — | 實作；遵守 BUGFIX_LOG 預防準則 |
| 4 | 在 Moodle 環境自行測試 | 依回報修正；問題寫入 BUGFIX_LOG |
| 5 | 回覆 **ok／測試無誤** | 見 §3 Git／PR／Push |

---

## 2. 回報 Bug

| 步驟 | 你做 | AI 做 |
|------|------|--------|
| 1 | 現象、重現步驟、期望行為 | 定位根因；對照 BUGFIX_LOG 是否舊疾 |
| 2 | — | 修復；**先**在 BUGFIX_LOG 記一條（現象／根因／解法／預防） |
| 3 | 回歸測試（含清單相關項） | 依回報再修 |
| 4 | 回覆 **ok／測試無誤** | 見 §3 |

---

## 3. 驗收後：Commit → PR → Push（僅在你說 ok 之後）

**原則：** 未明確要求 commit／push 前，AI **不**主動推遠端；本 SOP 以你的 **ok** 作為該輪工作的推送授權。

1. **整理變更**  
   - `git status`／`git diff`／近期 commit 風格  
   - 不納入 secrets；不改 git config  
2. **Commit**（訊息著重 why；必要時含版本 bump）  
3. **Push** 目前分支到 `origin`（`-u` 若尚未追蹤）  
4. **PR**  
   - 已有開啟中 PR：更新描述（Summary／Test plan），反映本輪 FEATURE／BUGFIX  
   - 尚無 PR：`gh pr create` 建立，並回報 PR URL  
5. **文件收尾**  
   - FEATURE_LOG 該節 →「已驗收」  
   - BUGFIX_LOG 已有條目則確認預防準則寫入檢查清單（若為新類型）  
   - 新入口 URL → 評估更新根目錄 `links.md`

### PowerShell 注意（Windows）

- Commit message 可用 here-string，避免 bash heredoc：
  ```powershell
  git commit -m @"
  訊息內容。

  "@
  ```
- 打包外掛 zip：用 `tar.exe`，內部路徑用 `/`（見 BUGFIX_LOG）。

---

## 4. Moodle 本機驗證建議（最短路徑）

1. 升版：Site administration → Notifications（遠端 pluginfo `cURL` 錯誤可忽略，繼續本地 upgrade）。
2. 對照本輪功能入口（`links.md`／admin 選單）。
3. 若動到 DB：確認 upgrade 成功、無 XMLDB／表名錯誤。
4. 若動到 AJAX：確認 context／sesskey／權限。
5. 專屬開班相關：學員數語意、視訊期望開始時間、週末時分。

---

## 5. AI 每次開發自我檢查（摘要）

- [ ] BUGFIX_LOG 清單已掃  
- [ ] FEATURE_LOG／必要時 SPEC 已更新  
- [ ] 語系 en + zh_tw  
- [ ] `version.php` 若需升級已 bump  
- [ ] 未在使用者 ok 前 push  
- [ ] 使用者 ok 後：commit → push → 更新／建立 PR → 回報 URL  

---

## 6. 文件地圖

| 檔案 | 用途 |
|------|------|
| [`SPEC.md`](SPEC.md) | 產品規格、目的、模組邊界 |
| [`FEATURE_LOG.md`](FEATURE_LOG.md) | 需求與決策時間軸 |
| [`BUGFIX_LOG.md`](BUGFIX_LOG.md) | 缺陷與回歸檢查 |
| [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md) | 本 SOP |
| [`../links.md`](../links.md) | 入口 URL |
| `local_tm_course/docs/SCHEDULING_REQUIREMENTS.md` | 面授排課細部規則（仍獨立） |
