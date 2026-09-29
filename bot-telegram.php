<?php
// Telegram Bot Webhook — Проверка товарных знаков
// Размести рядом с search.php. Вебхук ставится через сервер 45.88.106.54
// (Telegram заблокирован в РФ, трафик бота идёт через него), как и у chek.permjakov.ru.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/rospatent.php';

$input  = file_get_contents('php://input');
$update = json_decode($input, true);
if (!$update) exit;

file_put_contents(__DIR__ . '/tg_bot.log', date('Y-m-d H:i:s') . ' ' . $input . "\n", FILE_APPEND);

$message = $update['message'] ?? $update['channel_post'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'] ?? null;
$text   = trim($message['text'] ?? '');
if (!$chatId) exit;

function sendMessage($chatId, $text) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => TG_API . '/sendMessage',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    return $result;
}

function sendPhoto($chatId, $photoUrl, $caption) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => TG_API . '/sendPhoto',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'chat_id' => $chatId,
            'photo' => $photoUrl,
            'caption' => $caption,
            'parse_mode' => 'Markdown',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    $data = json_decode($result, true);
    // Если Роспатент отдал битую/недоступную ссылку — падаем на обычный текст
    if (empty($data['ok'])) sendMessage($chatId, $caption);
    return $result;
}

function sendWelcome($chatId) {
    sendMessage($chatId,
        "👋 Привет! Я проверяю *товарные знаки* по открытому реестру Роспатента.\n\n" .
        "*Как использовать:*\n" .
        "Отправьте:\n" .
        "• номер свидетельства — `998616`\n" .
        "• название знака — `Яндекс`\n" .
        "• правообладателя — `Иванов Иван Иванович`\n\n" .
        "В ответ — статус, даты, классы МКТУ и изображение знака."
    );
}

if ($text === '/start') { sendWelcome($chatId); exit; }
if ($text === '/help') {
    sendMessage($chatId,
        "*Команда:* `/check <номер|название|правообладатель>`\n" .
        "Либо просто отправьте текст запроса без команды.\n\n" .
        "Полная версия с фильтрами и историей поиска: [znak.permjakov.ru](https://znak.permjakov.ru)"
    );
    exit;
}

$query = preg_replace('/^\/check\s*/i', '', $text);
$query = trim($query);

if (mb_strlen($query) < 2) {
    sendMessage($chatId, "❓ Отправьте номер, название знака или правообладателя.\n\nНапример: `998616` или `Яндекс`\n\n/help — справка");
    exit;
}

sendMessage($chatId, "🔍 Ищу «{$query}»...");

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
logSearch('telegram', (string)$chatId, $query, (int)($data['totalResult'] ?? 0));

$total = (int)($data['totalResult'] ?? 0);
if ($total === 0) {
    sendMessage($chatId, "🚫 Ничего не найдено по запросу «{$query}».\n\n_Данные предоставлены Роспатентом на условиях Открытой лицензии._");
    exit;
}

if ($total > 5) {
    sendMessage($chatId, "Найдено *{$total}* совпадений, показываю первые 5. Уточните запрос для более точного результата.");
}

foreach (array_slice($data['data'] ?? [], 0, 5) as $item) {
    $msg = formatCard($item);
    $imageUrl = $item['files'][0]['file_url'] ?? null;
    if ($imageUrl) {
        sendPhoto($chatId, $imageUrl, $msg);
    } else {
        sendMessage($chatId, $msg);
    }
}

sendMessage($chatId, "_Данные предоставлены Роспатентом на условиях Открытой лицензии (rospatent.gov.ru/opendata)._\n🌐 Полный поиск: https://znak.permjakov.ru");

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
    $msg  = "{$icon} *{$name}* — {$statusLabel}\n\n";

    if ($isRegistered) {
        $msg .= "📄 Регистрация №: `{$item['reg_number']}`\n";
        if (!empty($item['reg_date']))    $msg .= "📅 Дата регистрации: " . substr($item['reg_date'], 0, 10) . "\n";
        if (!empty($item['expiry_date'])) $msg .= "⏳ Действует до: " . substr($item['expiry_date'], 0, 10) . "\n";
    }
    if (!empty($item['appl_number'])) $msg .= "📝 Заявка №: `{$item['appl_number']}`\n";
    if (!empty($item['appl_date']))   $msg .= "📅 Дата подачи: " . substr($item['appl_date'], 0, 10) . "\n";
    if (!empty($item['holders']))     $msg .= "\n👤 Правообладатель: {$item['holders']}\n";
    if (!empty($item['goods_classes'])) $msg .= "\n🏷 Классы МКТУ: {$item['goods_classes']}\n";

    $link = $item['open_registry_url'] ?: ($item['appl_registry_url'] ?? '');
    if ($link) $msg .= "\n🔗 [Карточка на fips.ru]({$link})";

    // Telegram ограничивает caption к фото 1024 символами
    if (mb_strlen($msg) > 1000) $msg = mb_substr($msg, 0, 950) . "\n\n_...сокращено_";

    return $msg;
}
