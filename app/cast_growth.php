<?php
declare(strict_types=1);

require_once __DIR__ . '/service_quiz.php';
require_once __DIR__ . '/service_training.php';
require_once __DIR__ . '/service_training_badge_logic.php';
require_once __DIR__ . '/service_training_mission_logic.php';
require_once __DIR__ . '/service_coaching_logic.php';

function cast_growth_type_image_path(string $typeKey): string {
  $typeKey = trim($typeKey);
  if ($typeKey === '') {
    $typeKey = 'all_rounder';
  }
  return '/wbss/public/images/cast_type_images/' . rawurlencode($typeKey) . '.png';
}

function get_cast_latest_service_type(PDO $pdo, int $storeId, int $castId): ?array {
  return service_quiz_fetch_latest_result($pdo, $storeId, $castId);
}

function get_cast_weak_skill_tags(PDO $pdo, int $storeId, int $castId, int $limit = 3): array {
  return service_training_fetch_recent_weak_tags($pdo, $storeId, $castId, 10, $limit);
}

function cast_growth_decode_json_list(?string $json): array {
  $rows = json_decode((string)$json, true);
  return is_array($rows) ? $rows : [];
}

function cast_growth_result_json(?string $json): array {
  $row = json_decode((string)$json, true);
  return is_array($row) ? $row : [];
}

function cast_growth_fetch_training_summaries(PDO $pdo, int $storeId, int $castId, int $limit = 3): array {
  if (!service_training_history_tables_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT *
    FROM cast_service_training_sessions
    WHERE store_id = ?
      AND cast_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT ?
  ");
  $st->bindValue(1, $storeId, PDO::PARAM_INT);
  $st->bindValue(2, $castId, PDO::PARAM_INT);
  $st->bindValue(3, max(1, $limit), PDO::PARAM_INT);
  $st->execute();

  $rows = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $resultJson = cast_growth_result_json((string)($row['result_json'] ?? '{}'));
    $rows[] = [
      'id' => (int)($row['id'] ?? 0),
      'created_at' => (string)($row['created_at'] ?? ''),
      'result_type_key' => (string)($row['result_type_key'] ?? ''),
      'result_type_name' => (string)($row['result_type_name'] ?? ''),
      'answered_count' => (int)($row['answered_count'] ?? 0),
      'total_score' => (int)($row['total_score'] ?? 0),
      'strong_points' => cast_growth_decode_json_list((string)($row['strong_points_json'] ?? '[]')),
      'stretch_points' => cast_growth_decode_json_list((string)($row['stretch_points_json'] ?? '[]')),
      'weak_tags' => cast_growth_decode_json_list((string)($row['weak_tags_json'] ?? '[]')),
      'focus_skills' => array_values((array)($resultJson['focus_skills'] ?? [])),
      'best_category' => (string)($resultJson['best_category'] ?? ''),
      'weak_category' => (string)($resultJson['weak_category'] ?? ''),
      'summary' => (string)($resultJson['summary'] ?? ''),
      'today_tip' => (string)($resultJson['today_tip'] ?? ''),
    ];
  }

  return $rows;
}

function cast_growth_training_theme_label(array $growthTheme, ?array $latestTraining): string {
  $focusSkills = array_values((array)($latestTraining['focus_skills'] ?? []));
  if ($focusSkills) {
    return implode(' / ', array_slice(array_map('strval', $focusSkills), 0, 2));
  }
  $themeSkills = array_values((array)($growthTheme['focus_skills'] ?? []));
  if ($themeSkills) {
    return implode(' / ', array_slice(array_map('strval', $themeSkills), 0, 2));
  }
  return '育成テーマ準備中';
}

function get_cast_mission_stats(PDO $pdo, int $storeId, int $castId, int $days = 7): array {
  $summary = [
    'total_logs' => 0,
    'done_count' => 0,
    'pending_count' => 0,
    'skipped_count' => 0,
    'achievement_rate' => 0,
    'streak_days' => service_training_done_streak($pdo, $storeId, $castId),
  ];

  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return $summary;
  }

  $st = $pdo->prepare("
    SELECT status, COUNT(*) AS c
    FROM cast_service_training_mission_logs
    WHERE store_id = ?
      AND cast_id = ?
      AND log_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY status
  ");
  $st->bindValue(1, $storeId, PDO::PARAM_INT);
  $st->bindValue(2, $castId, PDO::PARAM_INT);
  $st->bindValue(3, max(1, $days) - 1, PDO::PARAM_INT);
  $st->execute();

  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $status = (string)($row['status'] ?? '');
    $count = (int)($row['c'] ?? 0);
    $summary['total_logs'] += $count;
    if ($status === 'done') {
      $summary['done_count'] = $count;
    } elseif ($status === 'pending') {
      $summary['pending_count'] = $count;
    } elseif ($status === 'skipped') {
      $summary['skipped_count'] = $count;
    }
  }

  if ($summary['total_logs'] > 0) {
    $summary['achievement_rate'] = (int)round(($summary['done_count'] / $summary['total_logs']) * 100);
  }

  return $summary;
}

