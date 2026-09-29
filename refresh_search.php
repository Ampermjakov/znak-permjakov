<?php
// Кнопка "Обновить" НАД списком результатов текстового поиска — обновляет
// не одну карточку (это refresh.php), а весь сохранённый результат фразы
// целиком (search_cache), тем же принципом: авторизация + раз в сутки,
// не важно кто из авторизованных жмёт — ограничение общее на фразу+страницу.
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

$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$size = min(50, max(1, (int)($_GET['size'] ?? 20)));

if (mb_strlen($q) < 2) {
    out(['error' => 'Некорректный запрос'], 400);
}

$user = authCurrentUser();
if (!$user) {
    out(['error' => 'Обновление доступно только авторизованным пользователям', 'need_auth' => true], 401);
}

$pdo = dbConnect();
if (!$pdo) out(['error' => 'Нет соединения с БД'], 500);

// Считаем разницу средствами MySQL (TIMESTAMPDIFF), а не PHP strtotime() —
// та же причина, что и в refresh.php: несовпадение таймзоны PHP/MySQL иначе
// портит счётчик cooldown.
$st = $pdo->prepare('SELECT TIMESTAMPDIFF(HOUR, fetched_at, NOW()) AS age_hours
                      FROM search_cache WHERE query_key = ?');
$st->execute([searchCacheKey($q, $page)]);
$ageHours = $st->fetchColumn();

if ($ageHours !== false && $ageHours < REFRESH_COOLDOWN_HOURS) {
    $wait = REFRESH_COOLDOWN_HOURS - (int)$ageHours;
    out(['error' => "Этот поиск уже обновляли недавно. Следующее обновление доступно через {$wait} ч."], 429);
}

$live = rospatentSearch($q, $page, $size);

if (!empty($live['quota_exceeded'])) {
    out(['error' => $live['error']], 503);
}
if (isset($live['error'])) {
    out(['error' => $live['error']], 502);
}

$uids = [];
foreach ($live['data'] ?? [] as &$item) {
    tmCacheSave($item);
    $item['_fetched_at'] = date('Y-m-d H:i:s');
    if (!empty($item['object_uid'])) $uids[] = $item['object_uid'];
}
unset($item);

$total = (int)($live['totalResult'] ?? count($uids));
searchCacheSave($q, $page, $total, $uids);
if ($page === 1) searchCacheSeedRelated($live['data'] ?? [], $total);
logSearch('api', $user['email'], "refresh_search:{$q}", $total);

$results = array_map('formatTrademarkItem', $live['data'] ?? []);
usort($results, fn($a, $b) => statusSortRank($a['status']['code']) <=> statusSortRank($b['status']['code']));

out([
    'ok'                 => true,
    'total'              => $total,
    'page'               => $live['currentPage'] ?? $page,
    'total_pages'        => max(1, (int)ceil($total / $size)),
    'per_page'           => $size,
    'results'            => $results,
    'search_fetched_at'  => date('Y-m-d H:i:s'),
]);
