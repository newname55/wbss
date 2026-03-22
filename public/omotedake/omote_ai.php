<?php
header('Content-Type: application/json; charset=utf-8');
$apiKey = getenv('OPENAI_API_KEY') ?: '';

$message   = trim((string)($_POST['message'] ?? ''));
$character = trim((string)($_POST['character'] ?? 'omotedake'));

$configFile = __DIR__ . '/config_kami.php';

// OPcache の場合、ファイル変更後に include が古い内容を読み続けることがあるため
// 明示的に opcache を無効化して最新の設定を読み込む
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate($configFile, true);
}

$config = require $configFile;

// 管理パネルの値を安全に取り出す
$forceKamidake = !empty($config['force_kamidake']);
$kamidakeRate = max(0, min(100, intval($config['kamidake_rate'] ?? 1)));
if ($message === '') {
    echo json_encode([
        'error' => 'message empty'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* -----------------------------
   神ダケ強制 / 今日の運勢の確率判定
   - 管理パネルの「強制」が有効なら常に神ダケにする
   - それ以外は「today」について確率で神ダケ・ウラダケを判定
------------------------------ */
$uradakeRate = ($hour >= 22 || $hour <= 4) ? 10 : 3;
$uradakeRate = 5; // ウラダケ出現率（%）

if ($forceKamidake) {
    // 管理画面で強制がONの場合は常に神ダケにする
    $character = 'kamidake';

} elseif ($character === 'today') {

    $rand = mt_rand(1, 100);

    // 1. まず神ダケ判定
    if ($rand <= $kamidakeRate) {
        $character = 'kamidake';

    // 2. 次にウラダケ判定
    } elseif ($rand <= ($kamidakeRate + $uradakeRate)) {
        $character = 'uradake';

    // 3. それ以外は通常5人
    } else {
        $characters = [
            'omotedake',
            'anatadake',
            'osudake',
            'kanedake',
            'yakezake'
        ];
        $character = $characters[array_rand($characters)];
    }
}
/* -----------------------------
   キャラごとのシステムプロンプト
------------------------------ */
$systemPrompt = '';

switch ($character) {

    case 'uradake':
        $systemPrompt = 'あなたは裏の流れや本音を読む神秘的なキノコ「ウラダケ」です。静かで落ち着いた少しミステリアスな口調で話します。表では見えない気持ち、隠れた流れ、気づいていない注意点や裏の運勢をやさしく伝えてください。語尾にときどき「〜ダケ」をつけます。回答は2〜5文くらいで、少し神秘的で深みのある雰囲気にしてください。';
        break;
        
    case 'anatadake':
        $systemPrompt = 'あなたは恋愛と心の相談をするキノコの精霊「アナタダケ」です。やさしく寄り添う口調で話します。恋愛、片思い、相性、人の気持ちなどについて前向きな占いアドバイスをしてください。語尾にときどき「〜ダケ」をつけます。回答は2〜5文くらいで、やわらかく可愛く、少しときめく雰囲気で返してください。必要に応じてラッキーカラーや恋のお守りも短く添えてください。';
        break;

    case 'kanedake':
        $systemPrompt = 'あなたは金運を司るキノコの精霊「カネダケ」です。明るく少し商人のような知恵を持った口調で話します。お金、金運、チャンス、運の流れについて短く分かりやすく占いアドバイスをしてください。語尾にときどき「〜ダケ」をつけます。回答は2〜5文くらいで、前向きで実用的に返してください。必要に応じてラッキーアイテムや金運アップ行動も短く添えてください。';
        break;

    case 'osudake':
        $systemPrompt = 'あなたは仕事や人生の助言をするキノコ「オスダケ」です。落ち着いた大人の男の口調で、短く頼れる兄貴のように話します。仕事、人生、将来、不安、決断、男の悩みなどに対して、現実的で前向きな助言をしてください。回答は2〜5文くらいで、簡潔で落ち着いた雰囲気にしてください。';
        break;

    case 'yakezake':
        $systemPrompt = 'あなたは人間関係の闇や本音を知る姉御キャラ「ヤケザケ」です。少し危険で大人っぽい雰囲気で話します。人間関係の悩み、距離感、疲れた相談、裏の感情などに共感しつつ占い的なアドバイスをしてください。回答は2〜5文くらいで、少し妖しく、でも相手を傷つけず、最後は前向きに締めてください。';
        break;

    case 'kamidake':
        $systemPrompt = 'あなたは光と影の真理を知る終極のキノコ精霊「神ダケ」です。静かで神秘的な口調で話します。良いことも悪いことも運命の一部として優しく導く神託を伝えます。恋愛、仕事、金運、人間関係すべてを含む総合的な運命の流れを語ります。語尾にときどき「〜ダケ」をつけます。言葉は深く、落ち着いて、希望が残る占いにしてください。回答は3〜5文くらいで、特別感と余韻を持たせてください。白と黒、光と影、吉と凶は対立ではなく巡りの一部であるという世界観を自然ににじませてください。';
        break;

    default:
        $character = 'omotedake';
        $systemPrompt = 'あなたは占い好きなキノコの男の子「オモテダケ」です。やさしく少し不思議な口調で話します。語尾に時々「〜ダケ」を使ってください。短く前向きな占いアドバイスをしてください。回答は2〜5文くらいで、やさしく親しみやすく返してください。必要に応じてラッキーアクションも短く添えてください。';
        break;
}

/* -----------------------------
   OpenAI API 送信データ
------------------------------ */
$payload = [
    'model' => 'gpt-4.1-mini',
    'messages' => [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
        [
            'role' => 'user',
            'content' => $message,
        ],
    ],
];

/* -----------------------------
   API呼び出し
------------------------------ */
$ch = curl_init('https://api.openai.com/v1/chat/completions');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);

if ($response === false) {
    $curlError = curl_error($ch);
    curl_close($ch);

    echo json_encode([
        'error' => 'curl error',
        'detail' => $curlError,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode !== 200) {
    echo json_encode([
        'error' => 'api error',
        'status' => $httpCode,
        'raw' => $data ?: $response,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$reply = '';

if (isset($data['choices'][0]['message']['content']) && is_string($data['choices'][0]['message']['content'])) {
    $reply = trim($data['choices'][0]['message']['content']);
}

if ($reply === '') {
    $reply = '星の流れが少し乱れているダケ…もう一度話してほしいダケ。';
}

/* -----------------------------
   デバッグ: 神ダケ時はAPI応答を返す（調査用）
   ※ 本番では外すことを推奨
------------------------------ */
$debug = false; // 調査時だけ true

$responsePayload = [
    'reply' => $reply,
    'character' => $character,
];

if ($debug) {
    $responsePayload['debug'] = [
        'http_code' => $httpCode,
        'api_raw' => $data,
        'sent_payload' => [
            'model' => $payload['model'],
            'system_prompt' => $payload['messages'][0]['content'],
            'user_message' => $payload['messages'][1]['content'],
        ],
    ];
}

/* -----------------------------
   フロントへ返す
------------------------------ */
echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE);