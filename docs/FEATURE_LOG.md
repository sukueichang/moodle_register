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

使用者回覆「測試無誤／ok」後，把該節狀態改為「已驗收」，並依 [`DEV_WORKFLOW.md`](DEV_WORKFLOW.md) 更新 PR／push。

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