function cast_growth_recent_mission_ids(PDO $pdo, int $storeId, int $castId, int $limit = 5): array {
  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT mission_id
    FROM cast_service_training_mission_logs
    WHERE store_id = ?
      AND cast_id = ?
      AND mission_id <> ''
    ORDER BY log_date DESC, id DESC
    LIMIT ?
  ");
  $st->bindValue(1, $storeId, PDO::PARAM_INT);
  $st->bindValue(2, $castId, PDO::PARAM_INT);
  $st->bindValue(3, max(1, $limit), PDO::PARAM_INT);
  $st->execute();

  return array_values(array_filter(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(string $id): bool => $id !== ''));
}

function cast_growth_select_current_mission(string $typeKey, array $weakSkillTags, array $growthTheme, array $recentMissionIds = []): array {
  $recommendedCategories = array_values(array_map('strval', (array)($growthTheme['recommended_categories'] ?? [])));
  $best = null;

  foreach (service_training_mission_pool() as $mission) {
    if (!is_array($mission)) {
      continue;
    }

    $missionId = (string)($mission['id'] ?? '');
    if ($missionId === '') {
      continue;
    }

    $score = 0;
    $category = (string)($mission['category'] ?? '');
    $skillTag = (string)($mission['skill_tag'] ?? '');
    $difficulty = (int)($mission['difficulty'] ?? 2);
    $types = array_values(array_map('strval', (array)($mission['recommended_for_types'] ?? [])));

    if (isset($weakSkillTags[$skillTag])) {
      $score += 34 + ((int)$weakSkillTags[$skillTag] * 8);
    }
    if (in_array($typeKey, $types, true)) {
      $score += 22;
    }
    if (in_array($category, $recommendedCategories, true)) {
      $score += 14;
    }
    if ($difficulty <= 1) {
      $score += 12;
    } elseif ($difficulty === 2) {
      $score += 6;
    }
    if (in_array($missionId, $recentMissionIds, true)) {
      $score -= 28;
    }

    $candidate = [
      'score' => $score,
      'difficulty' => $difficulty,
      'mission_id' => $missionId,
      'mission' => $mission,
    ];

    if ($best === null) {
      $best = $candidate;
      continue;
    }

    if ($candidate['score'] > $best['score']) {
      $best = $candidate;
      continue;
    }
    if ($candidate['score'] === $best['score'] && $candidate['difficulty'] < $best['difficulty']) {
      $best = $candidate;
      continue;
    }
    if ($candidate['score'] === $best['score'] && $candidate['difficulty'] === $best['difficulty'] && strcmp($candidate['mission_id'], $best['mission_id']) < 0) {
      $best = $candidate;
    }
  }

  return is_array($best['mission'] ?? null) ? $best['mission'] : [];
}

function cast_growth_current_mission(PDO $pdo, int $storeId, int $castId, string $typeKey, array $growthTheme, array $weakSkillTags): array {
  if (service_training_mission_logs_table_ready($pdo)) {
    $today = service_training_get_today_mission_log($pdo, $storeId, $castId);
    if (is_array($today) && trim((string)($today['mission_id'] ?? '')) !== '') {
      $mission = service_training_find_mission((string)$today['mission_id']);
      if ($mission) {
        $mission['status'] = (string)($today['status'] ?? '');
        $mission['log_date'] = (string)($today['log_date'] ?? '');
        return $mission;
      }
    }
  }

  return cast_growth_select_current_mission($typeKey, $weakSkillTags, $growthTheme, cast_growth_recent_mission_ids($pdo, $storeId, $castId, 5));
}

function get_cast_recent_missions(PDO $pdo, int $storeId, int $castId, int $limit = 7): array {
  if (!service_training_mission_logs_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT log_date, mission_id, mission_title, mission_category, skill_tag, status
    FROM cast_service_training_mission_logs
    WHERE store_id = ?
      AND cast_id = ?
    ORDER BY log_date DESC, id DESC
    LIMIT ?
  ");
  $st->bindValue(1, $storeId, PDO::PARAM_INT);
  $st->bindValue(2, $castId, PDO::PARAM_INT);
  $st->bindValue(3, max(1, $limit), PDO::PARAM_INT);
  $st->execute();

  $rows = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $mission = service_training_find_mission((string)($row['mission_id'] ?? ''));
    if ($mission) {
      $row['mission_title'] = (string)($mission['action_text'] ?? ($row['mission_title'] ?? 'ミッション'));
    }
    $rows[] = $row;
  }

  return $rows;
}

