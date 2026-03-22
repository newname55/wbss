# シフト領域移行タスク

対象: `cast_shift_plans` / `cast_week_plans` / `shift_schedules` / `attendance_shifts` / `attendances`

## 1. 目的

`cast_shift_plans` を計画の正本、`attendances` を実績の正本として扱うため、既存コードと運用を段階的に移行する。

## 2. 正本ルール

- 計画の正本は `cast_shift_plans`
- 実績の正本は `attendances`
- `cast_week_plans` は週次UI補助
- `shift_schedules` は互換用途
- `attendance_shifts` はキャスト勤怠の正本として扱わない

## 3. 現行の主要利用箇所

### 3.1 `cast_shift_plans`

- [public/manager_today_schedule.php](/Users/newname/webproject/wbss/public/manager_today_schedule.php)
- [public/points_terms.php](/Users/newname/webproject/wbss/public/points_terms.php)
- [public/admin/cast_shift_edit.php](/Users/newname/webproject/wbss/public/admin/cast_shift_edit.php)
- [public/cast_my_schedule.php](/Users/newname/webproject/wbss/public/cast_my_schedule.php)
- [public/cast_week_plans.php](/Users/newname/webproject/wbss/public/cast_week_plans.php)
- [public/cast_week.php](/Users/newname/webproject/wbss/public/cast_week.php)
- [public/attendance/index.php](/Users/newname/webproject/wbss/public/attendance/index.php)
- [public/points_terms_print.php](/Users/newname/webproject/wbss/public/points_terms_print.php)

### 3.2 `attendances`

- [public/cast_schedule.php](/Users/newname/webproject/wbss/public/cast_schedule.php)
- [public/attendance/today.php](/Users/newname/webproject/wbss/public/attendance/today.php)
- [app/attendance.php](/Users/newname/webproject/wbss/app/attendance.php)
- [public/cast_schedule_save.php](/Users/newname/webproject/wbss/public/cast_schedule_save.php)
- [public/dashboard_cast.php](/Users/newname/webproject/wbss/public/dashboard_cast.php)
- [public/attendance/api/attendance_clock_out.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_clock_out.php)
- [public/manager_today_schedule.php](/Users/newname/webproject/wbss/public/manager_today_schedule.php)
- [public/attendance/api/attendance_toggle.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_toggle.php)
- [public/api/line_webhook.php](/Users/newname/webproject/wbss/public/api/line_webhook.php)
- [public/api/cast_candidates.php](/Users/newname/webproject/wbss/public/api/cast_candidates.php)
- [public/cashier/index.php](/Users/newname/webproject/wbss/public/cashier/index.php)
- [public/attendance/api/attendance_clock_in.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_clock_in.php)
- [public/attendance/api/attendance_remind_clockout.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_remind_clockout.php)

### 3.3 非正本テーブル

- `cast_week_plans`
  - [public/admin/cast_shift_edit.php](/Users/newname/webproject/wbss/public/admin/cast_shift_edit.php)
  - [public/dashboard.php](/Users/newname/webproject/wbss/public/dashboard.php)
  - [public/cast_week_plans.php](/Users/newname/webproject/wbss/public/cast_week_plans.php)
  - [public/attendance/reports.php](/Users/newname/webproject/wbss/public/attendance/reports.php)
- `shift_schedules`
  - 現行ローカル PHP 走査では直接参照なし
- `attendance_shifts`
  - [public/attendance/api/attendance_delete.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_delete.php)
  - [public/attendance/api/attendance_list.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_list.php)
  - [public/attendance/api/attendance_save.php](/Users/newname/webproject/wbss/public/attendance/api/attendance_save.php)

## 4. 実装タスク

### Phase 1: 入口の統一

- `cast_week_plans` を更新している画面・APIを棚卸しする
- シフト編集系の保存先を `cast_shift_plans` に寄せる
- 勤怠打刻・修正系の保存先が `attendances` のみに向いているか確認する
- 新規実装で `shift_schedules` と `attendance_shifts` を更新しないルールを明文化する

### Phase 2: 参照の統一

- シフト一覧・週表示・半月集計の参照元を `cast_shift_plans` 基準に統一する
- 勤怠一覧・当日状況・打刻状態の参照元を `attendances` 基準に統一する
- 「予定 vs 実績」の比較ロジックを `cast_shift_plans` と `attendances` の JOIN に寄せる

### Phase 3: 非正本の縮退

- `cast_week_plans` を UI補助用途に限定する
- `shift_schedules` を互換用途または集計用導出データとして隔離する
- `attendance_shifts` のキャスト用途を停止する
- 不要化した更新処理を削除する

## 5. コードレビュー観点

- 予定保存で `cast_shift_plans` 以外を書いていないか
- 実績保存で `attendances` 以外を書いていないか
- 同一画面で計画と実績を混在更新していないか
- 一覧表示が非正本テーブルを直接参照していないか
- 集計処理が複数テーブルの値を正本扱いしていないか

## 6. 完了条件

- 計画更新の書き込み先が `cast_shift_plans` に統一されている
- 実績更新の書き込み先が `attendances` に統一されている
- `cast_week_plans` / `shift_schedules` / `attendance_shifts` が正本として扱われていない
- README と方針書から移行方針に到達できる
