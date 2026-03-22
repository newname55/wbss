# 送迎ルート最適化 設計メモ

## 目的

- `cast_shift_plans` の出勤予定を正本として、当日出勤するキャストの送迎対象を抽出する
- キャスト住所をもとに、店舗到着時刻に間に合う最短ルートを組めるようにする
- まずは「全店舗を一覧で見て、店舗ごとに最短候補を作る」MVPから始める

## 現状整理

- 出勤予定の正本は `cast_shift_plans`
- 実績の正本は `attendances`
- キャストの店舗ひも付けや基本情報は `store_users` / `cast_profiles` / `users` に分散
- 住所テーブルは未整備
- 既存画面では以下が基点にしやすい
  - [public/store_casts.php](/Users/newname/webproject/wbss/public/store_casts.php)
  - [public/profile.php](/Users/newname/webproject/wbss/public/profile.php)
  - [public/manager_today_schedule.php](/Users/newname/webproject/wbss/public/manager_today_schedule.php)

## 先に決めるべき前提

- 送迎の到着先は「その日の出勤店舗」
- 1キャストは 1日 1店舗を基本とする
- まずは出勤時の迎車を優先し、退勤時の送りは第2段階で追加する
- 最短判定は距離だけでなく、将来的には「到着希望時刻」「同乗人数」「車両数」も考慮する
- 住所の生値は個人情報なので、運用上は閲覧権限を `manager/admin/super_user` に制限する

## おすすめDB構成

### 1. キャスト送迎プロフィール

`cast_transport_profiles`

- 役割
  - キャストの送迎用住所の正本
  - 緯度経度の保持
  - 迎車可否、備考、最寄り目印などを管理
- 理由
  - `cast_profiles` に住所を直接足すと、プロフィールと送迎個人情報が混ざる
  - 権限制御と将来の履歴管理を分けやすい

主な列:

- `store_id`
- `user_id`
- `pickup_zip`
- `pickup_prefecture`
- `pickup_city`
- `pickup_address1`
- `pickup_address2`
- `pickup_building`
- `pickup_note`
- `pickup_lat`
- `pickup_lng`
- `pickup_geocoded_at`
- `pickup_enabled`
- `privacy_level`
- `created_by_user_id`
- `updated_by_user_id`

### 2. 店舗の送迎基点

`store_transport_bases`

- 役割
  - 店舗到着地点、または送迎車の出発拠点を管理
- 理由
  - `stores` に直接増やしてもよいが、複数拠点や将来の車庫追加を考えると別表が安全

主な列:

- `store_id`
- `base_type` (`store`, `garage`, `meeting_point`)
- `name`
- `address_text`
- `lat`
- `lng`
- `is_default`

### 3. 生成された送迎プラン

`transport_route_plans`

- 役割
  - 指定日、対象店舗、最適化条件ごとの計算結果ヘッダ
- 理由
  - 毎回再計算だけだと、現場で確定した配車順が残らない

主な列:

- `business_date`
- `store_id`
- `plan_status` (`draft`, `confirmed`, `completed`)
- `direction` (`pickup`, `dropoff`)
- `target_arrival_time`
- `optimizer_version`
- `total_distance_km`
- `total_duration_min`
- `vehicle_count`
- `created_by_user_id`

### 4. ルート停車順

`transport_route_stops`

- 役割
  - どのキャストを何番目に迎えに行くかを保持

主な列:

- `route_plan_id`
- `stop_order`
- `cast_user_id`
- `store_id`
- `stop_type` (`pickup`, `dropoff`, `store_arrival`)
- `planned_at`
- `travel_minutes_from_prev`
- `distance_km_from_prev`
- `source_lat`
- `source_lng`
- `dest_lat`
- `dest_lng`
- `address_snapshot`

## なぜこの分け方がよいか

- 出勤予定の正本である `cast_shift_plans` はそのまま活かせる
- 個人情報の住所を既存プロフィールから分離できる
- ルート計算結果を保存できるので、再計算前後の差分確認がしやすい
- 将来「車両」「ドライバー」「退勤送迎」を追加しやすい

## MVPで使う抽出ロジック

対象キャスト:

- `cast_shift_plans.status = 'planned'`
- `cast_shift_plans.is_off = 0`
- `cast_transport_profiles.pickup_enabled = 1`
- 緯度経度が登録済み