function get_cast_badges(PDO $pdo, int $storeId, int $castId, ?array $latestQuizResult): array {
  return service_training_user_badges($pdo, $storeId, $castId, $latestQuizResult);
}

function cast_growth_status_meta(string $status): array {
  $map = [
    'good' => ['label' => '好調', 'class' => 'is-good'],
    'rising' => ['label' => '伸び中', 'class' => 'is-rising'],
    'follow' => ['label' => '要フォロー', 'class' => 'is-follow'],
    'none' => ['label' => '未分析', 'class' => 'is-none'],
  ];
  return $map[$status] ?? $map['none'];
}

function get_cast_growth_status(PDO $pdo, int $storeId, int $castId, ?array $latestQuizResult, ?array $latestTraining, array $weakTags, array $missionStats): array {
  $hasAnyData = $latestQuizResult || $latestTraining || (int)($missionStats['total_logs'] ?? 0) > 0;
  if (!$hasAnyData) {
    return cast_growth_status_meta('none');
  }

  $weakTopCount = (int)(array_values($weakTags)[0] ?? 0);
  $rate = (int)($missionStats['achievement_rate'] ?? 0);
  $streak = (int)($missionStats['streak_days'] ?? 0);

  if ($rate >= 70 && $streak >= 2 && $weakTopCount <= 2) {
    return cast_growth_status_meta('good');
  }
  if ($rate < 40 && ((int)($missionStats['total_logs'] ?? 0) >= 3 || $weakTopCount >= 3)) {
    return cast_growth_status_meta('follow');
  }
  return cast_growth_status_meta('rising');
}

