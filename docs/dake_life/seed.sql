USE dake_life;

INSERT INTO dl_fortune_rules (
  action_type,
  effect_json,
  note_text,
  is_active
) VALUES
  ('new_place_visit', '{"encounter":12,"comfort":-2,"challenge":15,"flow":4}', '新しい場所へ行く', 1),
  ('repeat_place_visit', '{"encounter":1,"comfort":10,"challenge":-3,"flow":4}', 'いつもの場所へ行く', 1),
  ('new_person_contact', '{"encounter":14,"comfort":1,"challenge":8,"flow":5}', '新しい人と接点を持つ', 1),
  ('repeat_person_contact', '{"encounter":2,"comfort":9,"challenge":-2,"flow":3}', 'いつもの人と接点を持つ', 1),
  ('quick_return', '{"encounter":0,"comfort":5,"challenge":-4,"flow":2}', '短い間隔で戻る', 1),
  ('long_gap_return', '{"encounter":6,"comfort":8,"challenge":7,"flow":10}', '久しぶりに戻る', 1),
  ('long_stay', '{"encounter":1,"comfort":12,"challenge":2,"flow":6}', '長く滞在する', 1),
  ('inactive_day', '{"encounter":-1,"comfort":0,"challenge":-1,"flow":-2}', '行動が少ない日', 1),
  ('special_event_join', '{"encounter":10,"comfort":6,"challenge":10,"flow":12}', '特別イベントへ参加する', 1)
ON DUPLICATE KEY UPDATE
  effect_json = VALUES(effect_json),
  note_text = VALUES(note_text),
  is_active = VALUES(is_active),
  updated_at = CURRENT_TIMESTAMP;

INSERT INTO dl_message_templates (
  phase_code,
  template_code,
  min_total_score,
  max_total_score,
  template_text,
  is_active,
  sort_order
) VALUES
  ('sprout', 'sprout_balanced', 0, 100, '{display_name}さんはいま「{phase_name}」。新しい動きが少しずつ育っています。特に{top_axis_name}が{top_axis_score}まで伸びています。次は{hint_action}を足すと流れがつながりやすいです。', 1, 10),
  ('flowing', 'flowing_balanced', 0, 100, '{display_name}さんはいま「{phase_name}」。流れが自然につながっていて、{top_axis_name}が強みになっています。今の良さを保ちながら、{hint_action}をひとつ加えると巡りが安定します。', 1, 10),
  ('plateau', 'plateau_balanced', 0, 100, '{display_name}さんはいま「{phase_name}」。落ち着きはありますが、少し同じ景色が続きやすい時期です。小さくても{hint_action}を入れると変化のきっかけになります。', 1, 10),
  ('biased', 'biased_balanced', 0, 100, '{display_name}さんはいま「{phase_name}」。安心はある一方で、行動の偏りが出やすい状態です。今日はあえて{hint_action}を試すと、challenge と flow が戻りやすくなります。', 1, 10),
  ('challenge', 'challenge_balanced', 0, 100, '{display_name}さんはいま「{phase_name}」。変化を受け止める力が高まっています。勢いを空回りさせないために、{hint_action}で comfort を少し補うのがおすすめです。', 1, 10),
  ('rest', 'rest_balanced', 0, 100, '{display_name}さんはいま「{phase_name}」。静かに整える時間です。無理に増やすより、まずは{hint_action}のような軽い一歩で十分です。', 1, 10),
  ('default', 'default_balanced', 0, 100, '{display_name}さんの今の流れは「{phase_name}」です。いちばん高いのは {top_axis_name} の {top_axis_score}。次の一歩として {hint_action} を意識すると、全体の巡りが整いやすくなります。', 1, 999)
ON DUPLICATE KEY UPDATE
  phase_code = VALUES(phase_code),
  min_total_score = VALUES(min_total_score),
  max_total_score = VALUES(max_total_score),
  template_text = VALUES(template_text),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order),
  updated_at = CURRENT_TIMESTAMP;
