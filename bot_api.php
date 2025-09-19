<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Ramsey\Uuid\Uuid;

/* === НАСТРОЙКИ=== */
// Значения берутся из переменных окружения;
// Настрой: GIGA_API_BASE, GIGA_OAUTH_BASE, GIGA_MODEL, GIGA_CA_BUNDLE, GIGA_ATTACH_FILE,
//          GIGA_ATTACH_ALWAYS, GIGA_CLIENT_ID, GIGA_CLIENT_SECRET,
//          GIGA_SYSTEM_PROMPT или GIGA_SYSTEM_PROMPT_FILE

function env_str(string $name, ?string $default = null): ?string {
    $v = getenv($name);
    if ($v === false) return $default;
    $v = is_string($v) ? trim($v) : '';
    return $v === '' ? ($default) : $v;
}
function env_bool(string $name, bool $default = false): bool {
    $v = getenv($name);
    if ($v === false) return $default;
    $v = strtolower(trim((string)$v));
    return in_array($v, ['1','true','yes','on'], true);
}

$CA_BUNDLE = env_str('GIGA_CA_BUNDLE');
$API_BASE  = env_str('GIGA_API_BASE', 'https://gigachat.devices.sberbank.ru');
$OAUTH_BASE= env_str('GIGA_OAUTH_BASE', 'https://ngw.devices.sberbank.ru:9443/');
$MODEL     = env_str('GIGA_MODEL', 'GigaChat-2-Pro');

$HIST_TTL = 3600;   // хранение истории 1 час
$MAX_MSGS = 10;     // максимум сообщений истории
$MAX_PREFIX = 24000;  // максимум символов в префиксе истории
$MAX_ONE_MSG = 4000;   // максимум символов в одном сообщении

// Файл, который при необходимости подмешиваем (путь задаётся переменной окружения)
$ATTACH_FIXED_FILE = env_str('GIGA_ATTACH_FILE');
$ATTACH_ALWAYS     = env_bool('GIGA_ATTACH_ALWAYS', false);  // true = подмешивать в каждый запрос

/* === СИСТЕМНЫЙ ПРОМПТ (внешняя настройка) === */
// Можно задать прямо в GIGA_SYSTEM_PROMPT или указать путь к файлу в GIGA_SYSTEM_PROMPT_FILE
$SYSTEM_PROMPT = (function (): string {
    $file = env_str('GIGA_SYSTEM_PROMPT_FILE');
    if ($file && is_file($file)) {
        $txt = (string)@file_get_contents($file);
        if ($txt !== '') return $txt;
    }
    $inline = env_str('GIGA_SYSTEM_PROMPT');
    if ($inline !== null && $inline !== '') return $inline;
    // Нейтральный дефолт без брендинга
    return 'Ты — ИИ‑ассистент. Отвечай кратко (1–3 предложения) строго по предоставленной информации. '
         . 'Если данных нет — отвечай: «У меня нет информации по этому вопросу.» '
         . 'Форматируй ответ как HTML с <p>…</p>, без заголовков <h1>–<h6>, <script> и <style>. '
         . 'Ссылки выводи как <a href="URL">Название</a>.';
})();

/* === УТИЛИТЫ === */
function ok(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, array $details = null, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg, 'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function http(string $baseUri, ?string $caBundle, int $timeout = 30): Client {
    return new Client([
        'base_uri'    => $baseUri,
        'verify'      => ($caBundle && file_exists($caBundle)) ? $caBundle : true,
        'http_errors' => false,
        'timeout'     => $timeout,
    ]);
}

/* === ТОЛЬКО POST + JSON === */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Method Not Allowed', null, 405);
}
$payload = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    fail('Поле "message" обязательно.', null, 400);
}