function cast_growth_skill_tag_guides(): array {
  return [
    '共感返し' => [
      'coach_now' => '今日は一言共感を足してみよう。',
      'ok' => 'まず相手の言葉を短く拾ってから返してみよう。',
      'ng' => 'もっと共感して、では曖昧になりやすいです。',
    ],
    '先回り行動' => [
      'coach_now' => '今日は一回だけ、先回りで動いてみよう。',
      'ok' => '灰皿かおかわり、どちらか一つだけ先に気づければ十分です。',
      'ng' => '気づいて動いて、を一気に求めすぎない方が伸びます。',
    ],
    '締めの一言' => [
      'coach_now' => '最後のひとことで印象を残してみよう。',
      'ok' => '帰り際だけ意識すれば大丈夫。短い一言で十分です。',
      'ng' => 'もっと印象を残して、だけだと再現しづらいです。',
    ],
    '入りの笑顔' => [
      'coach_now' => '今日は最初の笑顔だけ意識してみよう。',
      'ok' => '席について最初の3秒だけやわらかく入ろう。',
      'ng' => 'ずっと笑顔で、は負担になりやすいです。',
    ],
    '特別感演出' => [
      'coach_now' => '特別感は一回だけ、軽く入れるくらいで十分です。',
      'ok' => '強く出すより、軽く一言混ぜるだけで印象は変わります。',
      'ng' => 'もっとドキドキさせて、は軽く見えやすいです。',
    ],
    '姿勢' => [
      'coach_now' => '今日は姿勢を一回整えるだけでOKです。',
      'ok' => '少し前傾で聞けると、丁寧さが自然に伝わります。',
      'ng' => 'ちゃんとして、だと身体の使い方が伝わりません。',
    ],
    '観察から行動へ' => [
      'coach_now' => '気づいたことを一回だけ行動に変えてみよう。',
      'ok' => '見えていることを一つだけ動きにすると十分伸びます。',
      'ng' => '考えるだけで終わらないで、は責めて聞こえやすいです。',
    ],
    '丁寧な盛り上げ' => [
      'coach_now' => '盛り上げのあとに、丁寧さを一言足してみよう。',
      'ok' => 'ありがとう、助かる、などを一つ入れるだけで変わります。',
      'ng' => 'ノリを抑えて、だと良さを消しやすいです。',
    ],
    '名前呼び' => [
      'coach_now' => '名前は一回だけ、自然に呼んでみよう。',
      'ok' => 'ここぞの一回に絞ると、押しつけ感なく残ります。',
      'ng' => 'もっと距離を縮めて、は雑に伝わりやすいです。',
    ],
    '自然な褒め' => [
      'coach_now' => '自然な褒めを一言だけ入れてみよう。',
      'ok' => '見た目より雰囲気や話し方を拾うと自然です。',
      'ng' => 'もっと褒めて、はわざとらしくなりやすいです。',
    ],
    '会話スタート' => [
      'coach_now' => '今日は入りの一言だけ整えてみよう。',
      'ok' => '天気や飲み物みたいな軽い話題からで十分です。',
      'ng' => 'とにかく会話を広げて、はハードルが高すぎます。',
    ],
    '灰皿交換' => [
      'coach_now' => '灰皿交換を一回先に気づけるとかなり良いです。',
      'ok' => '2〜3本たまったら一言添えて交換してみよう。',
      'ng' => 'もっと気を利かせて、では行動が曖昧です。',
    ],
    'グラス所作' => [
      'coach_now' => '今日はグラスの扱いを一回だけ丁寧にしてみよう。',
      'ok' => '渡す・受ける・置くのどれか一つに絞るとやりやすいです。',
      'ng' => 'もっと上品に、だけでは再現しづらいです。',
    ],
    '距離感調整' => [
      'coach_now' => '相手に合わせて一回だけ距離感を調整してみよう。',
      'ok' => '近づくより、相手の反応に合わせて少し動くだけで十分です。',
      'ng' => 'もっと距離を詰めて、は逆効果になりやすいです。',
    ],
    '動きの落ち着き' => [
      'coach_now' => '動きを少しゆっくりにするだけで印象は上がります。',
      'ok' => '立つか座るか、どちらか一つだけ丁寧にすれば十分です。',
      'ng' => '落ち着いて、だけでは本人が掴みにくいです。',
    ],
    '沈黙対応' => [
      'coach_now' => '沈黙をすぐ埋めず、2秒待つ意識を持ってみよう。',
      'ok' => '焦らず様子を見るだけで、落ち着いた接客に見えます。',
      'ng' => '黙らないで、は緊張を強めやすいです。',
    ],
    '沈黙フォロー' => [
      'coach_now' => '会話が止まったら、小さい話題を一つ投げてみよう。',
      'ok' => 'その場のものや直前の話題から拾うと自然です。',
      'ng' => 'もっと盛り上げて、は負担が大きいです。',
    ],
    '締めの所作' => [
      'coach_now' => '帰り際に、その人に合う一言を残してみよう。',
      'ok' => '安心・印象・次回導線のどれを残すか選ぶとやりやすいです。',
      'ng' => '最後もっと頑張って、はぼやけやすいです。',
    ],
    '次回導線' => [
      'coach_now' => 'また話したい、を自然に一度だけ伝えてみよう。',
      'ok' => '会話の続きに触れると重くならずに残ります。',
      'ng' => 'もっと指名を取って、はプレッシャーになりやすいです。',
    ],
    'テーマ別メイク' => [
      'coach_now' => '今日は接客テーマに合わせて見た目を一つだけ調整してみよう。',
      'ok' => '目元、柔らかさ、ツヤ感のどれか一つに絞るとやりやすいです。',
      'ng' => 'もっと可愛くして、は方向性が曖昧です。',
    ],
  ];
}

