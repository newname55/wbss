# テーブル統合方針書

対象: `haruto_core` シフト・勤怠領域
対象テーブル: `cast_shift_plans` / `cast_week_plans` / `shift_schedules` / `attendance_shifts` / `attendances`

## 1. 目的

本方針書は、シフト・勤怠領域において重複して存在する計画系テーブルと実績系テーブルの責務を整理し、「計画の正本」と「実績の正本」を明文化することで、今後の改修・運用・集計での不整合を防止することを目的とする。

## 2. 背景

現行スキーマでは、以下のように同一または近接した責務を持つテーブルが複数存在している。

- 計画系
  - `cast_shift_plans`
  - `cast_week_plans`
  - `shift_schedules`
  - `attendance_shifts`
- 実績系
  - `attendances`

この状態では、どのテーブルを更新すべきかが機能ごとに揺れやすく、表示差異、集計差異、更新漏れ、二重更新の原因となる。

## 3. 統合方針

### 3.1 正本定義

- 計画の正本は `cast_shift_plans` とする
- 実績の正本は `attendances` とする

### 3.2 非正本の位置づけ

- `cast_week_plans` は週次UI入力・表示の補助データとする
- `shift_schedules` は旧集計・互換用途の補助データとする
- `attendance_shifts` はキャスト勤怠の正本として扱わない

## 4. テーブル別責務定義

### 4.1 `cast_shift_plans`

役割:
- キャスト勤務計画の正本

定義:
- 1レコード = 1キャスト × 1店舗 × 1営業日 の勤務予定

保持すべき意味:
- 出勤予定か休み予定か
- 出勤予定開始時刻
- 計画状態

扱い:
- 画面・API・バッチが参照する計画データの基準とする
- 計画更新は原則このテーブルのみを更新対象とする

### 4.2 `attendances`

役割:
- 勤怠実績の正本

定義:
- 1レコード = 1キャスト × 1店舗 × 1営業日 の勤務実績

保持すべき意味:
- 実際の出勤時刻
- 実際の退勤時刻
- 実績状態
- 打刻手段

扱い:
- 打刻・勤怠修正・勤怠集計の基準とする
- 実績更新は原則このテーブルのみを更新対象とする

### 4.3 `cast_week_plans`

役割:
- 週表示・週入力用の補助テーブル

定義:
- UI都合で持つ一時的または補助的な週単位シフト情報

扱い:
- 正本として扱わない
- 保存時は `cast_shift_plans` に正規化して反映する
- 将来的には廃止またはビュー相当の扱いを検討する

### 4.4 `shift_schedules`

役割:
- 旧半月集計・互換用途の補助テーブル

定義:
- `half_ym`, `half_k` を持つため、半月運用や給与集計に寄った旧スケジュール形式

扱い:
- 正本として扱わない
- 新規書き込み先にしない
- 必要な場合は `cast_shift_plans` から導出する

### 4.5 `attendance_shifts`

役割:
- 汎用シフト枠管理テーブル

定義:
- `person_type`, `person_id`, `person_name` を持つ汎用シフト情報

扱い:
- キャスト勤怠の正本として扱わない
- キャスト用途での新規採用は行わない
- 別用途に限定できない場合は廃止候補とする

## 5. 業務ルール

### 5.1 計画データのルール

- キャスト勤務計画は `cast_shift_plans` に一元管理する
- 同一キャスト・同一店舗・同一営業日の計画は1件のみとする
- `is_off=1` は休み予定、`is_off=0` は出勤予定を表す
- `status` は計画状態を表し、実績状態を兼ねない

### 5.2 実績データのルール

- 勤怠実績は `attendances` に一元管理する
- 同一キャスト・同一店舗・同一営業日の実績は1件のみとする
- `clock_in`, `clock_out`, `status` は事実データとして扱う
- 実績は計画を上書きしない

### 5.3 計画と実績の関係

- 計画と実績は別概念として管理する
- 差異判定は `cast_shift_plans` と `attendances` の比較で行う
- 欠勤、遅刻、予定なし出勤などは比較ロジックで判定する

## 6. 推奨制約

### 6.1 `cast_shift_plans`

推奨一意条件:
- `(store_id, user_id, business_date)`

### 6.2 `attendances`

推奨一意条件:
- `(store_id, user_id, business_date)`

### 6.3 参照整合性

推奨参照:
- `cast_shift_plans.store_id -> stores.id`
- `cast_shift_plans.user_id -> users.id`
- `attendances.store_id -> stores.id`
- `attendances.user_id -> users.id`

## 7. 更新ルール

- 計画更新系画面・APIは `cast_shift_plans` のみ更新する
- 打刻系画面・APIは `attendances` のみ更新する
- `cast_week_plans` への更新は UI補助用途に限定する
- `shift_schedules` と `attendance_shifts` は新規更新先にしない

## 8. 参照ルール

- シフト表示の基準は `cast_shift_plans`
- 勤怠表示の基準は `attendances`
- 出勤予定対実績レポートは `cast_shift_plans` と `attendances` を JOIN して作成する
- 半月集計や給与集計が必要な場合も、原則 `cast_shift_plans` と `attendances` から再構成する

## 9. 移行方針

### Phase 1

- 正本を `cast_shift_plans` / `attendances` に固定する
- 新規機能・改修で他3テーブルを正本扱いしない
- 既存画面の更新先を棚卸しする

### Phase 2

- `cast_week_plans` 利用画面を `cast_shift_plans` ベースへ寄せる
- `shift_schedules` 利用ロジックを `cast_shift_plans` 由来へ変更する
- `attendance_shifts` のキャスト用途を停止する

### Phase 3

- 非正本テーブルの利用箇所を削減する
- 不要テーブルはアーカイブまたは廃止候補として整理する

## 10. 最終方針

- 計画の正本は `cast_shift_plans` である
- 実績の正本は `attendances` である
- `cast_week_plans` は週次UI補助であり正本ではない
- `shift_schedules` は互換用途であり正本ではない
- `attendance_shifts` はキャスト勤怠の正本ではない

## 11. 補足

本方針は、シフト・勤怠領域におけるデータ責務を単純化し、将来の改修時に「どこを見れば正しいか」を明確にするためのものである。以後、設計判断・改修レビュー・集計仕様は本方針を基準として扱う。
