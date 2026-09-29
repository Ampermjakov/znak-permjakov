<?php
require_once __DIR__ . '/../config.php';

// У живого поиска Роспатента жёсткий лимит — 100 запросов в сутки на источник.
// При его превышении сервер возвращает это сообщение текстом; ретраить в этом
// случае бессмысленно (только тратит ещё одну попытку впустую).
function isQuotaExceededMessage(string $msg): bool
{
    return stripos($msg, 'превысили') !== false || stripos($msg, 'запросов в сутки') !== false;
}

// Дёргает ws-proxy (Node, держит Socket.IO-сессию к searchplatform.rospatent.gov.ru).
// Один ретрай при сетевых сбоях — первый запрос после простоя proxy иногда падает
// с "websocket error" (переподключение сокета), второй почти всегда проходит.
function rospatentSearch(string $query, int $page = 1, int $size = 20, ?string $attr = null): array
{
    $params = [
        'q'     => $query,
        'page'  => $page,
        'size'  => $size,
        'token' => ROSPATENT_PROXY_TOKEN,
    ];
    if ($attr) $params['attr'] = $attr;
    $url = ROSPATENT_PROXY_URL . '/search?' . http_build_query($params);

    $data = rospatentFetch($url);

    // ws-proxy оборачивает реальное сообщение Роспатента в поле detail —
    // error у него всегда просто литерал "upstream_error".
    $rawError = ($data['detail'] ?? null) ?: ($data['error'] ?? null);
    $quotaExceeded = $rawError && isQuotaExceededMessage($rawError);

    if (($data === null || (($data['error'] ?? null) && !$quotaExceeded))) {
        $data = rospatentFetch($url); // один повтор, но не при исчерпанной квоте
        $rawError = ($data['detail'] ?? null) ?: ($data['error'] ?? null);
        $quotaExceeded = $rawError && isQuotaExceededMessage($rawError);
    }

    if ($data === null) {
        return ['error' => 'Поисковый сервис Роспатента недоступен, попробуйте позже'];
    }
    if ($quotaExceeded) {
        return [
            'error' => 'Дневной лимит бесплатного живого поиска Роспатента (100 запросов) исчерпан. Попробуйте завтра.',
            'quota_exceeded' => true,
        ];
    }
    if (isset($data['error'])) {
        return ['error' => 'Ошибка поиска: ' . $data['error']];
    }
    return $data;
}

// Точный поиск по правообладателю (без нечёткости, без риска подсунуть
// однофамильца) — один запрос отдаёт сразу все знаки этого владельца
// (до 50 за раз), а не по одному. Используется enrich.php для массового
// обогащения — на порядок быстрее, чем перебор по одному номеру.
function rospatentSearchByOwner(string $holderName, int $page = 1, int $size = 50): array
{
    return rospatentSearch($holderName, $page, $size, 'owners.name');
}

function rospatentFetch(string $url): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);

    if ($raw === false || $err) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
