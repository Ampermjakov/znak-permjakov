<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/rospatent.php';
require_once __DIR__ . '/lib/format.php';

error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(30);
ob_start();

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Внутренняя ошибка сервиса'], JSON_UNESCAPED_UNICODE);
    } else {
        ob_end_flush();
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$size = min(50, max(1, (int)($_GET['size'] ?? 20)));

if (mb_strlen($q) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Введите номер, название знака или правообладателя (минимум 2 символа)'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ИНН (10 цифр — юрлицо, 12 — ИП) — точный, не нечёткий поиск по владельцу,
// целиком из своей базы (findLocalByInn), живой поиск не трогаем.
//
// Точный номер — адресный запрос одной записи из своей базы (findLocalByNumber),
// живой поиск не трогаем.
//
// Текст (название/владелец) — кэш результата ЭТОЙ КОНКРЕТНОЙ фразы+страницы
// (searchCacheGet, см. lib/db.php). Если такой запрос уже кто-то делал —
// отдаём ровно то же самое, что пришло тогда, без похода вживую. Если нет —
// один живой запрос, сохраняем и карточки (tmCacheSave), и сам список
// результатов под этой фразой (searchCacheSave) — следующий такой же запрос
// уже найдётся локально.
$source = 'local';
$notice = null;
$searchFetchedAt = null; // когда именно ЭТА фраза последний раз бралась из живого поиска — для бейджика и кнопки "Обновить" над списком результатов

if ($page === 1 && preg_match('/^\d{10}$|^\d{12}$/', $q) && ($innItems = findLocalByInn($q))) {
    $data = [
        'totalResult'    => count($innItems),
        'currentPage'    => 1,
        'totalPages'     => 1,
        'resultsPerPage' => $size,
        'data'           => $innItems,
    ];
} elseif ($page === 1 && preg_match('/^\d{4,15}$/', $q)) {
    $local = findLocalByNumber($q);
    $localItems = $local ? [$local] : [];
    $data = [
        'totalResult'    => count($localItems),
        'currentPage'    => 1,
        'totalPages'     => 1,
        'resultsPerPage' => $size,
        'data'           => $localItems,
    ];
} else {
    $cached = searchCacheGet($q, $page);

    if ($cached !== null) {
        $searchFetchedAt = $cached['fetched_at'];
        $data = [
            'totalResult'    => $cached['total'],
            'currentPage'    => $page,
            'totalPages'     => max(1, (int)ceil($cached['total'] / $size)),
            'resultsPerPage' => $size,
            'data'           => $cached['items'],
        ];
    } else {
        $source = 'live';
        $live = rospatentSearch($q, $page, $size);

        if (!empty($live['quota_exceeded'])) {
            // Дневной лимит живого поиска исчерпан, а такой запрос ещё никто
            // не делал — честно говорим, что проверить сейчас нечем, а не
            // притворяемся, что "имя свободно".
            $notice = $live['error'];
            $source = 'local';
            $data = ['totalResult' => 0, 'currentPage' => 1, 'totalPages' => 1, 'resultsPerPage' => $size, 'data' => []];
        } elseif (isset($live['error'])) {
            http_response_code(502);
            echo json_encode($live, JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            $uids = [];
            foreach ($live['data'] ?? [] as &$item) {
                tmCacheSave($item);
                $item['_fetched_at'] = date('Y-m-d H:i:s'); // только что получено вживую
                if (!empty($item['object_uid'])) $uids[] = $item['object_uid'];
            }
            unset($item);
            $total = (int)($live['totalResult'] ?? count($uids));
            searchCacheSave($q, $page, $total, $uids);
            if ($page === 1) searchCacheSeedRelated($live['data'] ?? [], $total);
            $searchFetchedAt = date('Y-m-d H:i:s');
            $data = $live;
        }
    }
}

logSearch('api', $_SERVER['REMOTE_ADDR'] ?? null, $q, (int)($data['totalResult'] ?? 0));

// Роспатент отдаёт результаты по релевантности текстового совпадения, без
// учёта статуса — действующие и прекращённые вперемешку. Сортируем: сначала
// действующие, потом заявки на рассмотрении, прекращённые/истёкшие — в конец.
// Сортировка стабильная (PHP 8+), внутри каждой группы порядок по релевантности
// сохраняется как есть.
$results = array_map('formatTrademarkItem', $data['data'] ?? []);
usort($results, fn($a, $b) => statusSortRank($a['status']['code']) <=> statusSortRank($b['status']['code']));

$out = [
    'query'        => $q,
    'source'       => $source, // 'local' — нашлось среди обогащённых карточек; 'live' — фраза новая, только что получена вживую
    'notice'       => $notice, // непустое — показать пользователю предупреждение (напр. дневной лимит живого поиска исчерпан)
    'total'        => $data['totalResult'] ?? 0,
    'page'         => $data['currentPage'] ?? $page,
    'total_pages'  => $data['totalPages'] ?? 1,
    'per_page'     => $data['resultsPerPage'] ?? $size,
    'results'      => $results,
    'attribution'  => ROSPATENT_ATTRIBUTION,
    'search_fetched_at' => $searchFetchedAt, // null для поиска по номеру — там кнопка "Обновить" на самой карточке
];

ob_end_clean();
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