/* === СЕССИЯ ДЛЯ КЭША МОДЕЛИ === */
function getStableSessionId(array $payload): string {
    $sid = (string)($payload['sessionId'] ?? '');
    if ($sid === '' && isset($_SERVER['HTTP_X_SESSION_ID'])) $sid = (string)$_SERVER['HTTP_X_SESSION_ID'];
    if ($sid === '' && isset($_COOKIE['x_session_id']))      $sid = (string)$_COOKIE['x_session_id'];
    if (!Uuid::isValid($sid)) {
        $sid = Uuid::uuid4()->toString();
        setcookie('x_session_id', $sid, [
            'expires'  => time() + 7*24*3600,
            'path'     => '/',
            'secure'   => true,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    return $sid;
}

/* === ХРАНИЛКА ИСТОРИИ: APCu → файл (TTL 1 час) === */
function histKey(string $sid): string { return "giga_hist_$sid"; }
function histPath(string $key): string { return sys_get_temp_dir() . "/$key.json"; }

function loadHistory(string $sessionId): array {
    global $HIST_TTL;
    $key = histKey($sessionId);

    if (function_exists('apcu_fetch')) {
        $wrap = apcu_fetch($key, $ok);
        if ($ok && is_array($wrap) && ($wrap['exp'] ?? 0) > time()) return $wrap['data'] ?? [];
    }
    $file = histPath($key);
    if (is_file($file)) {
        $wrap = json_decode((string)@file_get_contents($file), true);
        if (is_array($wrap) && ($wrap['exp'] ?? 0) > time()) {
            if (function_exists('apcu_store')) apcu_store($key, $wrap, $HIST_TTL);
            return $wrap['data'] ?? [];
        }
        @unlink($file);
    }
    return [];
}
function saveHistory(string $sessionId, array $history): void {
    global $HIST_TTL;
    $key  = histKey($sessionId);
    $file = histPath($key);
    $wrap = ['exp' => time() + $HIST_TTL, 'data' => $history];

    if (function_exists('apcu_store')) apcu_store($key, $wrap, $HIST_TTL);
    @file_put_contents($file, json_encode($wrap, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* === ОБРЕЗКИ === */
function clampMsg(string $s): string {
    global $MAX_ONE_MSG;
    if (mb_strlen($s) <= $MAX_ONE_MSG) return $s;
    $tail = mb_substr($s, -$MAX_ONE_MSG);
    return "…[truncated]…" . $tail;
}
function trimHistoryHard(array $history): array {
    foreach ($history as &$m) { $m['content'] = clampMsg((string)($m['content'] ?? '')); }
    return $history;
}

/* === МЯГКАЯ ОБРЕЗКА С СОХРАНЕНИЕМ SYSTEM (НОВОЕ) === */
function trimHistorySoft(array $history): array {
    global $MAX_MSGS, $MAX_PREFIX;

    // сохраняем первый system, если есть
    $system = null;
    if (!empty($history) && ($history[0]['role'] ?? '') === 'system') {
        $system = $history[0];
        $history = array_slice($history, 1);
    }

    // ограничение по количеству сообщений: учитываем system как 1 из MAX_MSGS
    $limitTail = $system ? max(0, $MAX_MSGS - 1) : max(0, $MAX_MSGS);
    if (count($history) > $limitTail) {
        $history = $limitTail === 0 ? [] : array_slice($history, -$limitTail);
    }

    // добираем хвост под лимит символов
    $total = 0; $trim = [];
    foreach (array_reverse($history) as $msg) {
        $len = mb_strlen((string)($msg['content'] ?? ''));
        if ($total + $len > $MAX_PREFIX) break;
        $trim[] = $msg; $total += $len;
    }
    $trim = array_reverse($trim);

    if ($system) array_unshift($trim, $system);
    return $trim;
}

function dropOldestPair(array $history): array {
    $n = count($history);
    if ($n <= 2) return $history;
    $i = 0;
    while ($i < $n && ($history[$i]['role'] ?? '') !== 'user') $i++;
    if ($i < $n) {
        array_splice($history, $i, 1);
        $n = count($history);
        for ($j = $i; $j < $n; $j++) {
            if (($history[$j]['role'] ?? '') === 'assistant') { array_splice($history, $j, 1); break; }
        }
    }
    return $history;
}

/* === ЖЁСТКИЙ РЕ-ИНЖЕКТ SYSTEM (НОВОЕ) === */
function forceSystemPrompt(array $history, string $systemPrompt): array {
    // убираем все существующие system
    $history = array_values(array_filter($history, fn($m) => ($m['role'] ?? '') !== 'system'));
    // вставляем актуальный system в начало
    array_unshift($history, ['role' => 'system', 'content' => $systemPrompt]);
    return $history;
}

/* === ОAUTH (кеш) === */
function getAccessToken(string $oauthBase, ?string $caBundle): string {
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch('giga_token', $ok);
        if ($ok && is_string($cached) && $cached !== '') return $cached;
    }
    $tmp = sys_get_temp_dir() . '/giga_token_cache.json';
    if (is_file($tmp)) {
        $data = json_decode((string)@file_get_contents($tmp), true);
        if (!empty($data['token']) && ($data['exp'] ?? 0) > time()) {
            if (function_exists('apcu_store')) apcu_store('giga_token', $data['token'], $data['exp'] - time());
            return $data['token'];
        }
    }

    // Чувствительные данные берём из окружения
    $clientId = env_str('GIGA_CLIENT_ID');
    $clientSecret = env_str('GIGA_CLIENT_SECRET');
    if (!$clientId || !$clientSecret) {
        fail('Не заданы учетные данные (GIGA_CLIENT_ID/GIGA_CLIENT_SECRET)', null, 500);
    }
    $auth  = base64_encode(trim($clientId) . ':' . trim($clientSecret));
    $cli   = http($oauthBase, $caBundle, 20);

    $resp = $cli->post('api/v2/oauth', [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'RqUID'         => Uuid::uuid4()->toString(),
        ],
        'form_params' => ['scope' => 'GIGACHAT_API_B2B'],
    ]);

    $code  = $resp->getStatusCode();
    $data  = json_decode((string)$resp->getBody(), true) ?: [];
    $token = $data['access_token'] ?? null;
    $ttl   = (int)($data['expires_in'] ?? 0);
    $exp   = time() + max(0, $ttl - 30);

    if ($code < 200 || $code >= 300 || !$token) {
        fail('Не удалось получить токен', ['status' => $code, 'body' => $data], 502);
    }

    if (function_exists('apcu_store')) apcu_store('giga_token', $token, max(1, $exp - time()));
    @file_put_contents($tmp, json_encode(['token' => $token, 'exp' => $exp], JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $token;
}

/* === ФАЙЛЫ: загрузка + кеш fileId по mtime === */
function uploadFile(string $apiBase, ?string $caBundle, string $token, string $filepath, string $purpose='general'): array {
    if (!is_file($filepath)) fail('Файл для загрузки не найден', ['path' => $filepath], 400);
    $cli = http($apiBase, $caBundle, 120);

    $resp = $cli->post('/api/v1/files', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'multipart' => [
            ['name'=>'file','contents'=>fopen($filepath,'rb'),'filename'=>basename($filepath)],
            ['name'=>'purpose','contents'=>$purpose],
        ],
    ]);
    $code = $resp->getStatusCode();
    $data = json_decode((string)$resp->getBody(), true) ?: [];
    if ($code < 200 || $code >= 300 || empty($data['id'])) {
        fail('Не удалось загрузить файл', ['status'=>$code,'body'=>$data], 502);
    }
    return $data; // id, bytes, created_at, filename, purpose...
}
function getFixedFileId(string $apiBase, ?string $caBundle, string $token, string $path): string {
    $cacheFile = sys_get_temp_dir() . '/gigachat_fileid_cache.json';
    $mtime = @filemtime($path) ?: 0;

    $cache = is_file($cacheFile) ? (json_decode((string)@file_get_contents($cacheFile), true) ?: []) : [];
    if (!empty($cache['path']) && $cache['path'] === $path && !empty($cache['id']) && ($cache['mtime'] ?? 0) === $mtime) {
        return (string)$cache['id'];
    }
    $meta = uploadFile($apiBase, $caBundle, $token, $path, 'general');
    $cache = ['path'=>$path, 'id'=>$meta['id'], 'mtime'=>$mtime];
    @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return (string)$meta['id'];
}

/* === НИЗКИЙ УРОВЕНЬ ВЫЗОВА МОДЕЛИ === */
function callGigaChatRaw(string $apiBase, ?string $caBundle, string $model, string $token, array $messages, string $sessionId, array $attachments=[]): array {
    // если есть вложения — положим их в последний user-месседж
    if (!empty($attachments)) {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                $messages[$i]['attachments'] = array_values($attachments);
                break;
            }
        }
    }

    $cli  = http($apiBase, $caBundle, 60);
    $json = [
        'model'          => $model,
        'messages'       => $messages,
        'profanity_check'=> true,
    ];

    $resp = $cli->post('/api/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'X-Request-ID'  => Uuid::uuid4()->toString(),
            'X-Session-ID'  => $sessionId,
        ],
        'json' => $json,
    ]);

    $code = $resp->getStatusCode();
    $data = json_decode((string)$resp->getBody(), true) ?: [];
    return ['status' => $code, 'data' => $data];
}

/* === УМНЫЙ ВЫЗОВ (ретраи + адаптивная обрезка) — ДОБАВЛЕН systemPrompt === */
function askGigaChatSmart(
    string $apiBase,
    ?string $caBundle,
    string $model,
    string $token,
    array $history,
    string $sessionId,
    array $attachments=[],
    string $systemPrompt=''
): array {
    // перед первым вызовом: обрезка + ре-инжект system
    $history = trimHistoryHard($history);
    $history = trimHistorySoft($history);
    if ($systemPrompt !== '') $history = forceSystemPrompt($history, $systemPrompt);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $raw  = callGigaChatRaw($apiBase, $caBundle, $model, $token, $history, $sessionId, $attachments);
        $code = (int)$raw['status'];
        $data = $raw['data'];

        $text  = $data['choices'][0]['message']['content'] ?? null;
        $usage = $data['usage'] ?? [];
        $prec  = $usage['precached_prompt_tokens'] ?? ($data['precached_prompt_tokens'] ?? 0);

        if ($code >= 200 && $code < 300 && $text !== null) {
            return [
                'text'  => (string)$text,
                'usage' => [
                    'prompt_tokens'            => $usage['prompt_tokens'] ?? null,
                    'completion_tokens'        => $usage['completion_tokens'] ?? null,
                    'total_tokens'             => $usage['total_tokens'] ?? null,
                    'precached_prompt_tokens'  => $prec,
                ],
            ];
        }

        $isCtxErr = ($code === 400 || $code === 413 || $code === 422 || $code === 500 || $code === 503);
        if ($isCtxErr && $attempt < 3) {
            if (count($history) > 2) {
                $history = dropOldestPair($history);
                $history = trimHistorySoft($history);
                if ($systemPrompt !== '') $history = forceSystemPrompt($history, $systemPrompt); // защита при ретраях
                continue;
            }
        }

        if ($attempt === 3) {
            // минимальный запрос: system + последний user
            $lastUser = null;
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (($history[$i]['role'] ?? '') === 'user') { $lastUser = $history[$i]; break; }
            }
            if ($lastUser) {
                $minimal = [
                    ['role' => 'system', 'content' => $systemPrompt ?: ''],
                    ['role' => 'user', 'content' => clampMsg((string)$lastUser['content'])],
                ];
                $raw2  = callGigaChatRaw($apiBase, $caBundle, $model, $token, $minimal, $sessionId, $attachments);
                $code2 = (int)$raw2['status'];
                $data2 = $raw2['data'];
                $text2 = $data2['choices'][0]['message']['content'] ?? null;
                if ($code2 >= 200 && $code2 < 300 && $text2 !== null) {
                    $usage2 = $data2['usage'] ?? [];
                    $prec2  = $usage2['precached_prompt_tokens'] ?? ($data2['precached_prompt_tokens'] ?? 0);
                    return [
                        'text'  => (string)$text2,
                        'usage' => [
                            'prompt_tokens'            => $usage2['prompt_tokens'] ?? null,
                            'completion_tokens'        => $usage2['completion_tokens'] ?? null,
                            'total_tokens'             => $usage2['total_tokens'] ?? null,
                            'precached_prompt_tokens'  => $prec2,
                        ],
                    ];
                }
            }
            fail('Ошибка от GigaChat', ['status' => $code, 'body' => $data], 502);
        }
    }
    fail('Неизвестная ошибка', null, 502);
}

