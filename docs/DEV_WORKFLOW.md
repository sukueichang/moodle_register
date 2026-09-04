# DEV_WORKFLOW — 開發／修 Bug SOP

> **文件角色：** 每次提需求或修 bug 時雙方（你與 AI）應遵循的標準流程。  
> **驗收訊號：** 你回覆 **`ok`**／**`測試無誤`**／**`測試無誤了`** → AI 才把實作結果 push 到已開的 PR。  
> **合併訊號：** push／驗收後，AI **必須主動問**你是否要把該分支 **merge 進 `main`**；未明確說 merge／合進去前，**不**自行合併。  
> **通知義務：** AI 只要有 **push**、**開／更新 PR**、或 **merge／刪分支**，當輪回覆都要主動說清楚（做了什麼、分支、PR URL）。不可默默上遠端。  
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
| 2 | 確認決策（規格 OK） | 寫入 FEATURE_LOG；必要時更新 SPEC |
| 3 | 說 **「開始開發」** | 見 §3a：先開／更新 **Draft PR** 記錄本輪規格 |
| 4 | — | 實作（本機）；遵守 BUGFIX_LOG 預防準則。過程中規格變更 → **同步更新同一支 PR 描述**與 FEATURE_LOG／SPEC |
| 5 | 在 Moodle 環境自行測試 | 依回報修正；問題寫入 BUGFIX_LOG |
| 6 | 回覆 **ok／測試無誤** | 見 §3b：commit 實作 → **push** 到該 PR 分支 → 更新 PR 描述 → **問是否 merge** |
| 7 | 明確回覆要不要 merge | 見 §3c：要 → merge 進 `main`；不要 → 維持 PR 開啟 |

---

## 2. 回報 Bug

| 步驟 | 你做 | AI 做 |
|------|------|--------|
| 1 | 現象、重現步驟、期望行為 | 定位根因；對照 BUGFIX_LOG 是否舊疾 |
| 2 | 確認修復方向後說 **「開始開發」**（若尚未開 PR） | 見 §3a：Draft PR 記錄本輪修復範圍；再實作。**先**在 BUGFIX_LOG 記一條 |
| 3 | 回歸測試（含清單相關項） | 依回報再修；規格／範圍變更則更新同一 PR |
| 4 | 回覆 **ok／測試無誤** | 見 §3b → **問是否 merge**（§3c） |

---

## 3a. 開始開發時：先開 PR 記錄（你說「開始開發」之後）

**目的：** 規格／範圍先上 GitHub 留痕，再動手寫功能碼。

1. 確認 FEATURE_LOG（進行中）與必要時 SPEC 已反映本輪決策  
2. 建立（或切換）feature 分支  
3. Commit 文件／規格變更（可尚無功能碼）  
4. Push 分支；以 `gh pr create` 開 **Draft PR**（已有則更新描述）  
   - Summary：本輪要做什麼、關鍵決策  
   - Test plan：驗收檢查清單  
5. **立刻回報**已 push／已開或更新的 **PR URL**（含分支名），再開始實作  

**原則：** 過程中規格變更 → 改 FEATURE_LOG／SPEC，並 **更新同一支 PR 的描述**（不另開新 PR，除非你要求拆分）。

---

## 3b. 驗收後：Push 實作到同一 PR（僅在你說 ok 之後）

**原則：** 未明確要求、且你尚未回 ok／測試無誤前，AI **不**把實作結果 push 到遠端（§3a 的規格／Draft PR 除外）。本 SOP 以你的 **ok** 作為實作 push 授權。

1. **整理變更**  
   - `git status`／`git diff`／近期 commit 風格  
   - 不納入 secrets；不改 git config  
2. **Commit** 實作（訊息著重 why；必要時含版本 bump）  
3. **Push** 到已開 PR 的分支（`-u` 若尚未追蹤）  
4. **更新 PR 描述**（Summary／Test plan），反映最終 FEATURE／BUGFIX；必要時標為 Ready for review  
5. **主動告知使用者**：已 push／已更新 PR（分支名 + PR URL + 本輪內容一句話）  
6. **立刻問是否 merge**（§3c）——測完／push 後的固定收尾，不可省略  
7. **文件收尾**  
   - FEATURE_LOG 該節 →「已驗收」  
   - BUGFIX_LOG 已有條目則確認預防準則寫入檢查清單（若為新類型）  
   - 新入口 URL → 評估更新根目錄 `links.md`

---

## 3c. 驗收／push 後：問是否 merge 進 `main`

**原則：** 功能測完並 push 到 PR 分支後，AI **每次都要直接問**你要不要把該分支 **merge 進 `main`**。未得到明確 merge 授權前，**禁止**自行 `gh pr merge`／把 PR 合進 `main`。

