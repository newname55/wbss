<?php
declare(strict_types=1);

require_once __DIR__ . '/service_coaching_templates.php';

function service_coaching_state(int $achievementRate, int $weakCount): string {
  if ($achievementRate >= 70 && $weakCount <= 1) {
    return 'good';
  }
  if ($achievementRate < 40 || $weakCount >= 3) {
    return 'need_follow';
  }
  return 'normal';
}

function service_coaching_default_payload(string $state, string $serviceType): array {
  $templates = service_coaching_templates();
  $typeBlock = (array)($templates[$serviceType] ?? $templates['all_rounder'] ?? []);
  $defaultTemplates = array_values((array)(($typeBlock['default']['templates'] ?? [])));
  $picked = $defaultTemplates[0] ?? service_coaching_tpl(
    '今日は一つだけ意識してみよう。',
    ['1席で1回に絞って伝える。'],
    ['「今日は最初の一言だけ意識してみよう」'],
    ['一度に複数の課題を渡す。']
  );

  if ($state === 'need_follow') {
    $picked['message'] = 'まずは一つだけで大丈夫。' . (string)($picked['message'] ?? '');
  } elseif ($state === 'good') {
    $picked['message'] = '強みはそのままで、' . ltrim((string)($picked['message'] ?? ''), '今日は');
  }

  return [
    'state' => $state,
    'template_key' => $serviceType . ':default:0',
    'message' => (string)($picked['message'] ?? ''),
    'how_to' => array_values(array_map('strval', (array)($picked['how_to'] ?? []))),
    'examples' => array_values(array_map('strval', (array)($picked['examples'] ?? []))),
    'ng' => array_values(array_map('strval', (array)($picked['ng'] ?? []))),
  ];
}

function service_coaching_pick_template(string $serviceType, array $weakSkillTags, array $recentTemplateKeys = []): ?array {
  $templates = service_coaching_templates();
  $typeBlock = (array)($templates[$serviceType] ?? $templates['all_rounder'] ?? []);

  foreach ($weakSkillTags as $rawTag) {
    $tag = service_coaching_normalize_skill_tag((string)$rawTag);
    if ($tag === '' || empty($typeBlock[$tag]['templates']) || !is_array($typeBlock[$tag]['templates'])) {
      continue;
    }

    $rows = array_values((array)$typeBlock[$tag]['templates']);
    if (!$rows) {
      continue;
    }

    $candidates = [];
    foreach ($rows as $index => $row) {
      if (!is_array($row)) {
        continue;
      }
      $templateKey = $serviceType . ':' . $tag . ':' . $index;
      $weight = in_array($templateKey, $recentTemplateKeys, true) ? 1 : 3;
      $candidates[] = [
        'template_key' => $templateKey,
        'weight' => $weight,
        'row' => $row,
      ];
    }

    if ($candidates) {
      $totalWeight = array_sum(array_column($candidates, 'weight'));
      $pick = random_int(1, max(1, $totalWeight));
      $running = 0;
      foreach ($candidates as $candidate) {
        $running += (int)$candidate['weight'];
        if ($pick <= $running) {
          return [
            'template_key' => (string)$candidate['template_key'],
            'weak_skill' => $tag,
            'template' => (array)$candidate['row'],
          ];
        }
      }
    }
  }

  $defaultRows = array_values((array)($typeBlock['default']['templates'] ?? []));
  if (!$defaultRows) {
    return null;
  }

  $index = array_rand($defaultRows);
  return [
    'template_key' => $serviceType . ':default:' . (int)$index,
    'weak_skill' => 'default',
    'template' => (array)$defaultRows[$index],
  ];
}

function service_coaching_build(string $serviceType, array $weakSkillTags, int $achievementRate, int $weakCount, array $recentTemplateKeys = []): array {
  $serviceType = trim($serviceType) !== '' ? trim($serviceType) : 'all_rounder';
  $state = service_coaching_state($achievementRate, $weakCount);
  $payload = service_coaching_default_payload($state, $serviceType);
  $picked = service_coaching_pick_template($serviceType, $weakSkillTags, $recentTemplateKeys);

  if ($picked) {
    $template = (array)($picked['template'] ?? []);
    $payload['template_key'] = (string)($picked['template_key'] ?? $payload['template_key']);
    $payload['message'] = (string)($template['message'] ?? $payload['message']);
    $payload['how_to'] = array_values(array_map('strval', (array)($template['how_to'] ?? $payload['how_to'])));
    $payload['examples'] = array_values(array_map('strval', (array)($template['examples'] ?? $payload['examples'])));
    $payload['ng'] = array_values(array_map('strval', (array)($template['ng'] ?? $payload['ng'])));
  }

  if ($state === 'need_follow') {
    array_unshift($payload['how_to'], 'まずは一つだけで大丈夫、と伝える。');
    $payload['how_to'] = array_values(array_slice(array_unique($payload['how_to']), 0, 3));
  }

  return [
    'message' => (string)$payload['message'],
    'how_to' => array_values((array)$payload['how_to']),
    'examples' => array_values((array)$payload['examples']),
    'ng' => array_values((array)$payload['ng']),
    'state' => $state,
    'template_key' => (string)($payload['template_key'] ?? ''),
  ];
}