基本JOINイメージ:

```sql
SELECT
  sp.store_id,
  sp.user_id,
  sp.business_date,
  sp.start_time,
  u.display_name,
  ctp.pickup_lat,
  ctp.pickup_lng,
  ctp.pickup_address1,
  ctp.pickup_address2
FROM cast_shift_plans sp
JOIN users u
  ON u.id = sp.user_id
JOIN cast_transport_profiles ctp
  ON ctp.store_id = sp.store_id
 AND ctp.user_id = sp.user_id
WHERE sp.business_date = :business_date
  AND sp.status = 'planned'
  AND sp.is_off = 0
  AND ctp.pickup_enabled = 1
  AND ctp.pickup_lat IS NOT NULL
  AND ctp.pickup_lng IS NOT NULL
ORDER BY sp.store_id, sp.start_time, u.display_name;
```

## ルート最適化の考え方

### MVP

- 店舗ごとに対象キャストをまとめる
- 同じ店舗でも開始時刻が大きく違う場合は便を分ける
- 1便ごとに「店舗を終点」とする巡回順を計算する
- まずは単一車両の最短順序から始める

### 第2段階

- 複数車両
- 乗車定員
- キャストごとの迎車可能時間帯
- ドライバー開始地点固定
- 退勤送迎

## ページ案

### 1. キャスト送迎設定

候補URL:

- `/public/cast_transport_profiles.php`
- もしくは [public/profile.php](/Users/newname/webproject/wbss/public/profile.php) に管理者向け編集導線を追加

表示項目:

- 店舗
- キャスト名
- 郵便番号
- 住所
- 建物名
- 目印メモ
- 地図座標
- 送迎対象ON/OFF
- 最終ジオコーディング日時

操作:

- 住所保存
- 地図ピン確認
- ジオコーディング再実行

### 2. 送迎ルート最適化一覧

候補URL:

- `/public/transport_routes.php`

画面構成:

- 上部フィルタ
  - 営業日
  - 対象店舗（全店舗 or 単独店舗）
  - 方向（迎車 / 送り）
  - 到着基準時刻
- 中段サマリー
  - 対象キャスト数
  - 住所未登録数
  - ルート生成済み便数
- 下段
  - 店舗ごとの候補便一覧
  - 「最短ルート生成」ボタン
  - 距離、所要時間、順番

### 3. 送迎ルート詳細

候補URL:

- `/public/transport_route_detail.php?id=...`

表示項目:

- 停車順
- キャスト名
- 住所スナップショット
- 前地点からの移動時間
- 店舗到着予定
- 確定 / 手動並び替え

## 画面の入れ方

おすすめの導線:

- [public/store_casts.php](/Users/newname/webproject/wbss/public/store_casts.php)
  - 各キャスト行に「送迎設定」ボタン
- [public/manager_today_schedule.php](/Users/newname/webproject/wbss/public/manager_today_schedule.php)
  - 上部に「送迎最適化」ボタン
- 新規の [public/transport_routes.php](/Users/newname/webproject/wbss/public/transport_routes.php)
  - 管理者向けの一覧画面

## 実装順のおすすめ

1. `cast_transport_profiles` と `store_transport_bases` を追加
2. キャスト住所登録画面を作る
3. 出勤予定と住所をJOINした送迎対象一覧を作る
4. ルート計算結果を保存する `transport_route_plans` / `transport_route_stops` を追加
5. 最短ルート生成ボタンと詳細画面を作る
6. 外部Map API連携で実距離ベース最適化に切り替える

## 重要な注意

- 本当に「最短」にしたいなら、住所文字列だけでは足りず、緯度経度が必須
- さらに道路距離ベース最適化には Map API またはルーティングエンジンが必要
- まずは DB と画面を先に整え、計算エンジンは段階導入にした方が安全

## このリポジトリでの実装起点

- 出勤予定参照: [public/manager_today_schedule.php](/Users/newname/webproject/wbss/public/manager_today_schedule.php)
- キャスト一覧: [public/store_casts.php](/Users/newname/webproject/wbss/public/store_casts.php)
- キャストプロフィール: [public/profile.php](/Users/newname/webproject/wbss/public/profile.php)
- キャスト取得共通: [app/repo_casts.php](/Users/newname/webproject/wbss/app/repo_casts.php)