/* === PIPELINE === */
$sessionId = getStableSessionId($payload);
$token     = getAccessToken($OAUTH_BASE, $CA_BUNDLE);

// attachments по фиксированному файлу
$attachments = [];
if ($ATTACH_ALWAYS && $ATTACH_FIXED_FILE) {
    if (!is_file($ATTACH_FIXED_FILE)) {
        fail('Фиксированный файл не найден на сервере', ['path' => $ATTACH_FIXED_FILE], 500);
    }
    $fileId = getFixedFileId($API_BASE, $CA_BUNDLE, $token, $ATTACH_FIXED_FILE);
    $attachments = [$fileId];
}

// история
$history = loadHistory($sessionId);

// (опционально) системный промпт в начало — как было у тебя
if (empty($history) || ($history[0]['role'] ?? '') !== 'system') {
     array_unshift($history, ['role' => 'system', 'content' => $SYSTEM_PROMPT]);
}

// добавляем текущий запрос пользователя
$history[] = ['create_at' => time(), 'role' => 'user', 'content' => $message];

/* НОВОЕ: зафиксировать system в нуле перед вызовом */
$history = forceSystemPrompt($history, $SYSTEM_PROMPT);

// вызов модели (с передачей $SYSTEM_PROMPT внутрь)
$result = askGigaChatSmart($API_BASE, $CA_BUNDLE, $MODEL, $token, $history, $sessionId, $attachments, $SYSTEM_PROMPT);

// сохраняем ответ ассистента
$history[] = ['create_at' => time(), 'role' => 'assistant', 'content' => $result['text']];

// НОВОЕ: не даём «съесть» system при сохранении
$history = trimHistoryHard($history);
$history = trimHistorySoft($history);
$history = forceSystemPrompt($history, $SYSTEM_PROMPT);
saveHistory($sessionId, $history);

/* === ОТВЕТ ФРОНТУ === */
ok([
    'response' => $result['text'],
    'session_id' => $sessionId,
    'usage' => $result['usage'],
    'cached_prompt_tokens' => $result['usage']['precached_prompt_tokens'],
    'attachments_used' => $attachments,
]);
