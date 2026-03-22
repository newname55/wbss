# wbss 統合業務設計 差分版

## 1. この文書の目的

この文書は、既存の `wbss` / `haruto_core` 構造を前提に、
「会計・イベント・顧客管理」を 1 本の業務フローとしてつなぐための
差分ベース設計をまとめたものです。

実装時の参照先:

- SQL: [add_visit_session_tables.sql](/Users/newname/webproject/wbss/docs/add_visit_session_tables.sql)
- 実装手順: [wbss-visit-implementation-plan.md](/Users/newname/webproject/wbss/docs/wbss-visit-implementation-plan.md)

前提は次のとおりです。

- 既存の `tickets / ticket_items / ticket_payments / ticket_settlements` は活かす
- 既存の `customers` と `customer_notes / customer_cast_links` は活かす
- 既存の `store_event_instances / store_external_events` は活かす
- 現場運用を止めないため、まずは「来店セッション」を追加して中継層にする
- 既存画面の責務はなるべく変えず、裏でひも付けを増やす

---

## 2. 既存構造の把握

### 2-1. 既存で既に使えるもの

- `tickets`
  - `business_date`
  - `status`
  - `opened_at / locked_at / closed_at`
  - `totals_snapshot`
- `ticket_items`
  - 会計ロック時の明細保持
- `ticket_payments`
  - 複数決済、途中入金の保持
- `ticket_settlements`
  - total / paid_total / balance の保持
- `customers`
  - `last_visit_at`
  - `visit_count`
  - `referral_source`
  - `assigned_user_id`
  - `merged_into_customer_id`
- `customer_notes`
  - 履歴メモ
- `customer_cast_links`
  - 顧客とキャストの関係
- `store_event_instances`
  - 店内企画の実施回
- `store_external_events`
  - 外部イベントの取り込み

### 2-2. 既存で不足しているもの

- 1 回の「来店」を親として束ねるキー
- 来店中の席移動履歴
- 来店中のキャスト配置履歴
- 指名を会計明細ではなく業務イベントとして残す構造
- イベント参加と実来店をつなぐ実績テーブル
- 顧客 KPI / イベント KPI / 日次 KPI の派生集計テーブル

### 2-3. 差分設計の基本方針

- 新しい中心は `visits`
- 既存 `tickets` は会計専用の子にする
- イベントは `tickets` ではなく `visits` 側に持つ
- 顧客の累積値は `customers` にキャッシュしつつ、正本は `visits` と `tickets` から再計算可能にする

---

## 3. 差分の全体像

### 3-1. 触らないもの

- 既存の会計計算ロジック
- 既存の `ticket_save / ticket_lock / ticket_payment_add / ticket_payment_recalc`
- 既存の顧客編集画面
- 既存のイベント一覧画面

### 3-2. 最小限の追加でつなぐもの

- `visits`
- `visit_ticket_links`
- `event_entries`
- `visit_cast_assignments`
- `visit_nomination_events`
- `customer_metrics`
- `event_metrics_daily`
- `store_daily_metrics`

### 3-3. 必要なら後追いで足すもの

- `visit_seat_assignments`
- `visit_orders`
- `customer_followups`
- `cast_metrics_daily`

---

## 4. 差分ベース業務フロー

### 4-1. イベント作成

既存流用:

- `store_event_instances`

追加:

- 追加テーブルは不要
- ただし `store_event_instances` を「event_schedule 相当」として扱う

保存すべき情報:

- `store_id`
- `title`
- `status`
- `starts_at / ends_at`
- `budget_yen`
- `memo`

設計上の扱い:

- `store_event_instances` を新設 `event_schedules` に置き換えない
- まずは現行の実施回テーブルとして流用する
- 将来 `event_templates` が必要になったら追加する

### 4-2. 顧客流入

既存流用:

- `customers.referral_source`

追加:

- `event_entries`

保存すべき情報:

- `store_id`
- `customer_id`
- `store_event_instance_id`
- `entry_type` (`reservation`, `walkin`, `referral`, `sns`, `guide`, `repeat_dm`)
- `source_detail`
- `referrer_customer_id`
- `reserved_at`
- `arrived_visit_id` nullable

差分ポイント:

- 初回来店導線は `customers.referral_source` に残してよい
- ただしイベント施策分析は `customers` に上書き保存すると壊れるため、流入単位は `event_entries` に分離する

### 4-3. 来店

既存流用:

- `customers.last_visit_at`
- `customers.visit_count`
- `tickets.business_date`

追加:

- `visits`

