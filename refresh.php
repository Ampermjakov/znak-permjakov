<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/rospatent.php';
require_once __DIR__ . '/lib/format.php';

error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$regNumber = trim($_GET['reg_number'] ?? '');
if ($regNumber === '' || !preg_match('/^\d{1,15}$/', $regNumber)) {
    out(['error' => 'Некорректный номер регистрации'], 400);
}

$user = authCurrentUser();
if (!$user) {
    out(['error' => 'Обновление доступно только авторизованным пользователям', 'need_auth' => true], 401);
}

$pdo = dbConnect();
if (!$pdo) out(['error' => 'Нет соединения с БД'], 500);

// Не чаще раза в REFRESH_COOLDOWN_HOURS на один и тот же номер — не важно,
// кто из авторизованных жмёт, ограничение общее на карточку, а не на юзера.
// Считаем разницу средствами MySQL (TIMESTAMPDIFF), а не PHP strtotime() —
// иначе при несовпадении таймзоны PHP и таймзоны сессии MySQL счётчик врёт.
$st = $pdo->prepare('SELECT TIMESTAMPDIFF(HOUR, fetched_at, NOW()) AS age_hours
                      FROM trademark_cache WHERE reg_number = ? ORDER BY fetched_at DESC LIMIT 1');
$st->execute([$regNumber]);
$ageHours = $st->fetchColumn();

if ($ageHours !== false && $ageHours < REFRESH_COOLDOWN_HOURS) {
    $wait = REFRESH_COOLDOWN_HOURS - (int)$ageHours;
    out(['error' => "Эту карточку уже обновляли недавно. Следующее обновление доступно через {$wait} ч."], 429);
}

// Роспатент ищет по номеру ЗАЯВКИ, а не регистрации: по рег.номеру свободный поиск
// отдаёт 0 результатов. Берём appl_number из локальной записи и ищем по нему.
$local = findLocalByNumber($regNumber);
$applNumber = trim((string) ($local['appl_number'] ?? ''));
$query = $applNumber !== '' ? $applNumber : $regNumber;

$data = rospatentSearch($query, 1, 5);

if (!empty($data['quota_exceeded'])) {
    out(['error' => $data['error']], 503);
}
if (isset($data['error'])) {
    out(['error' => $data['error']], 502);
}

$item = null;
foreach ($data['data'] ?? [] as $candidate) {
    $rn = $candidate['reg_number'] ?? null;
    $an = $candidate['appl_number'] ?? null;
    if ($rn === $regNumber || ($applNumber !== '' && $an === $applNumber)) { $item = $candidate; break; }
}

if (!$item) {
    out(['error' => 'Актуальных данных по этому номеру в поиске Роспатента не найдено']);
}

tmCacheSave($item);
logSearch('api', $user['email'], "refresh:{$regNumber}", 1);

$item['_fetched_at'] = date('Y-m-d H:i:s');
out(['ok' => true, 'item' => formatTrademarkItem($item)]);
