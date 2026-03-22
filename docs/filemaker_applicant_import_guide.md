# FileMaker 面接台帳 取込ガイド

## 1. 方針

直接 `wbss_applicant_*` に流し込まず、まず `fm_applicant_import_raw` に取り込み、その後に正規テーブルへ変換します。

理由:

- FileMaker 項目名の揺れを吸収しやすい
- 取込失敗行だけを再処理しやすい
- 移行中に元データを見失わない
- 本番画面のテーブルへ安全に変換できる

## 2. 実行手順

### 2-1. 生テーブル作成

```sql
SOURCE /Users/newname/webproject/wbss/docs/add_filemaker_applicant_import_tables.sql;
```

### 2-2. CSV / TAB を raw へ投入

```bash
php /Users/newname/webproject/wbss/bin/import_filemaker_applicants.php \
  --mode=raw \
  --in="/path/to/filemaker_export.tab" \
  --batch_key="fm_20260320_01"
```

### 2-3. raw から本テーブルへ変換

```bash
php /Users/newname/webproject/wbss/bin/import_filemaker_applicants.php \
  --mode=transform \
  --batch_key="fm_20260320_01"
```

### 2-4. 結果確認

```sql
SELECT process_status, COUNT(*)
FROM fm_applicant_import_raw
WHERE batch_key = 'fm_20260320_01'
GROUP BY process_status;
```

## 3. 取込時の対応

### 人物マスタへ入るもの

- `姓` / `名`
- `姓かな` / `名かな`
- `生年月日`
- `電話番号`
- `現住所`
- `以前住所`
- `血液型`
- `身体情報`
- `顔写真パス`

### 面接テーブルへ入るもの

- `面接日`
- `面接時刻`
- `面接店舗`
- `面接担当者`
- `面接結果`
- `応募経路`
- `希望時給` / `希望日給`
- `体験入店状態`
- `体験入店日`
- `入店判定`

### 在籍履歴へ入るもの

- `在籍状態`
- `現在店舗`
- `入店日`
- `移動日`
- `退店日`
- `源氏名`
- `移動理由`
- `退店理由`

## 4. 推奨 CSV 列名

`.csv` はコンマ区切り、`.tab` / `.tsv` はタブ区切りとして自動判定します。

このバッチは日本語ヘッダと英語ヘッダの両方にある程度対応しています。特に使いやすいのは次の列名です。

| CSV列名 | 変換先 |
| --- | --- |
| `レコード番号` | `legacy_record_no` |
| `ID` | `legacy_record_no` |
| `応募者コード` | `person_code` |
| `氏` | `last_name` |
| `姓` | `last_name` |
| `名` | `first_name` |
| `氏ふりがな` | `last_name_kana` |
| `姓かな` | `last_name_kana` |
| `名ふりがな` | `first_name_kana` |
| `名かな` | `first_name_kana` |
| `生年月日` | `birth_date_text` |
| `生年月日年号2` / `生年月日月` / `生年月日日` | `birth_date_text` 補助 |
| `携帯電話` | `phone` |
| `電話番号` | `phone` |
| `現住所県` / `現住所郡市区` / `現住所それ以降の住所` | `current_address` 補助 |
| `現住所` | `current_address` |
| `以前住所` | `previous_address` |
| `血液型` | `blood_type` |
| `顔写真パス` | `photo_original_path` |
| `顔写真URL` | `photo_public_url` |
| `面接日` | `interview_date_text` |
| `面接時刻` | `interview_time_text` |
| `面接店舗コード` | `interview_store_code` |
| `店番` | `interview_store_code` / `current_store_code` |
| `勤務店舗名` | `interview_store_name` / `current_store_name` |
| `面接場所` | `interview_store_name` |
| `面接店舗` | `interview_store_name` |
| `面接担当者ログインID` | `interviewer_login_id` |
| `担当者` | `interviewer_name` |
| `面接担当者` | `interviewer_name` |
| `面接結果状態` | `interview_result` |
| `面接結果` | `interview_result` |
| `体験入店状態` | `trial_status` |
| `体験入店日` | `trial_date_text` |
| `入店判定` | `join_decision` |
| `入店日` | `join_date_text` |
| `在籍状態` | `current_status` |
| `現在店舗コード` | `current_store_code` |
| `現在店舗` | `current_store_name` |
| `源氏名` | `genji_name` |
| `退店日` | `left_at_text` |
| `退職日` | `left_at_text` |
| `退店理由` | `leave_reason` |
| `移動理由` | `move_reason` |
| `見た目点` | `appearance_score` |
| `会話力点` | `communication_score` |
| `意欲点` | `motivation_score` |
| `清潔感点` | `cleanliness_score` |
| `営業力点` | `sales_potential_score` |
| `定着見込点` | `retention_potential_score` |

画像のスクリーンショットに出ていた `レギュラー・バイト` `前職期間` `前職給与日給` `満年齢` `本数1日` `服のサイズ` は、現時点では `notes` 側へ退避します。

## 5. 写真の扱い

- FileMaker のコンテナ書き出しファイルを別途保存する
- CSV には `顔写真パス` か `顔写真URL` を持たせる
- 変換バッチは `photo_public_url` があればそのまま使う
- `photo_original_path` だけある場合は URL へ変換せず raw に残す
- 本番で使うには別途 `public/uploads/applicants/...` へコピーして `wbss_applicant_photos.file_path` に反映する

## 6. 注意点

- 電話番号では名寄せしない
- 再取込の基準は `legacy_source='filemaker'` と `legacy_record_no`
- 同じ raw 行を二重変換しないよう、`process_status` を更新する
- 店舗解決は `stores.code` 優先、次に `stores.name`
- 面接担当者解決は `users.login_id` 優先、次に `users.display_name`

## 7. 次にやると良いこと

- 写真ファイルの一括コピー
- FileMaker 項目対応の確定版作成
- 本番用の batch_key 運用ルール決定
- エラー行の手修正フロー作成