保存すべき情報:

- `visit_id`
- `store_id`
- `business_date`
- `customer_id`
- `primary_ticket_id` nullable
- `store_event_instance_id` nullable
- `entry_id` nullable
- `visit_status` (`arrived`, `seated`, `billing`, `closed`, `cancelled`)
- `visit_type` (`walkin`, `reservation`, `douhan`, `event`, `free`)
- `arrived_at`
- `left_at` nullable
- `guest_count`
- `charge_people_snapshot`
- `first_free_stage`
- `created_by_user_id`

差分ポイント:

- 来店の親を `tickets` から切り離す
- 顧客が確定していない来店もあるため、`customer_id` は nullable 許容でもよい
- ただし本命は 1 来店 1 顧客を基本にする
- 複数名グループは後述の `visit_ticket_links` と `guest_count` で吸収する

### 4-4. 席割り・キャスト配置

既存流用:

- 既存 `tickets.seat_id` は代表席として残す
- キャスト候補 API や出勤情報はそのまま流用

追加:

- `visit_seat_assignments`
- `visit_cast_assignments`

保存すべき情報:

- `visit_seat_assignments`
  - `visit_id`
  - `seat_id`
  - `started_at`
  - `ended_at`
  - `is_primary`
- `visit_cast_assignments`
  - `visit_id`
  - `cast_user_id`
  - `role_type` (`table`, `support`, `primary`, `douhan`)
  - `free_stage` (`first`, `second`, `third`, `none`)
  - `started_at`
  - `ended_at`

差分ポイント:

- まず最小導入なら `visit_cast_assignments` から始める
- 席移動が激しくないなら `visit_seat_assignments` は第 2 段階でよい

### 4-5. 指名発生

既存流用:

- `tickets.totals_snapshot`
- 会計計算上の指名料

追加:

- `visit_nomination_events`

保存すべき情報:

- `visit_id`
- `customer_id`
- `cast_user_id`
- `nomination_type` (`hon`, `jounai`, `douhan`, `free_to_shimei`)
- `set_no`
- `started_at`
- `ended_at` nullable
- `fee_ex_tax`
- `cast_back_yen`
- `count_unit`

差分ポイント:

- 指名料の金額は引き続き `tickets` 側で算出してよい
- ただし KPI、指名変遷、キャスト配分の正本は `visit_nomination_events`
- `ticket_items` の指名行だけで分析しない

### 4-6. 注文・ドリンク

既存流用:

- `orders` 系画面
- `ticket_items` の会計明細

追加:

- まずは必須ではない
- 将来は `visit_orders` を追加

保存すべき情報:

- `visit_id`
- `ticket_id`
- `customer_id` nullable
- `cast_user_id` nullable
- `menu_id`
- `qty`
- `amount`
- `ordered_at`
- `served_at`

差分ポイント:

- 先に会計・来店・イベント統合を優先
- オーダー分析は第 2 フェーズでもよい

### 4-7. 会計

既存流用:

- `tickets`
- `ticket_items`
- `ticket_payments`
- `ticket_settlements`

追加:

- `visit_ticket_links`

保存すべき情報:

- `visit_id`
- `ticket_id`
- `customer_id` nullable
- `link_type` (`primary`, `split`, `additional`)
- `payer_group`
- `allocated_sales_yen`

差分ポイント:

- 既存 `tickets` は極力 ALTER しない
- まずは中継テーブル `visit_ticket_links` でつなぐ
- 将来余裕があれば `tickets.visit_id` を追加してもよいが、初期導入は不要

### 4-8. 退店

既存流用:

- `tickets.closed_at`

追加:

- `visits.left_at`
- `visits.close_reason`
- `visits.next_action_status`

保存すべき情報:

- `left_at`
- `stay_minutes`
- `close_reason` (`completed`, `early_leave`, `cancel`, `carry_over`)
- `next_action_status` (`none`, `follow_needed`, `reserved_next`, `hot`)

差分ポイント:

- 会計締めと退店は完全一致しないことがある
- `tickets.closed_at` だけで退店を表現しない

### 4-9. 再来・フォロー

既存流用:

- `customer_notes`
- `customers.next_action`

追加:

- `customer_followups` は後追いでもよい

保存すべき情報:

- `customer_id`
- `visit_id`
- `action_type`
- `planned_at`
- `done_at`
- `reaction_result`

差分ポイント:

- 当面は `customer_notes` 運用でもよい
- ただし KPI を出す段階で構造化が必要

---

## 5. 中心エンティティの差分責務

