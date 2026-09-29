<?php
// Приведение "сырой" карточки знака (от ws-proxy или из локального кэша)
// к единому виду для фронтенда. Используется и в search.php, и в refresh.php,
// чтобы кнопка "Обновить" отдавала карточку той же формы, что и обычный поиск.

function formatTrademarkItem(array $item): array
{
    return [
        'object_uid'    => $item['object_uid'] ?? null,
        'appl_number'   => $item['appl_number'] ?? null,
        'appl_date'     => shortDate($item['appl_date'] ?? null),
        'reg_number'    => $item['reg_number'] ?? null,
        'reg_date'      => shortDate($item['reg_date'] ?? null),
        'expiry_date'   => shortDate($item['expiry_date'] ?? null),
        'status'        => tmStatus($item),
        'kind'          => $item['tmk_kind'] ?? '',
        'name'          => $item['mark_description_text'] ?? null,
        'holders'       => $item['holders'] ?? null,
        'corr_address'  => $item['corr_address'] ?? null,
        'goods_classes' => $item['goods_classes'] ?? null,
        'goods'         => $item['goods'] ?? null,
        'image_url'     => $item['files'][0]['file_url'] ?? null,
        'colour'        => $item['mark_image_colour'] ?? null,
        'registry_url'  => $item['open_registry_url'] ?: ($item['appl_registry_url'] ?? null),
        'fetched_at'    => $item['_fetched_at'] ?? null, // когда карточка последний раз обновлялась живыми данными
    ];
}

// Статус для бейджа 🟢/🔴/🟡 на карточке. Если запись пришла из своего
// юр. реестра (CSV) — там есть точный флаг actual, используем его.
// Для записей из живого поиска Роспатента такого поля нет — судим по
// наличию регистрации и дате истечения срока охраны.
function tmStatus(array $item): array
{
    if (empty($item['reg_number'])) {
        return ['code' => 'pending', 'label' => 'Заявка на рассмотрении', 'icon' => '🟡'];
    }
    if (array_key_exists('actual', $item)) {
        return $item['actual']
            ? ['code' => 'active', 'label' => 'Действует', 'icon' => '🟢']
            : ['code' => 'expired', 'label' => 'Прекращено / истёк срок', 'icon' => '🔴'];
    }
    $expiry = $item['expiry_date'] ?? null;
    if ($expiry && strtotime($expiry) < time()) {
        return ['code' => 'expired', 'label' => 'Истёк срок действия', 'icon' => '🔴'];
    }
    return ['code' => 'active', 'label' => 'Действует', 'icon' => '🟢'];
}

function shortDate(?string $v): ?string
{
    if (!$v) return null;
    return substr($v, 0, 10);
}

function statusSortRank(string $code): int
{
    return match ($code) {
        'active'  => 0,
        'pending' => 1,
        'expired' => 2,
        default   => 3,
    };
}
