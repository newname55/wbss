# 面接台帳 / 体験入店 / 在籍管理 / 店舗移動管理 設計

## 1. 設計概要

### テーブル一覧

| テーブル | 役割 |
| --- | --- |
| `wbss_applicant_persons` | 人物マスタ。固定属性と一覧高速表示用の current 状態を保持 |
| `wbss_applicant_photos` | 顔写真などのファイル管理。将来の複数画像拡張用 |
| `wbss_applicant_interviews` | 面接 1 回ごとの記録 |
| `wbss_applicant_interview_scores` | 面接評価の点数明細 |
| `wbss_applicant_store_assignments` | 在籍 / 移動 / 退店を含む店舗所属履歴 |
| `wbss_applicant_status_logs` | 状態遷移・操作ログ |

### 関係

- `wbss_applicant_persons` 1 : N `wbss_applicant_interviews`
- `wbss_applicant_persons` 1 : N `wbss_applicant_photos`
- `wbss_applicant_persons` 1 : N `wbss_applicant_store_assignments`
- `wbss_applicant_interviews` 1 : 1 `wbss_applicant_interview_scores`
- `wbss_applicant_persons` 1 : N `wbss_applicant_status_logs`
- `wbss_applicant_interviews.interview_store_id` は既存 `stores.id`
- `wbss_applicant_interviews.interviewer_user_id` は既存 `users.id`
- `wbss_applicant_store_assignments.store_id` は既存 `stores.id`

### なぜ分離するか

- 人物マスタと面接を分ける理由  
  同一人物に複数面接が発生するため。氏名や生年月日などの固定情報は人物、面接日・担当者・結果・評価は面接に分ける。
- 面接と在籍を分ける理由  
  面接に通っても即在籍ではない。体験入店、保留、不採用、再面接があるため、採用判定フローと在籍状態を同じ列で持つと履歴が壊れる。
- 在籍履歴を独立させる理由  
  店舗移動、再入店、退店を期間で追えるようにするため。現在状態はここから導出し、人物テーブルへサマリ反映する。
- 写真を別テーブルにする理由  
  顔写真必須に対応しつつ、将来の複数画像、差し替え履歴、サムネイル追加に拡張しやすい。

## 2. current status の表現

### 正本

- 正本は `wbss_applicant_store_assignments` の `is_current=1` 行
- 体験入店など在籍前の状態は `wbss_applicant_persons.current_status` と最新面接で表現

### 一覧高速化用のサマリ

`wbss_applicant_persons` に以下を持つ。

- `current_status`
- `is_currently_employed`
- `current_store_id`
- `current_assignment_id`
- `latest_interview_id`
- `latest_interviewed_at`
- `latest_interview_result`
- `primary_photo_id`

これにより一覧画面は人物テーブル中心で取得でき、最優先要件の「現在在籍」「現在店舗」を速く表示できる。

## 3. 履歴の残し方

- 面接履歴: `wbss_applicant_interviews`
- 評価履歴: `wbss_applicant_interview_scores`
- 所属履歴: `wbss_applicant_store_assignments`
- 操作ログ: `wbss_applicant_status_logs`

移動時は「旧所属を終了」し「新所属を開始」する。削除ではなく期間を閉じる。

## 4. 特に欲しい設計判断への回答

### 1. 人物マスタと面接テーブルをどう分けるか

- 人物マスタ  
  氏名、かな、生年月日、電話番号、住所、血液型、写真、身体情報など
- 面接テーブル  
  面接日、面接店舗、担当者、結果、応募経路、希望条件、体験入店状態、採用判定メモなど

判断基準は「その値が面接のたびに変わりうるか」。変わるなら面接、基礎属性なら人物。

### 2. `current_store_id` をどこで持つべきか

- 正本は `wbss_applicant_store_assignments`
- 一覧高速化用のキャッシュとして `wbss_applicant_persons.current_store_id` に保持

つまり「履歴は所属履歴テーブル」「一覧最適化は人物テーブル」の二段構えにする。

### 3. 在籍履歴と店舗移動履歴を同じテーブルで持つべきか

同じテーブルで持つべき。  
1 行を「ある期間、ある店舗に所属した事実」と定義すると、入店・移動・再入店・退店を一貫して扱える。移動は終了行と開始行の組み合わせで表現する。

### 4. 面接結果と在籍状態をどう分けるか

- 面接結果: `wbss_applicant_interviews.interview_result`
- 在籍状態: `wbss_applicant_persons.current_status` と `wbss_applicant_store_assignments`

面接結果は選考イベントの結果、在籍状態は現在所属の事実であり、意味が違うので分ける。

### 5. 一覧画面で「現在在籍」「現在店舗」を速く出すための設計

- 一覧の起点は `wbss_applicant_persons`
- `current_store_id` と `is_currently_employed` を人物に保持
- 店舗名は `stores` を 1 回 join
- 最新面接情報も人物にキャッシュ
- 詳細時だけ履歴テーブルを掘る

### 6. FileMaker からの移行を見据えたインポート方針

- まず FileMaker の 1 レコードを「人物」と「最新面接」に分解
- 重複人物判定は電話番号に頼らず、移行用に `legacy_source` と `legacy_record_no` を保持
- 既に在籍扱いのデータは `wbss_applicant_store_assignments` へ current 行を生成
- 退店済みは `end_date` を埋めた履歴として投入
- 面接履歴が複数ある場合は時系列で面接テーブルへ入れる
- CSV 取込では一時テーブルを作り、変換後に本テーブルへ流す方針を推奨

## 5. 画面構成

### 面接者一覧ページ

- 最優先表示
  - 在籍状況
  - 現在店舗
- 表示項目
  - 顔写真有無
  - 人物ID
  - 氏名
  - ふりがな
  - 年齢
  - 電話番号
  - 面接担当者
  - 最新面接日
  - 最新面接結果
  - 体験入店状態
  - 在籍状態
  - 現在店舗
  - 源氏名
  - 更新日
- フィルタ
  - 氏名
  - 電話番号
  - 面接担当者
  - 店舗
  - 在籍中 / 非在籍
  - 体験入店中
  - 面接結果
  - 退店済み
  - 最新面接日範囲

### 面接者新規作成 / 詳細編集ページ

- タブ
  - 基本情報
  - 面接情報
  - 現在の状況
  - 担当者記入項目
  - 身体情報
  - 経歴 / 条件
  - 評価
  - 履歴
- サブ操作
  - 顔写真アップロード
  - 面接追加
  - 体験入店更新
  - 在籍化
  - 店舗移動
  - 退店

## 6. 実装骨組み

- `docs/create_applicant_management_tables.sql`
- `app/repo_applicants.php`
- `app/service_applicants.php`
- `public/applicants/index.php`
- `public/applicants/detail.php`
- `public/applicants/save.php`
- `public/applicants/upload_photo.php`
- `public/applicants/actions/add_interview.php`
- `public/applicants/actions/change_status.php`
- `public/applicants/actions/move_store.php`

## 7. MVP 実装範囲

- 一覧表示
- 新規登録
- 詳細編集
- 顔写真アップロード
- 面接記録追加
- 現在在籍状態の表示
- 現在店舗の表示
- 体験入店 / 在籍 / 退店 の更新
- 店舗移動履歴
- 評価点入力

## 8. UI 方針

- PC: テーブル + 詳細カード + タブ
- スマホ: タブを横スクロール、カード縦積み
- ステータスは色付きバッジ
- `現在店舗` は強調チップ表示
- 写真は常時表示枠を確保
- FileMaker 利用者が迷わないよう、情報密度は高めにする
