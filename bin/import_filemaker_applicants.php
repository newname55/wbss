<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
require_once $root . '/app/repo_applicants.php';
require_once $root . '/app/service_applicants.php';

if (!function_exists('db')) {
  fwrite(STDERR, "db() not found\n");
  exit(1);
}

function fm_log(string $message): void {
  fwrite(STDOUT, '[' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s') . "] {$message}\n");
}

function fm_arg(array $argv, string $name, ?string $default = null): ?string {
  $prefix = $name . '=';
  foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, $prefix)) {
      return substr((string)$arg, strlen($prefix));
    }
  }
  return $default;
}

function fm_normalize_header(string $value): string {
  $value = trim($value);
  $value = mb_convert_kana($value, 'asKV', 'UTF-8');
  $value = mb_strtolower($value, 'UTF-8');
  $value = preg_replace('/[\s　]+/u', '', $value) ?? $value;
  return $value;
}

function fm_header_aliases(): array {
  return [
    'legacy_record_no' => ['legacy_record_no', 'record_no', 'レコード番号', 'fmrecordid', 'レコードid', 'id'],
    'person_code' => ['person_code', '応募者コード', '応募者id', 'applicant_code'],
    'last_name' => ['last_name', '姓', '苗字', '氏'],
    'first_name' => ['first_name', '名'],
    'last_name_kana' => ['last_name_kana', '姓かな', '姓カナ', '氏ふりがな'],
    'first_name_kana' => ['first_name_kana', '名かな', '名カナ', '名ふりがな'],
    'birth_date_text' => ['birth_date', 'birth_date_text', '生年月日'],
    'phone' => ['phone', '電話番号', 'tel', '携帯番号', '携帯電話'],
    'postal_code' => ['postal_code', '郵便番号'],
    'current_address' => ['current_address', '現住所', '住所'],
    'previous_address' => ['previous_address', '以前住所', '前住所'],
    'blood_type' => ['blood_type', '血液型'],
    'body_height_cm' => ['body_height_cm', 'height', '身長'],
    'body_weight_kg' => ['body_weight_kg', 'weight', '体重'],
    'bust_cm' => ['bust_cm', 'bust', 'バスト'],
    'waist_cm' => ['waist_cm', 'waist', 'ウエスト'],
    'hip_cm' => ['hip_cm', 'hip', 'ヒップ'],
    'cup_size' => ['cup_size', 'cup', 'カップ'],
    'shoe_size' => ['shoe_size', 'shoe', '靴サイズ'],
    'clothing_top_size' => ['clothing_top_size', '服上', '上服サイズ'],
    'clothing_bottom_size' => ['clothing_bottom_size', '服下', '下服サイズ'],
    'photo_original_path' => ['photo_original_path', '顔写真パス', '写真パス', 'photo_path'],
    'photo_file_name' => ['photo_file_name', '顔写真ファイル名', 'photo_file_name'],
    'photo_public_url' => ['photo_public_url', '顔写真url', '写真url', 'photo_url'],
    'interview_date_text' => ['interview_date', 'interview_date_text', '面接日'],
    'interview_time_text' => ['interview_time', 'interview_time_text', '面接時刻'],
    'interview_store_code' => ['interview_store_code', '面接店舗コード', '店番'],
    'interview_store_name' => ['interview_store_name', '面接店舗', '面接場所', '勤務店舗名', '店名'],
    'interviewer_login_id' => ['interviewer_login_id', '面接担当者ログインid'],
    'interviewer_name' => ['interviewer_name', '面接担当者', '担当者'],
    'interview_result' => ['interview_result', '面接結果', '面接結果状態'],
    'application_route' => ['application_route', '応募経路'],
    'recruitment_slot_note' => ['recruitment_slot_note', '採用枠メモ'],
    'interview_notes' => ['interview_notes', '面接メモ'],
    'previous_job' => ['previous_job', '前職'],
    'desired_hourly_wage' => ['desired_hourly_wage', '希望時給'],
    'desired_daily_wage' => ['desired_daily_wage', '希望日給'],
    'preferred_store_code' => ['preferred_store_code', '希望店舗コード'],
    'preferred_store_name' => ['preferred_store_name', '希望店舗'],
    'trial_status' => ['trial_status', '体験入店状態'],
    'trial_date_text' => ['trial_date', 'trial_date_text', '体験入店日'],
    'trial_feedback' => ['trial_feedback', '体験入店フィードバック'],
    'join_decision' => ['join_decision', '入店判定'],
    'join_date_text' => ['join_date', 'join_date_text', '入店日'],
    'next_action_note' => ['next_action_note', '次アクション'],
    'genji_name' => ['genji_name', '源氏名'],
    'current_status' => ['current_status', '在籍状態', '現在状態'],
    'current_store_code' => ['current_store_code', '現在店舗コード', '店番'],
    'current_store_name' => ['current_store_name', '現在店舗', '勤務店舗名', '店名'],
    'joined_at_text' => ['joined_at_text', '入店日付', '作成日'],
    'moved_at_text' => ['moved_at_text', '移動日'],
    'left_at_text' => ['left_at_text', '退店日', '退職日'],
    'move_reason' => ['move_reason', '移動理由'],
    'leave_reason' => ['leave_reason', '退店理由'],
    'appearance_score' => ['appearance_score', '見た目点'],
    'communication_score' => ['communication_score', '会話力点'],
    'motivation_score' => ['motivation_score', '意欲点'],
    'cleanliness_score' => ['cleanliness_score', '清潔感点'],
    'sales_potential_score' => ['sales_potential_score', '営業力点'],
    'retention_potential_score' => ['retention_potential_score', '定着見込点'],
    'score_comment' => ['score_comment', '評価コメント'],
    'notes' => ['notes', '備考', 'メモ', '前職期間', '前職給与日給', 'レギュラー・バイト', '満年齢', '本数1日', '服のサイズ'],
  ];
}

