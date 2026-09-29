<?php
// MAX Bot Webhook — Проверка товарных знаков
// Размести рядом с search.php. Ходит через сервер 45.88.106.54, как и chek.permjakov.ru.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/rospatent.php';

$input  = file_get_contents('php://input');
$update = json_decode($input, true);
if (!$update) exit;

file_put_contents(__DIR__ . '/max_bot.log', date('Y-m-d H:i:s') . ' ' . $input . "\n", FILE_APPEND);

$type = $update['update_type'] ?? '';
if ($type !== 'message_created' && $type !== 'bot_started') exit;

$message = $update['message'] ?? [];
$body    = $message['body'] ?? [];
$chatId  = $message['recipient']['chat_id'] ?? $update['chat_id'] ?? null;
if (!$chatId) exit;

$text = trim($body['text'] ?? '');

function sendMessage($chatId, $text) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MAX_API . '/messages?chat_id=' . $chatId,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'text' => $text,
            'format' => 'markdown',
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . MAX_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    file_put_contents(__DIR__ . '/max_bot.log', date('Y-m-d H:i:s') . ' API: ' . $result . PHP_EOL, FILE_APPEND);
    return $result;
}

function sendWelcome($chatId) {
    sendMessage($chatId,
        "👋 Привет! Я проверяю **товарные знаки** по открытому реестру Роспатента.\n\n" .
        "**Как использовать:**\n" .
        "Отправьте:\n" .
        "• номер свидетельства — `998616`\n" .
        "• название знака — `Яндекс`\n" .
        "• правообладателя — `Иванов Иван Иванович`\n\n" .
        "В ответ — статус, даты и классы МКТУ, со ссылкой на изображение знака."
    );
}

if ($type === 'bot_started') { sendWelcome($chatId); exit; }
if ($text === '/start' || $text === 'Начать') { sendWelcome($chatId); exit; }
if ($text === '/help') {
    sendMessage($chatId,
        "**Как пользоваться:**\n\nПросто отправьте номер, название знака или правообладателя — я проверю по реестру Роспатента.\n\n" .
        "Полная версия с фильтрами: [znak.permjakov.ru](https://znak.permjakov.ru)"
    );
    exit;
}

$query = preg_replace('/^\/check\s*/i', '', $text);
$query = trim($query);

if (mb_strlen($query) < 2) {
    sendMessage($chatId, "❓ Отправьте номер, название знака или правообладателя.\n\nНапример: `998616` или `Яндекс`\n\n/help — справка");
    exit;
}

sendMessage($chatId, "🔍 Ищу «" . $query . "»...");

$data = rospatentSearch($query, 1, 5);

if (!empty($data['quota_exceeded'])) {
    // Дневной лимит живого поиска исчерпан — не молчим и не пугаем ошибкой,
    // пробуем ответить тем, что уже успели накопить в своей базе.
    $local = preg_match('/^\d{4,15}$/', $query)
        ? (($r = findLocalByNumber($query)) ? [$r] : [])
        : searchLocalFulltext($query, 5);

    if ($local) {
        sendMessage($chatId, "⏳ " . $data['error'] . " Показываю то, что уже есть в базе (может быть неполно).");
        $data = ['data' => $local, 'totalResult' => count($local)];
    } else {
        sendMessage($chatId, "⏳ " . $data['error']);
        exit;
    }
} elseif (isset($data['error'])) {
    sendMessage($chatId, "⚠️ " . $data['error']);
    exit;
}

foreach ($data['data'] ?? [] as $item) tmCacheSave($item);
logSearch('max', (string)$chatId, $query, (int)($data['totalResult'] ?? 0));

$total = (int)($data['totalResult'] ?? 0);
if ($total === 0) {
    sendMessage($chatId, "🚫 Ничего не найдено по запросу «{$query}».\n\n_Данные предоставлены Роспатентом на условиях Открытой лицензии._");
    exit;
}

$msg = '';
if ($total > 5) {
    $msg .= "Найдено **{$total}** совпадений, показываю первые 5. Уточните запрос для более точного результата.\n\n─────────────────\n";
}

foreach (array_slice($data['data'] ?? [], 0, 5) as $item) {
    $msg .= "\n" . formatCard($item) . "\n─────────────────\n";
}

$msg .= "\n_Данные предоставлены Роспатентом на условиях Открытой лицензии (rospatent.gov.ru/opendata)._\n";
$msg .= "🌐 Полный поиск с картинками: https://znak.permjakov.ru";

if (mb_strlen($msg) > 4000) {
    $msg = mb_substr($msg, 0, 3900) . "\n\n_...сокращено_\n🌐 https://znak.permjakov.ru";
}

sendMessage($chatId, $msg);

// ═══════════════════════════════════════════════════════════════════════

function formatCard(array $item): string
{
    $isRegistered = !empty($item['reg_number']);
    if (!$isRegistered) {
        $icon = '🟡'; $statusLabel = 'Заявка на рассмотрении';
    } elseif (!empty($item['expiry_date']) && strtotime($item['expiry_date']) < time()) {
        $icon = '🔴'; $statusLabel = 'Истёк срок действия';
    } else {
        $icon = '🟢'; $statusLabel = 'Действует';
    }

    $name = $item['mark_description_text'] ?? '(словесная часть не выделена)';
    $msg  = "{$icon} **{$name}** — {$statusLabel}\n";

    if ($isRegistered) {
        $msg .= "📄 Регистрация №: `{$item['reg_number']}`\n";
        if (!empty($item['reg_date']))    $msg .= "📅 Дата регистрации: " . substr($item['reg_date'], 0, 10) . "\n";
        if (!empty($item['expiry_date'])) $msg .= "⏳ Действует до: " . substr($item['expiry_date'], 0, 10) . "\n";
    }
    if (!empty($item['appl_number'])) $msg .= "📝 Заявка №: `{$item['appl_number']}`\n";
    if (!empty($item['appl_date']))   $msg .= "📅 Дата подачи: " . substr($item['appl_date'], 0, 10) . "\n";
    if (!empty($item['holders']))     $msg .= "👤 Правообладатель: {$item['holders']}\n";
    if (!empty($item['goods_classes'])) $msg .= "🏷 Классы МКТУ: {$item['goods_classes']}\n";

    $imageUrl = $item['files'][0]['file_url'] ?? null;
    if ($imageUrl) $msg .= "🖼 Изображение: {$imageUrl}\n";

    $link = $item['open_registry_url'] ?: ($item['appl_registry_url'] ?? '');
    if ($link) $msg .= "🔗 Карточка на fips.ru: {$link}\n";

    return $msg;
}