### 5-1. `visits`

役割:

- 来店セッションの親

既存との差分責務:

- `tickets` が持っていた「何の来店だったか」を吸収する
- イベント、流入、席、キャスト、指名、会計を束ねる

### 5-2. `tickets`

役割:

- 会計と入金の管理

既存との差分責務:

- 「来店そのもの」は持たない
- 金額確定、支払、残高管理に専念

### 5-3. `customers`

役割:

- 顧客の代表マスタ

既存との差分責務:

- `visit_count / last_visit_at / referral_source` はキャッシュとして維持
- 正本は `visits` と `event_entries`

### 5-4. `store_event_instances`

役割:

- 現行ではイベント開催回

既存との差分責務:

- 新たな `event_schedule` として流用
- `visits.store_event_instance_id` と `event_entries.store_event_instance_id` の親になる

### 5-5. cast 関連

役割:

- `users / user_roles / store_users` を流用

既存との差分責務:

- 顧客との静的関係は `customer_cast_links`
- 来店ごとの担当実績は `visit_cast_assignments`
- 指名実績は `visit_nomination_events`

---

## 6. 推奨テーブル差分

## 6-1. master

### 既存流用

- `stores`
- `customers`
- `users`
- `roles`
- `user_roles`
- `store_event_instances`
- `store_external_events`

### 追加候補

- `free_stage_defs`
  - 目的: フリー段階の定義をコード直書きしない
  - PK: `code`
  - 主カラム: `code`, `name`, `sort_order`, `is_active`

---

## 6-2. transaction

### `visits`

- 目的: 1 来店を業務上の親として保持
- PK: `id`
- 主カラム:
  - `store_id`
  - `business_date`
  - `customer_id`
  - `store_event_instance_id`
  - `entry_id`
  - `visit_status`
  - `visit_type`
  - `arrived_at`
  - `left_at`
  - `guest_count`
  - `charge_people_snapshot`
  - `first_free_stage`
  - `created_by_user_id`
  - `updated_at`
- 関係:
  - 1:N `visit_ticket_links`
  - 1:N `visit_cast_assignments`
  - 1:N `visit_nomination_events`

### `event_entries`

- 目的: イベント流入と実来店の接続
- PK: `id`
- 主カラム:
  - `store_id`
  - `customer_id`
  - `store_event_instance_id`
  - `entry_type`
  - `source_detail`
  - `referrer_customer_id`
  - `reserved_at`
  - `visit_id`
  - `status`
- 関係:
  - N:1 `customers`
  - N:1 `store_event_instances`
  - N:1 `visits`

### `visit_ticket_links`

- 目的: 既存 `tickets` を来店にひも付ける
- PK: `id`
- 主カラム:
  - `store_id`
  - `visit_id`
  - `ticket_id`
  - `customer_id`
  - `link_type`
  - `payer_group`
  - `allocated_sales_yen`
  - `created_at`
- 関係:
  - N:1 `visits`
  - N:1 `tickets`

### `visit_cast_assignments`

- 目的: 来店中のキャスト配置履歴
- PK: `id`
- 主カラム:
  - `store_id`
  - `visit_id`
  - `customer_id`
  - `cast_user_id`
  - `role_type`
  - `free_stage`
  - `started_at`
  - `ended_at`

### `visit_nomination_events`

- 目的: 指名発生の正本
- PK: `id`
- 主カラム:
  - `store_id`
  - `visit_id`
  - `customer_id`
  - `cast_user_id`
  - `nomination_type`
  - `set_no`
  - `fee_ex_tax`
  - `cast_back_yen`
  - `count_unit`
  - `started_at`
  - `ended_at`

### `visit_seat_assignments`

- 目的: 卓移動履歴
- PK: `id`
- 主カラム:
  - `store_id`
  - `visit_id`
  - `seat_id`
  - `started_at`
  - `ended_at`
  - `is_primary`

---

## 6-3. analytics

### `customer_metrics`

- 目的: 顧客 KPI の高速参照
- PK: `(store_id, customer_id)`
- 主カラム:
  - `first_visit_at`
  - `last_visit_at`
  - `visit_count`
  - `paid_ticket_count`
  - `nomination_count`
  - `same_cast_repeat_count`
  - `total_sales_yen`
  - `avg_sales_yen`
  - `favorite_event_id`
  - `favorite_lead_source`
  - `updated_at`

### `event_metrics_daily`

