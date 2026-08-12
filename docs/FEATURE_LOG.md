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

## 2026-08-10 — 先修放寬：時序 approved 報名／專班共包＋連帶取消

- **需求：** 來台連上課（如 Beginner’s → AI）不應只因「先修尚未完成」被擋；現場到達前需能先報後課。先修若後來取消，依賴它的後課不可殘留。
- **決策：**
  1. 先修通過（整課完成型）= **已完成** ∨ **有 approved 先修場次且該場次 starttime < 目標場次 starttime**（同日上下午可；多日先修以開始較早為準）∨ **專班同一次申請共包該先修課**。
  2. 「已報名」**只認 approved**；pending 不算。
  3. 放寬**只套用 `verify_type = course`（整課）**；活動完成／成績規則仍須真實達成（可與整課規則並存於 AND／OR）。
  4. 先修報名被取消／駁回（且因此失去唯一時序依據）→ **自動取消**依賴之後課報名，並 **寄信通知學員 + 報名業務**（`batch_submittedby`；若無則專班申請人／相關業務）。
  5. 公開場次／批次／專班申請／審核入學皆共用同一評估語意；專班申請階段以共包＋完成／既有 approved 為主（尚無場次時間則不做時序比對）。
  6. **場次規則優先於課程連動預設**；既有場次若已寫入舊 JSON，改連動不會自動更新——須編輯該場次先修。
- **影響範圍：** `prerequisite_manager.php`、`enrolment_manager.php`、`reservation_application.php`、`batch_lookup.php`、`notification_helper.php`、語系、SPEC §53／§0.5、DEV_WORKFLOW。
- **版本／狀態：** 進行中（**5.17.7**；5.17.6 後修正時序比對改 starttime；待驗收）。

---

## 2026-08-10 — DEV_WORKFLOW：先開 PR 記錄再實作

- **需求：** 規格確認並說「開始開發」後，先發 PR 留痕；測完再 push 實作；規格變更改同一 PR。
- **決策：** 見更新後 [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md) §1／§3a／§3b。
- **影響範圍：** `docs/DEV_WORKFLOW.md`、FEATURE_LOG 更新約定。
- **版本／狀態：** 進行中（隨本輪 Draft PR）。

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
| 課前準備／設備檢查 | class prep、equipment check items | 本檔 2026-07 |
| 排課規則（面授） | 教室／時段約束 | `local_tm_course/docs/SCHEDULING_REQUIREMENTS.md` |

細部版本號與檔案級變更請對照根目錄 `CHANGELOG.md`（若有）與 `local_tm_course/version.php`。

---

## 待決策／待辦（可勾）

- [ ] Equipment check 全流程產品驗收後標記已驗收並收斂 PR 描述
- [ ] 其餘進行中工作區變更一併對齊 SPEC 章節編號