function fm_default_filemaker_export_headers(bool $withPhoto = true): array {
  return [
    'ID',
    'レギュラー・バイト',
    '千支',
    '勤務店舗名',
    '携帯電話',
    '現住所それ以降の住所',
    '現住所郡市区',
    '現住所県',
    '最終更新日',
    '作成日',
    '氏',
    '氏ふりがな',
    '自宅電話',
    ...($withPhoto ? ['写真'] : []),
    '生年月日月',
    '生年月日日',
    '生年月日年号',
    '生年月日年号２',
    '前職',
    '前職期間',
    '前職給与日給',
    '退職日',
    '担当者',
    '店番',
    '店名',
    '年齢',
    '服のサイズ',
    '本数1日',
    '満年齢',
    '名',
    '名ふりがな',
    '面接結果状態',
    '面接場所',
    '面接日',
  ];
}

function fm_build_header_map(array $headers): array {
  $normalizedHeaders = [];
  foreach ($headers as $index => $header) {
    $normalizedHeaders[$index] = fm_normalize_header((string)$header);
  }

  $map = [];
  foreach (fm_header_aliases() as $canonical => $aliases) {
    foreach ($aliases as $alias) {
      $normalizedAlias = fm_normalize_header((string)$alias);
      $index = array_search($normalizedAlias, $normalizedHeaders, true);
      if ($index !== false) {
        $map[$canonical] = (int)$index;
        break;
      }
    }
  }
  return $map;
}

function fm_headers_look_like_data(array $headers): bool {
  $nonEmpty = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), $headers), static fn(string $v): bool => $v !== ''));
  if ($nonEmpty === []) {
    return true;
  }

  $hits = 0;
  foreach (array_keys(fm_header_aliases()) as $canonical) {
    foreach ($nonEmpty as $header) {
      $normalized = fm_normalize_header($header);
      foreach (fm_header_aliases()[$canonical] as $alias) {
        if ($normalized === fm_normalize_header((string)$alias)) {
          $hits++;
          break 2;
        }
      }
    }
  }

  return $hits < 2;
}

function fm_detect_delimiter(string $path): string {
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  if (in_array($ext, ['tab', 'tsv'], true)) {
    return "\t";
  }

  $fp = fopen($path, 'rb');
  if (!$fp) {
    throw new RuntimeException("csv open failed: {$path}");
  }
  $line = (string)fgets($fp);
  fclose($fp);

  return substr_count($line, "\t") > substr_count($line, ',') ? "\t" : ',';
}

function fm_csv_rows(string $path): array {
  $delimiter = fm_detect_delimiter($path);
  $rawContent = file_get_contents($path);
  if ($rawContent === false) {
    throw new RuntimeException("csv open failed: {$path}");
  }

  // FileMaker export may use classic Mac CR line endings.
  $normalizedContent = str_replace(["\r\n", "\r"], ["\n", "\n"], $rawContent);

  $fp = fopen('php://temp', 'r+b');
  if (!$fp) {
    throw new RuntimeException('temp stream open failed');
  }
  fwrite($fp, $normalizedContent);
  rewind($fp);

  $rows = [];
  $headers = null;
  while (($cols = fgetcsv($fp, 0, $delimiter)) !== false) {
    if ($headers === null) {
      $headers = $cols;
      continue;
    }
    $rows[] = $cols;
  }
  fclose($fp);

  if ($headers === null) {
    throw new RuntimeException('csv header not found');
  }

  if (fm_headers_look_like_data($headers)) {
    array_unshift($rows, $headers);
    $headers = fm_default_filemaker_export_headers(count($headers) >= 34);
  }

  return [$headers, $rows];
}

