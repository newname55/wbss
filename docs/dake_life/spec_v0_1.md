# DAKE_LIFE 初期仕様書 v0.1

---

# 0. 概要

## システム名
DAKE_LIFE

## コンセプト
現実の行動ログをもとに、ユーザーの「流れ・偏り・変化」を可視化し、  
オモテダケのメッセージとして返すライフゲーム。

## 一言定義
行動によって運勢が変わる、リアル人生ゲーム

---

# 1. 目的

DAKE_LIFE の目的は以下の3つ。

1. 行動に意味を与える  
2. 現在の状態（流れ・偏り）を可視化する  
3. 次の行動を優しく促す  

未来を当てるのではなく、  
未来を少し良い方向に動かすシステムとする。

---

# 2. スコープ（v0.1）

## 含む機能
- 行動ログ登録
- 運勢スコア算出
- フェーズ判定
- メッセージ生成（テンプレ）
- 日次スナップショット保存
- API提供

## 含まない
- AI最適化
- SNS機能
- 課金
- 高度分析UI

---

# 3. 用語定義

| 用語 | 意味 |
|------|------|
| Action | ユーザーの行動 |
| Score | 運勢数値 |
| Phase | 現在の状態（章） |
| Rule | 行動→スコア変換ルール |
| Snapshot | 日次履歴 |
| Message | 表示メッセージ |

---

# 4. 運勢パラメータ

4軸で管理する。

## encounter_score
出会い・広がり・新しい接点

## comfort_score
安心・継続・居場所

## challenge_score
挑戦・新規行動・変化

## flow_score
流れ・タイミング・巡り

---

## 初期値
すべて 50

## 範囲
0〜100（clamp）

---

## 減衰（1日あたり）

| 項目 | 減衰 |
|------|------|
| encounter | -0.8 |
| comfort | -0.3 |
| challenge | -0.7 |
| flow | -0.9 |

---

# 5. フェーズ定義

| code | 名称 | 概要 |
|------|------|------|
| sprout | 芽吹き期 | 変化が始まった状態 |
| flowing | 巡り期 | 流れが良い状態 |
| plateau | 足踏み期 | 安定・変化少 |
| biased | 偏り期 | 同じ行動に偏る |
| challenge | 挑戦期 | 新規行動多 |
| rest | 休息期 | 行動少・静かな状態 |

---

# 6. action_type 定義

v0.1で使用する行動種別。

- new_place_visit
- repeat_place_visit
- new_person_contact
- repeat_person_contact
- quick_return
- long_gap_return
- long_stay
- inactive_day
- special_event_join

---

# 7. DB設計

## DB名
dake_life

---

## テーブル一覧

- dl_users
- dl_action_logs
- dl_fortune_states
- dl_fortune_rules
- dl_daily_snapshots
- dl_message_templates

---

## dl_users

ユーザー管理

---

## dl_action_logs

行動ログ

---

## dl_fortune_states

現在の運勢状態

---

## dl_fortune_rules

行動→スコア変換ルール

---

## dl_daily_snapshots

日次履歴

---

## dl_message_templates

メッセージテンプレ

---

# 8. ルール設計

## 基本構造

action_type → effect_json

---

## 例

### new_place_visit

- encounter +12
- challenge +15
- comfort -2
- flow +4

---

### repeat_place_visit

- comfort +10
- flow +4
- challenge -3

---

### inactive_day

- encounter -1
- challenge -1
- flow -2

---

# 9. 再計算ロジック

## 処理フロー

1. 初期値50セット
2. 直近30日ログ取得
3. ルール適用
4. 減衰適用
5. 偏り補正
6. clamp(0〜100)
7. phase判定
8. DB保存

---

## 偏り補正

以下条件で発動：

- repeat系が多い
- new系が少ない

処理：

- challenge減少
- biasedフェーズ寄せ

---

# 10. API設計

## POST /api/life/actions

行動登録

---

## POST /api/life/users/{id}/recalc

再計算

---

## GET /api/life/users/{id}/state

状態取得

---

## GET /api/life/users/{id}/message

メッセージ取得

---

## GET /api/life/users/{id}/snapshots

履歴取得

---

# 11. メッセージ仕様

## JSON構造
{
“headline”: “”,
“phase_text”: “”,
“strong_point”: “”,
“warning”: “”,
“suggestion”: “”
}
---

## 文体ルール

- 優しい
- 断定しない
- 詩的
- 行動を軽く促す
- 「ダケ」を適度に使用

---

# 12. 日次バッチ

## 処理内容

- スコア減衰
- snapshot保存

---

# 13. ディレクトリ構成
app/DakeLife/
public/api/life/
docs/dake_life/
cron/
# 14. 拡張余地

- AIメッセージ生成
- スロット連携
- タロット連携
- 位置情報（神社など）
- ミッション機能
- 実績システム
- 感情ログ

---

# 15. 成功条件

- 行動で運勢が変わる
- 毎日見たくなる
- メッセージが刺さる
- 次の行動が自然に分かる

---

# 16. 本質

DAKE_LIFEは占いではない。

行動 → 状態 → 物語 → 次の行動

この循環を作るシステムである。

---

# 17. 実装反映差分（v0.1.1）

本セクションは、実装に基づき v0.1 仕様からの差分を明確化する。

---

## 17.1 行動ログ入力仕様の更新

### 正本フィールド

- `occurred_at`（DATETIME）を正本とする

```json
{
  "user_id": 1,
  "action_type": "new_place_visit",
  "occurred_at": "2026-03-18 10:00:00"
}