1. **問法範例**（push 回報同一則訊息末尾即可）：  
   - 「要不要把這個 PR merge 進 `main`？回覆『merge』／『合進去』我就合併；不需要就說『先留著』。」
2. **你說要 merge**（如 `merge`／`合進去`／`合併 main`）：  
   - 以 `gh pr merge`（或等價流程）合進 `main`  
   - 依你指示刪除已合併遠端分支／關閉被取代的舊 PR  
   - **主動回報**：已 merge、目標分支、PR URL、一句話結果  
3. **你說先不要**：維持 PR OPEN，不 merge、不刪進行中分支  
4. **勿混淆訊號**：僅「ok／可以 push」＝授權 push；**不等于**授權 merge。merge 必須另一次明確指示。

### PowerShell 注意（Windows）

- Commit message 可用 here-string，避免 bash heredoc：
  ```powershell
  git commit -m @"
  訊息內容。

  "@
  ```
- 打包外掛 zip：見 **§4**（禁止用 `Compress-Archive`）。

---

## 4. 打包外掛 ZIP（Windows／上傳 Moodle）

交付或上傳 Moodle「安裝外掛」前，必須依此流程打包。細節背景見 [`BUGFIX_LOG.md`](BUGFIX_LOG.md)「Windows 打包 zip」。

### 正確做法（唯一允許）

在專案根目錄（含 `local_tm_course` 資料夾的那一層）執行：

```powershell
tar.exe -a -c -f local_tm_course.zip -C "<專案根目錄>" local_tm_course
```

例（本機路徑依實際調整）：

```powershell
tar.exe -a -c -f local_tm_course.zip -C "C:\Users\waylon.su\Desktop\tm_course_registration\Moodle plugin v2" local_tm_course
```

要求：

- zip **最外層必須是單一資料夾** `local_tm_course/`（內含 `version.php`、`db/` 等）
- 內部路徑必須使用正斜線 `/`（`tar.exe` 會自動做到）

### 上傳前必做：反斜線檢查

```powershell
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead("local_tm_course.zip")
($zip.Entries | Where-Object { $_.FullName -match '\\' }).Count   # 必須是 0
$zip.Dispose()
```

同時抽查：應能看到 `local_tm_course/version.php`、`local_tm_course/db/install.xml` 這類路徑。

### 禁止

- **禁止** `Compress-Archive`
- **禁止** `[System.IO.Compression.ZipFile]::CreateFromDirectory(...)`

上述兩種在 Windows PowerShell 5.1 會把內部路徑寫成 `\`，Moodle 會回報「無法偵測到外掛類型」。

### 安裝

Moodle：Site administration → Plugins → Install plugins → 上傳 ZIP → 完成後 Notifications 升版。

---

## 5. Moodle 本機驗證建議（最短路徑）

1. 升版：Site administration → Notifications（遠端 pluginfo `cURL` 錯誤可忽略，繼續本地 upgrade）。
2. 對照本輪功能入口（`links.md`／admin 選單）。
3. 若動到 DB：確認 upgrade 成功、無 XMLDB／表名錯誤。
4. 若動到 AJAX：確認 context／sesskey／權限。
5. 專屬開班相關：學員數語意、視訊期望開始時間、週末時分。
6. 先修相關：完成／approved 時序報名／專班共包；先修取消後連帶取消與通知。

---

## 6. AI 每次開發自我檢查（摘要）

- [ ] BUGFIX_LOG 清單已掃  
- [ ] FEATURE_LOG／必要時 SPEC 已更新  
- [ ] 語系 en + zh_tw  
- [ ] `version.php` 若需升級已 bump  
- [ ] 你說「開始開發」後：已開／更新 Draft PR 記錄規格  
- [ ] 規格變更時：同一 PR 描述已同步  
- [ ] 未在使用者 ok 前 push **實作**  
- [ ] 使用者 ok 後：commit 實作 → push → 更新 PR → 回報 URL  
- [ ] push／驗收後：**已問**是否 merge 進 `main`（未授權不 merge）  
- [ ] 交付 zip 時：用 `tar.exe` 打包，且反斜線檢查為 0  

---

## 7. 文件地圖

| 檔案 | 用途 |
|------|------|
| [`SPEC.md`](SPEC.md) | 產品規格、目的、模組邊界 |
| [`FEATURE_LOG.md`](FEATURE_LOG.md) | 需求與決策時間軸 |
| [`BUGFIX_LOG.md`](BUGFIX_LOG.md) | 缺陷與回歸檢查 |
| [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md) | 本 SOP（含 §4 打包 zip） |
| [`../links.md`](../links.md) | 入口 URL |
| `local_tm_course/docs/SCHEDULING_REQUIREMENTS.md` | 面授排課細部規則（仍獨立） |