function fm_safe_row_json(array $headers, array $cols): string {
  $size = max(count($headers), count($cols));
  $headerPadded = array_pad($headers, $size, '');
  $colsPadded = array_pad($cols, $size, null);
  $assoc = [];
  foreach ($headerPadded as $index => $header) {
    $key = trim((string)$header);
    if ($key === '') {
      $key = '__extra_' . $index;
    }
    $assoc[$key] = $colsPadded[$index] ?? null;
  }
  return json_encode($assoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function fm_parse_date(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  $formats = ['Y-m-d', 'Y/m/d', 'Y.m.d', 'Y-m-d H:i:s', 'Y/m/d H:i:s', 'm/d/Y', 'm/d/Y H:i:s'];
  foreach ($formats as $format) {
    $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Asia/Tokyo'));
    if ($dt instanceof DateTimeImmutable) {
      $normalized = $dt->format('Y-m-d');
      $year = (int)$dt->format('Y');
      if ($year < 1900 || $year > 2100) {
        return null;
      }
      return $normalized;
    }
  }
  try {
    $dt = new DateTimeImmutable($value, new DateTimeZone('Asia/Tokyo'));
    $year = (int)$dt->format('Y');
    if ($year < 1900 || $year > 2100) {
      return null;
    }
    return $dt->format('Y-m-d');
  } catch (Throwable $e) {
    return null;
  }
}

function fm_parse_time(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  $formats = ['H:i', 'H:i:s', 'G:i'];
  foreach ($formats as $format) {
    $dt = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Asia/Tokyo'));
    if ($dt instanceof DateTimeImmutable) {
      return $dt->format('H:i:s');
    }
  }
  return null;
}

function fm_compose_birth_date(array $raw): ?string {
  $single = fm_parse_date($raw['birth_date_text'] ?? null);
  if ($single !== null) {
    return $single;
  }

  $era = trim((string)($raw['生年月日年号'] ?? ''));
  $yearValue = trim((string)($raw['生年月日年号2'] ?? ''));
  $month = trim((string)($raw['生年月日月'] ?? ''));
  $day = trim((string)($raw['生年月日日'] ?? ''));

  if ($yearValue === '' && $era !== '' && preg_match('/^\d{4}$/', $era)) {
    $yearValue = $era;
    $era = '';
  }

  if ($yearValue !== '' && $month !== '' && $day !== '') {
    $year = fm_compose_birth_year($era, $yearValue);
    if ($year === null) {
      return null;
    }
    $candidate = sprintf('%04d-%02d-%02d', $year, (int)$month, (int)$day);
    return fm_parse_date($candidate);
  }
  return null;
}

function fm_compose_birth_year(string $era, string $yearValue): ?int {
  $yearValue = trim($yearValue);
  if (!preg_match('/^\d+$/', $yearValue)) {
    return null;
  }

  $year = (int)$yearValue;
  if ($year >= 1900 && $year <= 2100) {
    return $year;
  }
  if ($year <= 0) {
    return null;
  }

  $era = trim($era);
  $eraMap = [
    '令和' => 2018,
    '平成' => 1988,
    '昭和' => 1925,
    '大正' => 1911,
    '明治' => 1867,
  ];

  if (isset($eraMap[$era])) {
    $gregorian = $eraMap[$era] + $year;
    return ($gregorian >= 1900 && $gregorian <= 2100) ? $gregorian : null;
  }

  return null;
}

function fm_compose_current_address(array $raw): ?string {
  $single = trim((string)($raw['current_address'] ?? ''));
  if ($single !== '') {
    return $single;
  }

  $parts = [
    trim((string)($raw['現住所県'] ?? '')),
    trim((string)($raw['現住所郡市区'] ?? '')),
    trim((string)($raw['現住所それ以降の住所'] ?? '')),
  ];
  $parts = array_values(array_filter($parts, static fn(string $v): bool => $v !== ''));
  return $parts === [] ? null : implode('', $parts);
}

function fm_parse_decimal(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  $value = str_replace([',', '円', '¥', '￥', ' '], '', $value);
  return is_numeric($value) ? (string)$value : null;
}

function fm_parse_score(?string $value): ?int {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }
  return is_numeric($value) ? (int)$value : null;
}

function fm_norm_interview_result(?string $value): string {
  $value = trim((string)$value);
  return match ($value) {
    '合格', '採用', 'pass' => 'pass',
    '保留', 'hold' => 'hold',
    '不採用', '見送り', 'reject' => 'reject',
    '入店', 'joined' => 'joined',
    '体入のみ' => 'hold',
    '面接のみ' => 'pending',
    '退職' => 'reject',
    default => 'pending',
  };
}

function fm_norm_trial_status(?string $value): string {
  $value = trim((string)$value);
  return match ($value) {
    '予定', 'scheduled' => 'scheduled',
    '実施', 'completed' => 'completed',
    '合格', 'passed' => 'passed',
    '見送り', 'failed' => 'failed',
    '取消', 'cancelled' => 'cancelled',
    default => 'not_set',
  };
}

function fm_norm_join_decision(?string $value): string {
  $value = trim((string)$value);
  return match ($value) {
    '承認', 'approved', '入店' => 'approved',
    '不採用', 'rejected' => 'rejected',
    '保留', 'deferred' => 'deferred',
    default => 'undecided',
  };
}

function fm_infer_person_status(array $raw, ?int $currentStoreId, ?string $leftDate): string {
  $currentStatus = trim((string)($raw['current_status'] ?? ''));
  if ($currentStatus !== '') {
    return match ($currentStatus) {
      '在籍中', '在籍', 'active' => 'active',
      '体験入店', 'trial' => 'trial',
      '退店', '退職', 'left' => 'left',
      '保留', 'hold' => 'hold',
      default => 'interviewing',
    };
  }

  $resultLabel = trim((string)($raw['interview_result'] ?? ''));
  if (in_array($resultLabel, ['退職', '退店'], true) || $leftDate !== null) {
    return 'left';
  }
  if ($resultLabel === '体入のみ') {
    return 'trial';
  }
  if ($currentStoreId !== null) {
    return 'active';
  }
  return 'interviewing';
}

function fm_find_store_id(PDO $pdo, ?string $code, ?string $name): ?int {
  $code = trim((string)$code);
  if ($code !== '') {
    $st = $pdo->prepare("SELECT id FROM stores WHERE code = ? LIMIT 1");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    if ($id !== false) {
      return (int)$id;
    }
  }

  $name = trim((string)$name);
  if ($name !== '') {
    $st = $pdo->prepare("SELECT id FROM stores WHERE name = ? LIMIT 1");
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id !== false) {
      return (int)$id;
    }

    $normalized = fm_normalize_store_label($name);
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($stores as $store) {
      $storeName = fm_normalize_store_label((string)($store['name'] ?? ''));
      if ($storeName === '') {
        continue;
      }
      if ($storeName === $normalized || str_contains($normalized, $storeName) || str_contains($storeName, $normalized)) {
        return (int)$store['id'];
      }
    }
  }

  return null;
}