- 目的: イベント別日次 KPI
- PK: `(store_id, business_date, store_event_instance_id)`
- 主カラム:
  - `entry_count`
  - `visit_count`
  - `new_customer_count`
  - `repeat_customer_count`
  - `nomination_count`
  - `sales_total_yen`
  - `paid_total_yen`
  - `gross_profit_yen`
  - `roi`
  - `revisit_30d_count`
  - `updated_at`

### `store_daily_metrics`

- 目的: 店舗日次 KPI
- PK: `(store_id, business_date)`
- 主カラム:
  - `visit_count`
  - `customer_count`
  - `ticket_count`
  - `sales_total_yen`
  - `paid_total_yen`
  - `balance_total_yen`
  - `avg_sales_per_visit`
  - `nomination_rate`
  - `repeat_rate`
  - `updated_at`

---

## 7. キー設計の差分方針

### `visit_id`

- 新設する中心キー
- 理由:
  - 既存 `ticket_id` は会計中心であり、来店中の業務イベントを表せない
  - 1 来店に分割会計や複数伝票がありうる

### `ticket_id`

- 既存のまま使用
- 理由:
  - 既存 API と会計画面への影響を避ける

### `customer_id`

- 既存のまま使用
- 理由:
  - 既に顧客詳細、メモ、担当がこのキーに乗っている

### `store_id`

- 新規追加テーブルすべてに持つ
- 理由:
  - マルチ店舗分析
  - 店舗別 `business_date` 集計

### `business_date`

- `visits` に必須
- `tickets` は既存のまま保持
- 理由:
  - 深夜営業で `arrived_at` のカレンダー日付と営業日がずれる
  - KPI は `business_date` 基準で集計する必要がある

---

## 8. 会計接続の差分設計

### 推奨接続方法

- 初期導入は `visit_ticket_links` を使う

理由:

- `tickets` ALTER を最小限にできる
- 既存の伝票 API を壊さない
- 分割会計や追加伝票を自然に表現できる

### 関係

- `visits 1 - N visit_ticket_links`
- `visit_ticket_links N - 1 tickets`
- `visits N - 1 customers`
- `visits N - 1 store_event_instances`

### 実務上の運用

- 来店開始時に `visits` を作る
- 会計開始時または伝票紐付け時に `visit_ticket_links` を作る
- 伝票が分割されたら `visit_ticket_links` を追加する
- `ticket_payments` はそのまま使う

### 顧客との接続

- 顧客売上の正本は `visit_ticket_links.customer_id` 経由で集計
- `tickets.customer_id` を無理に追加しなくてよい

### イベントとの接続

- イベントは `tickets` には持たせない
- `visits.store_event_instance_id` で表現する

---

## 9. イベント設計の差分修正

### 現状に合わせた再定義

- `event`
  - 新設しない
  - 当面は未導入
- `event_schedule`
  - 既存 `store_event_instances` を流用
- `event_entries`
  - 新設

### なぜこの形にするか

- 現行 `store_event_instances` が既に開催回テーブルとして機能している
- いきなり `events / event_schedules` の 2 層へ再分解すると既存画面の改修量が増える
- まずは `store_event_instances` を「スケジュール」と見なし、参加実績を `event_entries` で補うほうが安全

### 将来の拡張

- 同一企画の複数回管理が本格化したら `event_templates` を追加
- `store_event_instances.template_id` を活用する

---

## 10. 顧客履歴設計の差分修正

### 既存 `customers` に残してよいもの

- `last_visit_at`
- `visit_count`
- `referral_source`
- `assigned_user_id`
- `next_action`

### 新しい正本

- 初回来店導線
  - `customers.referral_source` は初期値
  - 詳細正本は `event_entries.entry_type`
- 来店回数
  - 正本は `visits`
- 指名履歴
  - 正本は `visit_nomination_events`
- 最終来店日
  - 正本は `MAX(visits.arrived_at)`
- 累計売上
  - 正本は `visit_ticket_links` + `ticket_settlements/ticket_payments`
- 反応しやすいイベント
  - `customer_metrics.favorite_event_id`

### 更新方針

- 画面表示高速化のため `customers.last_visit_at / visit_count` は更新してよい
- ただし再計算可能であることを前提にする

---

## 11. KPI 設計の差分修正

## 11-1. イベント KPI

- エントリー数
  - `COUNT(event_entries.id)`
- 来店化率
  - `event visit count / entry count`
- 新規客数
  - `初回来店 customer の visits 数`
- 指名化率
  - `指名発生 visit 数 / イベント来店 visit 数`
- 客単価
  - `イベント起点売上 / イベント来店数`
