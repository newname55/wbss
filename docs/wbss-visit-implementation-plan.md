# wbss visit 導入実装ガイド

## 1. 対象スコープ

今回の実装スコープは次の 3 点です。

1. `docs/` を正本として詳細化する
2. `visits / visit_ticket_links` を追加する SQL を用意する
3. 既存会計導線に `visit` を最小差分で接続する

この時点では、現場画面の見た目や入力手順は大きく変えません。

---

## 2. 実装対象ファイル

### 追加

- [app/service_visit.php](/Users/newname/webproject/wbss/app/service_visit.php)
- [docs/add_visit_session_tables.sql](/Users/newname/webproject/wbss/docs/add_visit_session_tables.sql)
- [docs/wbss-integrated-architecture-delta.md](/Users/newname/webproject/wbss/docs/wbss-integrated-architecture-delta.md)
- [docs/wbss-visit-implementation-plan.md](/Users/newname/webproject/wbss/docs/wbss-visit-implementation-plan.md)

### 修正対象

- [public/cashier/index.php](/Users/newname/webproject/wbss/public/cashier/index.php)
- [public/cashier/ticket.php](/Users/newname/webproject/wbss/public/cashier/ticket.php)
- [public/api/ticket_get.php](/Users/newname/webproject/wbss/public/api/ticket_get.php)

---

## 3. 導入方針

### 3-1. migration 前でもコードを先に入れられるようにする

- `visits` と `visit_ticket_links` が無い環境では旧挙動を維持する
- テーブル存在チェックを `app/service_visit.php` に寄せる
- 本番切替は SQL 適用タイミングで行う

### 3-2. visit 発番のタイミング

- 現行の `public/cashier/index.php?action=new` で伝票新規作成時に発番する

理由:

- 現在この導線が伝票作成の最小起点
- 現場オペレーションへの影響が小さい
- 後で「来店先行作成」に移行しても互換を保てる

### 3-3. 顧客未確定来店を許容する

- 初期導入では `customer_id` nullable
- 来店直後に顧客が未登録でも `visit` だけ作れるようにする

### 3-4. イベント参加実績は会計開始時に自動接続する

- `store_event_instance_id` を伴って会計開始した場合、`event_entries` を自動接続する
- 同一顧客・同一イベントで未接続の `event_entry` があれば再利用する
- 無ければ `source_detail='auto_from_cashier'` で新規作成する

理由:

- 将来 CRM やイベント ROI を見る際に、イベント参加と来店が切れないようにするため
- 現時点で専用入力画面がなくても、最低限の実績線を残せるため

---

## 4. リクエストパラメータ方針

`action=new` では次の GET パラメータを将来互換込みで受けられるようにする。

- `customer_id`
- `store_event_instance_id`
- `visit_type`
- `guest_count`
- `first_free_stage`

現時点で画面から全て入力させなくてもよく、未指定時は既定値で生成する。

---

## 5. テーブル設計メモ

### `visits`

最初の役割:

- 伝票の親となる来店セッション

初期導入で必須の列:

- `id`
- `store_id`
- `business_date`
- `customer_id`
- `store_event_instance_id`
- `primary_ticket_id`
- `visit_status`
- `visit_type`
- `arrived_at`
- `guest_count`

### `visit_ticket_links`

最初の役割:

- 既存 `tickets` と `visits` を 1 本つなぐ

初期導入で必須の列:

- `visit_id`
- `ticket_id`
- `store_id`
- `customer_id`
- `link_type`

---

## 6. 実コード変更ルール

### 6-1. `public/cashier/index.php`

新規伝票作成時:

- まず既存通り `tickets` を作成
- `visits` テーブルが存在する場合のみ `visit` を作成
- 続けて `visit_ticket_links` を作成
- リダイレクト先に `visit_id` を含める

一覧表示時:

- `visit_ticket_links` があれば `visit_id` を表示する
- テーブルが無い場合は旧一覧のまま

### 6-2. `public/cashier/ticket.php`

- 伝票ヘッダに `visit_id / customer_id / event_id` の要約を表示する
- 表示のみ追加し、既存入力ロジックは崩さない

### 6-3. `public/api/ticket_get.php`

- 既存の `ticket` payload に `visit` 情報を追加返却する
- フロント側で将来活用できるようにする

---

## 7. 今回やらないこと

- 指名 UI の全面再設計
- 顧客画面からの来店作成導線
- イベント予約 UI
- analytics バッチ実装

---

## 8. 次フェーズ

この実装が入った後に着手しやすい順序は次のとおりです。

1. `event_entries` 入力導線を追加
2. `visit_nomination_events` 保存を会計 UI と連動
3. `customer_metrics / store_daily_metrics` の集計バッチ追加
4. 顧客詳細に `visits` タイムラインを表示

## 9. 指名保存ルール

- 指名の正本は `visit_nomination_events`
- 会計保存時に payload から毎回再構成して入れ替える
- legacy 会計の `sets[*].customers[*].shimei` と簡易会計の `payload.shimei` の両方を読む
- `nomination_type`
  - `hon` は本指名
  - `jounai` は場内
- `set_no`
  - legacy payload はセット番号を維持
  - 簡易会計 payload は現時点では `1`
- `fee_ex_tax`
  - `normal50/full50` は 1000 円 × 本数
  - `half25` は 500 円 × 本数
  - `pack_douhan` は 0 円
- 更新方式は `DELETE + INSERT`
  - 会計保存時点の payload を最新正本とみなす