function get_cast_manager_coaching(int $castId, ?array $latestQuizResult, array $weakTags, array $growthTheme, ?array $latestTraining, array $currentMission): array {
  $type = (array)($latestQuizResult['result_type'] ?? []);
  $typeKey = (string)($latestQuizResult['result_type_key'] ?? 'all_rounder');
  $weakSkillTags = array_slice(array_keys($weakTags), 0, 2);
  $achievementRate = (int)($growthTheme['mission_achievement_rate'] ?? 0);
  if ($achievementRate <= 0 && isset($growthTheme['_mission_stats']['achievement_rate'])) {
    $achievementRate = (int)$growthTheme['_mission_stats']['achievement_rate'];
  }
  $coachingAuto = service_coaching_build($typeKey, $weakSkillTags, $achievementRate, count($weakTags));

  $managerSummary = '';
  if ($type) {
    $managerSummary = (string)($type['summary'] ?? '');
  }
  if ($managerSummary === '' && !empty($growthTheme['growth_message'])) {
    $managerSummary = (string)$growthTheme['growth_message'];
  }

  $coachNow = (string)($coachingAuto['message'] ?? '');
  if ($coachNow === '' && !empty($type['today_tip'])) {
    $coachNow = (string)$type['today_tip'];
  }
  if ($coachNow === '' && !empty($currentMission['action_text'])) {
    $coachNow = '今日は「' . (string)$currentMission['action_text'] . '」を一回だけ意識してみよう。';
  }

  $howTo = array_values(array_map('strval', (array)($coachingAuto['how_to'] ?? [])));
  $examples = array_values(array_map('strval', (array)($coachingAuto['examples'] ?? [])));
  $ng = array_values(array_map('strval', (array)($coachingAuto['ng'] ?? [])));

  $okLine = $howTo[0] ?? '今日は一つだけ意識してみよう、と小さく区切ると伝わりやすいです。';
  $ngLine = $ng[0] ?? 'もっとちゃんとして、のような広すぎる伝え方は避けた方が良いです。';

  $leverage = [];
  foreach (array_slice((array)($type['strengths'] ?? []), 0, 2) as $item) {
    $leverage[] = (string)$item;
  }
  foreach (array_slice((array)($latestTraining['strong_points'] ?? []), 0, 2) as $item) {
    $leverage[] = (string)$item;
  }
  $leverage = array_values(array_unique(array_filter(array_map('trim', $leverage), static fn(string $v): bool => $v !== '')));

  $seatMatches = array_values(array_map('strval', array_slice((array)($type['matches'] ?? []), 0, 3)));

  return [
    'manager_summary' => $managerSummary !== '' ? $managerSummary : '今は強みを残しつつ、所作を一つずつ整えていく段階です。',
    'coach_now' => $coachNow !== '' ? $coachNow : '今日は一つだけ丁寧さを足す声かけが合いやすいです。',
    'ok_line' => $okLine,
    'ng_line' => $ngLine,
    'growth_style' => $examples[0] ?? '一度に多くを求めず、1席で1回できる行動に分けて伝えると伸びやすいタイプです。',
    'how_to' => $howTo,
    'examples' => $examples,
    'ng' => $ng,
    'coaching_state' => (string)($coachingAuto['state'] ?? 'normal'),
    'leverage_points' => $leverage,
    'seat_matches' => $seatMatches,
  ];
}

function cast_growth_status_sort_weight(string $statusLabel): int {
  return match ($statusLabel) {
    '要フォロー' => 0,
    '伸び中' => 1,
    '好調' => 2,
    default => 3,
  };
}

function cast_growth_notes_table_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cast_growth_manager_notes'
    ");
    $st->execute();
    $ready = ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    $ready = false;
  }

  return $ready;
}

function cast_growth_fetch_note(PDO $pdo, int $storeId, int $castId): ?array {
  if (!cast_growth_notes_table_ready($pdo) || $storeId <= 0 || $castId <= 0) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT *
    FROM cast_growth_manager_notes
    WHERE store_id = ?
      AND cast_id = ?
    LIMIT 1
  ");
  $st->execute([$storeId, $castId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function cast_growth_save_note(PDO $pdo, int $storeId, int $castId, int $managerUserId, string $note): void {
  if (!cast_growth_notes_table_ready($pdo) || $storeId <= 0 || $castId <= 0 || $managerUserId <= 0) {
    return;
  }

  $note = trim($note);
  if (mb_strlen($note) > 1000) {
    $note = mb_substr($note, 0, 1000);
  }

  $st = $pdo->prepare("
    INSERT INTO cast_growth_manager_notes (
      store_id, cast_id, manager_user_id, note
    ) VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      manager_user_id = VALUES(manager_user_id),
      note = VALUES(note),
      updated_at = CURRENT_TIMESTAMP
  ");
  $st->execute([$storeId, $castId, $managerUserId, $note]);
}

function cast_growth_mvp_candidate(array $snapshots): ?array {
  $best = null;

  foreach ($snapshots as $snapshot) {
    if (!is_array($snapshot)) {
      continue;
    }

    $statusLabel = (string)($snapshot['status']['label'] ?? '');
    $achievement = (int)($snapshot['mission_stats']['achievement_rate'] ?? 0);
    $streak = (int)($snapshot['mission_stats']['streak_days'] ?? 0);
    $badgeCount = count((array)($snapshot['badges']['earned'] ?? []));
    $weakPenalty = (int)(array_values((array)($snapshot['weak_tags']))[0] ?? 0);

    $score = 0;
    if ($statusLabel === '好調') {
      $score += 40;
    } elseif ($statusLabel === '伸び中') {
      $score += 20;
    }
    $score += $achievement;
    $score += min(14, $streak * 2);
    $score += min(10, $badgeCount * 2);
    $score -= min(12, $weakPenalty * 2);

    $candidate = ['score' => $score, 'snapshot' => $snapshot];
    if ($best === null || $candidate['score'] > $best['score']) {
      $best = $candidate;
    }
  }

  return is_array($best['snapshot'] ?? null) ? $best['snapshot'] : null;
}