- ROI
  - `(paid_total_yen - budget_yen) / budget_yen`

## 11-2. 顧客 KPI

- 再来率
  - `初回来店から 30 日以内に 2 回目来店した customer 数 / 初回来店 customer 数`
- 指名定着率
  - `同一 cast 再指名数 / 全指名数`
- 累計売上
  - `customer 紐付き paid_total の合計`
- 平均客単価
  - `累計売上 / 来店回数`
- イベント反応度
  - `特定イベント参加後の来店数または売上寄与`

## 11-3. 会計 KPI

- 総売上
  - `SUM(ticket_settlements.total)`
- 実入金
  - `SUM(ticket_payments.captured) - SUM(refunded)`
- 未収残
  - `SUM(ticket_settlements.balance)`
- 分割会計率
  - `split link を持つ visit 数 / 全 visit 数`
- 課金人数差分率
  - `charge_people_snapshot > guest_count の visit 数 / 全 visit 数`

---

## 12. migration 戦略

## 12-1. 追加テーブル優先順位

1. `visits`
2. `visit_ticket_links`
3. `event_entries`
4. `visit_cast_assignments`
5. `visit_nomination_events`
6. `customer_metrics`
7. `event_metrics_daily`
8. `store_daily_metrics`

## 12-2. 既存コード影響

### 影響小

- 既存会計 API 群
- 既存顧客画面
- 既存イベント画面

### 影響中

- 伝票作成の入り口
  - 伝票作成前に `visit_id` を発番する導線が必要
- 顧客来店登録画面
  - 来店開始時に `visits` を作る必要がある

### 影響大

- もし 1 画面で来店・席・会計を全部同時入力している店舗があるなら、その UI 導線だけは整理が必要

## 12-3. 段階導入

### Step 1

- `visits`
- `visit_ticket_links`
- バッチで `customers.last_visit_at / visit_count` 更新

目的:

- まず「来店」と「会計」を分離してつなぐ

### Step 2

- `event_entries`
- `visits.store_event_instance_id`

目的:

- イベント施策の来店効果測定を可能にする

### Step 3

- `visit_cast_assignments`
- `visit_nomination_events`

目的:

- 指名変遷、フリー転換、キャスト寄与分析を可能にする

### Step 4

- analytics 系テーブルと定期集計

目的:

- ダッシュボード、CRM、AI 分析へ展開する

## 12-4. リスク

- 顧客未確定来店をどう扱うか
- 分割会計時の顧客割当ルールが曖昧なままだと累計売上がズレる
- フリー段階を入力しない運用だと KPI が欠損する
- `business_date` と来店日時の不整合
- 旧データへの後付け `visit` 復元精度

---

## 13. 設計上の落とし穴

### 1. `store_event_instance_id` を `tickets` に直接持たせる

- 会計都合でイベントが複数伝票に複製され、分析が汚れる

### 2. 顧客売上を `tickets` 単体で集計する

- 分割会計や追加伝票で顧客単位配賦が破綻する

### 3. 指名を `ticket_items` の文言だけで持つ

- 本指名、場内、同伴起点、再指名が分離できない

### 4. `customers.visit_count` を正本にしてしまう

- 更新漏れで KPI が崩れる

### 5. フリー段階を顧客マスタにだけ保存する

- 来店ごとの `First/Second/Third` 変化を失う

### 6. 退店時刻を `tickets.closed_at` だけで見る

- 会計後の滞在や未締め退店に対応できない

### 7. `store_event_instances` を全面廃止して再設計する

- 既存画面の改修コストが高く、段階導入に向かない

### 8. `visit_id` を作らず `ticket_id` を業務キーにし続ける

- CRM と KPI が会計依存のままになり、将来拡張が止まる

---

## 14. この差分設計で得られること

- 既存会計を壊さず、来店を中心に横断できる
- イベントから来店、売上、再来まで追える
- 顧客単位で来店履歴、指名変遷、累計売上を追える
- 将来の複数店舗 KPI ダッシュボードにそのまま伸ばせる
- AI 分析の入力データを transaction と analytics に分離できる

---

## 15. 実装の最初の一手

まず着手すべきなのは次の 2 つです。

1. `visits` を追加する
2. `visit_ticket_links` を追加して既存 `tickets` と接続する

この 2 つだけでも、

- 来店と会計の分離
- 顧客別売上集計の安定化
- イベント来店分析の受け皿

まで進められます。

その後に `event_entries` と `visit_nomination_events` を足す流れが、
現場影響と分析価値のバランスが最もよいです。