function fm_normalize_store_label(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }
  $value = str_replace(['　', ' '], '', $value);
  $value = preg_replace('/[()（）]/u', '', $value) ?? $value;
  return $value;
}

function fm_find_user_id(PDO $pdo, ?string $loginId, ?string $displayName): ?int {
  $loginId = trim((string)$loginId);
  if ($loginId !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE login_id = ? LIMIT 1");
    $st->execute([$loginId]);
    $id = $st->fetchColumn();
    if ($id !== false) {
      return (int)$id;
    }
  }

  $displayName = trim((string)$displayName);
  if ($displayName !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE display_name = ? LIMIT 1");
    $st->execute([$displayName]);
    $id = $st->fetchColumn();
    if ($id !== false) {
      return (int)$id;
    }
  }

  return null;
}

function fm_find_person_by_legacy(PDO $pdo, string $legacyRecordNo): ?array {
  $st = $pdo->prepare("
    SELECT *
    FROM wbss_applicant_persons
    WHERE legacy_source = 'filemaker'
      AND legacy_record_no = ?
    LIMIT 1
  ");
  $st->execute([$legacyRecordNo]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fm_find_interview_existing(PDO $pdo, int $personId, string $interviewDate, ?int $storeId): ?int {
  $sql = "
    SELECT id
    FROM wbss_applicant_interviews
    WHERE person_id = ?
      AND interview_date = ?
  ";
  $params = [$personId, $interviewDate];
  if ($storeId !== null) {
    $sql .= " AND interview_store_id = ?";
    $params[] = $storeId;
  }
  $sql .= " ORDER BY id DESC LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $id = $st->fetchColumn();
  return $id === false ? null : (int)$id;
}

function fm_find_photo_existing(PDO $pdo, int $personId, string $path): ?int {
  $st = $pdo->prepare("SELECT id FROM wbss_applicant_photos WHERE person_id = ? AND file_path = ? LIMIT 1");
  $st->execute([$personId, $path]);
  $id = $st->fetchColumn();
  return $id === false ? null : (int)$id;
}

function fm_mark_raw(PDO $pdo, int $rawId, string $status, ?string $error = null): void {
  $st = $pdo->prepare("
    UPDATE fm_applicant_import_raw
    SET process_status = ?,
        processed_at = NOW(),
        error_message = ?
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$status, $error, $rawId]);
}

function fm_insert_raw(PDO $pdo, array $row): void {
  $columns = array_keys($row);
  $sql = "INSERT INTO fm_applicant_import_raw (" . implode(',', $columns) . ") VALUES (:" . implode(', :', $columns) . ")";
  $st = $pdo->prepare($sql);
  $st->execute($row);
}

function fm_raw_table_columns(): array {
  return [
    'batch_key',
    'source_file',
    'row_no',
    'process_status',
    'processed_at',
    'error_message',
    'legacy_record_no',
    'person_code',
    'last_name',
    'first_name',
    'last_name_kana',
    'first_name_kana',
    'birth_date_text',
    'phone',
    'postal_code',
    'current_address',
    'previous_address',
    'blood_type',
    'body_height_cm',
    'body_weight_kg',
    'bust_cm',
    'waist_cm',
    'hip_cm',
    'cup_size',
    'shoe_size',
    'clothing_top_size',
    'clothing_bottom_size',
    'photo_original_path',
    'photo_file_name',
    'photo_public_url',
    'interview_date_text',
    'interview_time_text',
    'interview_store_code',
    'interview_store_name',
    'interviewer_login_id',
    'interviewer_name',
    'interview_result',
    'application_route',
    'recruitment_slot_note',
    'interview_notes',
    'previous_job',
    'desired_hourly_wage',
    'desired_daily_wage',
    'preferred_store_code',
    'preferred_store_name',
    'trial_status',
    'trial_date_text',
    'trial_feedback',
    'join_decision',
    'join_date_text',
    'next_action_note',
    'genji_name',
    'current_status',
    'current_store_code',
    'current_store_name',
    'joined_at_text',
    'moved_at_text',
    'left_at_text',
    'move_reason',
    'leave_reason',
    'appearance_score',
    'communication_score',
    'motivation_score',
    'cleanliness_score',
    'sales_potential_score',
    'retention_potential_score',
    'score_comment',
    'notes',
    'raw_json',
  ];
}

function fm_raw_varchar_limits(): array {
  return [
    'batch_key' => 64,
    'source_file' => 255,
    'legacy_record_no' => 100,
    'person_code' => 40,
    'last_name' => 60,
    'first_name' => 60,
    'last_name_kana' => 60,
    'first_name_kana' => 60,
    'birth_date_text' => 40,
    'phone' => 30,
    'postal_code' => 20,
    'current_address' => 255,
    'previous_address' => 255,
    'blood_type' => 10,
    'body_height_cm' => 20,
    'body_weight_kg' => 20,
    'bust_cm' => 20,
    'waist_cm' => 20,
    'hip_cm' => 20,
    'cup_size' => 10,
    'shoe_size' => 20,
    'clothing_top_size' => 20,
    'clothing_bottom_size' => 20,
    'photo_original_path' => 255,
    'photo_file_name' => 255,
    'photo_public_url' => 255,
    'interview_date_text' => 40,
    'interview_time_text' => 20,
    'interview_store_code' => 30,
    'interview_store_name' => 100,
    'interviewer_login_id' => 50,
    'interviewer_name' => 100,
    'interview_result' => 30,
    'application_route' => 100,
    'recruitment_slot_note' => 255,
    'previous_job' => 120,
    'desired_hourly_wage' => 30,
    'desired_daily_wage' => 30,
    'preferred_store_code' => 30,
    'preferred_store_name' => 100,
    'trial_status' => 30,
    'trial_date_text' => 40,
    'join_decision' => 30,
    'join_date_text' => 40,
    'next_action_note' => 255,
    'genji_name' => 80,
    'current_status' => 30,
    'current_store_code' => 30,
    'current_store_name' => 100,
    'joined_at_text' => 40,
    'moved_at_text' => 40,
    'left_at_text' => 40,
    'move_reason' => 255,
    'leave_reason' => 255,
    'appearance_score' => 10,
    'communication_score' => 10,
    'motivation_score' => 10,
    'cleanliness_score' => 10,
    'sales_potential_score' => 10,
    'retention_potential_score' => 10,
  ];
}

function fm_trim_raw_row(array $row): array {
  $limits = fm_raw_varchar_limits();
  foreach ($limits as $column => $limit) {
    if (!isset($row[$column]) || $row[$column] === null) {
      continue;
    }
    $value = (string)$row[$column];
    if (mb_strlen($value, 'UTF-8') > $limit) {
      $row[$column] = mb_substr($value, 0, $limit, 'UTF-8');
    }
  }
  return $row;
}

function fm_run_raw_import(PDO $pdo, string $path, string $batchKey): void {
  [$headers, $rows] = fm_csv_rows($path);
  $headerMap = fm_build_header_map($headers);
  if (!isset($headerMap['legacy_record_no'])) {
    throw new RuntimeException('CSV に レコード番号 / legacy_record_no がありません');
  }

  $inserted = 0;
  $pdo->beginTransaction();
  try {
    foreach ($rows as $index => $cols) {
      $raw = [
        'batch_key' => $batchKey,
        'source_file' => basename($path),
        'row_no' => $index + 2,
        'process_status' => 'pending',
        'legacy_record_no' => '',
      ];

      foreach (array_keys(fm_header_aliases()) as $canonical) {
        $raw[$canonical] = null;
      }

      foreach ($headerMap as $canonical => $colIndex) {
        $raw[$canonical] = isset($cols[$colIndex]) ? trim((string)$cols[$colIndex]) : null;
      }

      foreach ($headers as $headerIndex => $headerName) {
        $headerKey = trim((string)$headerName);
        if ($headerKey !== '' && !array_key_exists($headerKey, $raw)) {
          $raw[$headerKey] = isset($cols[$headerIndex]) ? trim((string)$cols[$headerIndex]) : null;
        }
      }

      $composedBirthDate = fm_compose_birth_date($raw);
      if ($composedBirthDate !== null) {
        $raw['birth_date_text'] = $composedBirthDate;
      }

      $composedAddress = fm_compose_current_address($raw);
      if ($composedAddress !== null) {
        $raw['current_address'] = $composedAddress;
      }

      $extraNotes = [];
      foreach (['レギュラー・バイト', '前職期間', '前職給与日給', '満年齢', '本数1日', '服のサイズ', '千支', '自宅電話'] as $extraKey) {
        $extraValue = trim((string)($raw[$extraKey] ?? ''));
        if ($extraValue !== '') {
          $extraNotes[] = $extraKey . ': ' . $extraValue;
        }
      }
      if ($extraNotes !== []) {
        $existingNotes = trim((string)($raw['notes'] ?? ''));
        $raw['notes'] = $existingNotes !== '' ? ($existingNotes . "\n" . implode("\n", $extraNotes)) : implode("\n", $extraNotes);
      }

      $raw['legacy_record_no'] = trim((string)($raw['legacy_record_no'] ?? ''));
      if ($raw['legacy_record_no'] === '') {
        $raw['process_status'] = 'skipped';
        $raw['error_message'] = 'legacy_record_no が空のためスキップ';
        $raw['legacy_record_no'] = 'row-' . ($index + 2);
      }

      $raw['raw_json'] = fm_safe_row_json($headers, $cols);
      $insertRow = [];
      foreach (fm_raw_table_columns() as $column) {
        $insertRow[$column] = $raw[$column] ?? null;
      }
      $insertRow = fm_trim_raw_row($insertRow);
      fm_insert_raw($pdo, $insertRow);
      $inserted++;
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  fm_log("raw import done batch={$batchKey} rows={$inserted}");
}

function fm_transform_row(PDO $pdo, array $raw): void {
  $legacyRecordNo = trim((string)$raw['legacy_record_no']);
  if ($legacyRecordNo === '') {
    throw new RuntimeException('legacy_record_no empty');
  }

  $storeId = fm_find_store_id($pdo, $raw['interview_store_code'] ?? null, $raw['interview_store_name'] ?? null);
  $preferredStoreId = fm_find_store_id($pdo, $raw['preferred_store_code'] ?? null, $raw['preferred_store_name'] ?? null);
  $currentStoreId = fm_find_store_id($pdo, $raw['current_store_code'] ?? null, $raw['current_store_name'] ?? null);
  $interviewerUserId = fm_find_user_id($pdo, $raw['interviewer_login_id'] ?? null, $raw['interviewer_name'] ?? null);
  $leftDate = fm_parse_date($raw['left_at_text'] ?? null);
  $inferredStatus = fm_infer_person_status($raw, $currentStoreId, $leftDate);

  $personPayload = service_applicants_person_payload([
    'legacy_source' => 'filemaker',
    'legacy_record_no' => $legacyRecordNo,
    'person_code' => $raw['person_code'] ?? null,
    'last_name' => $raw['last_name'] ?? '',
    'first_name' => $raw['first_name'] ?? '',
    'last_name_kana' => $raw['last_name_kana'] ?? null,
    'first_name_kana' => $raw['first_name_kana'] ?? null,
    'birth_date' => fm_parse_date($raw['birth_date_text'] ?? null),
    'phone' => $raw['phone'] ?? null,
    'postal_code' => $raw['postal_code'] ?? null,
    'current_address' => $raw['current_address'] ?? null,
    'previous_address' => $raw['previous_address'] ?? null,
    'blood_type' => $raw['blood_type'] ?? null,
    'body_height_cm' => fm_parse_score($raw['body_height_cm'] ?? null),
    'body_weight_kg' => fm_parse_decimal($raw['body_weight_kg'] ?? null),
    'bust_cm' => fm_parse_score($raw['bust_cm'] ?? null),
    'waist_cm' => fm_parse_score($raw['waist_cm'] ?? null),
    'hip_cm' => fm_parse_score($raw['hip_cm'] ?? null),
    'cup_size' => $raw['cup_size'] ?? null,
    'shoe_size' => fm_parse_decimal($raw['shoe_size'] ?? null),
    'clothing_top_size' => $raw['clothing_top_size'] ?? null,
    'clothing_bottom_size' => $raw['clothing_bottom_size'] ?? null,
    'previous_job' => $raw['previous_job'] ?? null,
    'desired_hourly_wage' => fm_parse_decimal($raw['desired_hourly_wage'] ?? null),
    'desired_daily_wage' => fm_parse_decimal($raw['desired_daily_wage'] ?? null),
    'notes' => $raw['notes'] ?? null,
  ], 0);
  service_applicants_assert_person_payload($personPayload);

  $person = fm_find_person_by_legacy($pdo, $legacyRecordNo);
  if ($person) {
    $personId = (int)$person['id'];
    repo_applicants_update_person($pdo, $personId, array_merge($personPayload, ['updated_by_user_id' => null]));
  } else {
    $personId = repo_applicants_insert_person($pdo, $personPayload);
    service_applicants_log($pdo, [
      'person_id' => $personId,
      'action_type' => 'person_created',
      'action_note' => 'FileMaker 取込で人物作成',
    ]);
  }

  $photoPath = trim((string)($raw['photo_public_url'] ?: $raw['photo_original_path'] ?: ''));
  if ($photoPath !== '' && fm_find_photo_existing($pdo, $personId, $photoPath) === null) {
    repo_applicants_set_all_photos_non_primary($pdo, $personId);
    repo_applicants_insert_photo($pdo, [
      'person_id' => $personId,
      'file_name' => trim((string)($raw['photo_file_name'] ?: basename($photoPath))),
      'file_path' => $photoPath,
      'thumb_path' => $photoPath,
      'mime_type' => null,
      'file_size' => null,
      'is_primary' => 1,
      'uploaded_by_user_id' => null,
    ]);
    service_applicants_log($pdo, [
      'person_id' => $personId,
      'action_type' => 'photo_uploaded',
      'action_note' => 'FileMaker 取込で顔写真登録',
      'payload_json' => ['path' => $photoPath],
    ]);
  }

  $interviewDate = fm_parse_date($raw['interview_date_text'] ?? null);
  $interviewId = null;
  if ($interviewDate !== null && $storeId !== null) {
    $interviewId = fm_find_interview_existing($pdo, $personId, $interviewDate, $storeId);
    if ($interviewId === null) {
      $interviewId = repo_applicants_insert_interview($pdo, [
        'person_id' => $personId,
        'interview_date' => $interviewDate,
        'interview_time' => fm_parse_time($raw['interview_time_text'] ?? null),
        'interview_store_id' => $storeId,
        'interviewer_user_id' => $interviewerUserId,
        'interview_result' => fm_norm_interview_result($raw['interview_result'] ?? null),
        'application_route' => service_applicants_nullable_string($raw['application_route'] ?? null),
        'recruitment_slot_note' => service_applicants_nullable_string($raw['recruitment_slot_note'] ?? null),
        'interview_notes' => service_applicants_nullable_string($raw['interview_notes'] ?? null),
        'previous_job' => service_applicants_nullable_string($raw['previous_job'] ?? null),
        'desired_hourly_wage' => fm_parse_decimal($raw['desired_hourly_wage'] ?? null),
        'desired_daily_wage' => fm_parse_decimal($raw['desired_daily_wage'] ?? null),
        'preferred_store_id' => $preferredStoreId,
        'trial_status' => fm_norm_trial_status($raw['trial_status'] ?? null),
        'trial_date' => fm_parse_date($raw['trial_date_text'] ?? null),
        'trial_feedback' => service_applicants_nullable_string($raw['trial_feedback'] ?? null),
        'join_decision' => fm_norm_join_decision($raw['join_decision'] ?? null),
        'join_date' => fm_parse_date($raw['join_date_text'] ?? null),
        'next_action_note' => service_applicants_nullable_string($raw['next_action_note'] ?? null),
        'created_by_user_id' => null,
        'updated_by_user_id' => null,
      ]);
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'interview_id' => $interviewId,
        'store_id' => $storeId,
        'action_type' => 'interview_created',
        'action_note' => 'FileMaker 取込で面接作成',
      ]);
    }

    repo_applicants_upsert_interview_score($pdo, (int)$interviewId, [
      'appearance_score' => fm_parse_score($raw['appearance_score'] ?? null),
      'communication_score' => fm_parse_score($raw['communication_score'] ?? null),
      'motivation_score' => fm_parse_score($raw['motivation_score'] ?? null),
      'cleanliness_score' => fm_parse_score($raw['cleanliness_score'] ?? null),
      'sales_potential_score' => fm_parse_score($raw['sales_potential_score'] ?? null),
      'retention_potential_score' => fm_parse_score($raw['retention_potential_score'] ?? null),
      'total_score' => null,
      'comment' => service_applicants_nullable_string($raw['score_comment'] ?? null),
    ]);
  }

  $joinDate = fm_parse_date($raw['join_date_text'] ?? null) ?: fm_parse_date($raw['joined_at_text'] ?? null);
  $currentAssignment = repo_applicants_find_current_assignment($pdo, $personId);

  if ($inferredStatus === 'active' && $currentStoreId !== null) {
    if (!$currentAssignment) {
      $assignmentId = repo_applicants_insert_assignment($pdo, [
        'person_id' => $personId,
        'store_id' => $currentStoreId,
        'source_interview_id' => $interviewId,
        'assignment_status' => 'active',
        'transition_type' => repo_applicants_count_assignments($pdo, $personId) > 0 ? 'rejoin' : 'join',
        'start_date' => $joinDate ?: ($interviewDate ?: date('Y-m-d')),
        'end_date' => null,
        'genji_name' => service_applicants_nullable_string($raw['genji_name'] ?? null),
        'move_reason' => service_applicants_nullable_string($raw['move_reason'] ?? null),
        'leave_reason' => null,
        'is_current' => 1,
        'created_by_user_id' => null,
        'updated_by_user_id' => null,
      ]);
      service_applicants_log($pdo, [
        'person_id' => $personId,
        'assignment_id' => $assignmentId,
        'store_id' => $currentStoreId,
        'action_type' => 'joined',
        'action_note' => 'FileMaker 取込で在籍作成',
      ]);
    }
  } elseif ($inferredStatus === 'left' && $currentAssignment && $leftDate !== null) {
    repo_applicants_close_assignment($pdo, (int)$currentAssignment['id'], [
      'assignment_status' => 'left',
      'end_date' => $leftDate,
      'move_reason' => null,
      'leave_reason' => service_applicants_nullable_string($raw['leave_reason'] ?? null),
      'updated_by_user_id' => null,
    ]);
    service_applicants_log($pdo, [
      'person_id' => $personId,
      'assignment_id' => (int)$currentAssignment['id'],
      'store_id' => (int)$currentAssignment['store_id'],
      'action_type' => 'left',
      'action_note' => 'FileMaker 取込で退店反映',
    ]);
  }

  service_applicants_refresh_summary($pdo, $personId, 0);
}

function fm_run_transform(PDO $pdo, string $batchKey, int $limit): void {
  $st = $pdo->prepare("
    SELECT *
    FROM fm_applicant_import_raw
    WHERE batch_key = ?
      AND process_status = 'pending'
    ORDER BY row_no ASC
    LIMIT {$limit}
  ");
  $st->execute([$batchKey]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  fm_log("transform target=" . count($rows) . " batch={$batchKey}");

  $done = 0;
  $error = 0;
  foreach ($rows as $raw) {
    $rawId = (int)$raw['id'];
    try {
      $pdo->beginTransaction();
      fm_transform_row($pdo, $raw);
      fm_mark_raw($pdo, $rawId, 'done', null);
      $pdo->commit();
      $done++;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      fm_mark_raw($pdo, $rawId, 'error', $e->getMessage());
      $error++;
      fm_log("row={$raw['row_no']} error=" . $e->getMessage());
    }
  }

  fm_log("transform done={$done} error={$error}");
}

$mode = fm_arg($argv, '--mode', 'raw');
$input = fm_arg($argv, '--in');
$batchKey = fm_arg($argv, '--batch_key', 'fm_' . date('Ymd_His'));
$limit = (int)(fm_arg($argv, '--limit', '500') ?? '500');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($mode === 'raw') {
  if (!is_string($input) || $input === '' || !is_file($input)) {
    fwrite(STDERR, "--in=csv_path is required for --mode=raw\n");
    exit(1);
  }
  fm_run_raw_import($pdo, $input, $batchKey);
  exit(0);
}

if ($mode === 'transform') {
  fm_run_transform($pdo, $batchKey, max(1, $limit));
  exit(0);
}

fwrite(STDERR, "unknown --mode={$mode}\n");
exit(1);
