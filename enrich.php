<?php
// CLI-скрипт: фоновый обход действующих регистраций (actual=1 в trademark_registry),
// у которых ещё нет карточки с картинкой/названием/МКТУ, и дозаполнение
// trademark_cache через живой поиск Роспатента (ws-proxy).
//
// Основной проход — по ПРАВООБЛАДАТЕЛЯМ, а не по номерам: один запрос по
// имени владельца отдаёт сразу до 50 его знаков (с пагинацией — больше),
// а не один за раз. У кого несколько регистраций — экономия кратная.
// Заодно так находятся и знаки без словесной части (чистая графика),
// которые обычным текстовым поиском по названию не найти вообще.
//
// Резервный проход — старый перебор по одному номеру (enrichFetchBatch),
// для тех, кого не удалось найти по имени владельца (расхождение в
// написании и т.п.) — включается, когда владельцев для обработки не осталось.
//
// Рассчитан на частый запуск через cron небольшими пачками — не держит
// PHP-процесс часами, сам укладывается в тайм-бюджет и завершается.
//
// Cron: * * * * * /path/to/php /path/to/enrich.php >> enrich.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Только из командной строки\n");
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/rospatent.php';

const HOLDER_BATCH_LIMIT     = 40;   // сколько владельцев максимум забрать за один запуск
const MAX_PAGES_PER_HOLDER   = 3;    // до 150 знаков на владельца за один прогон (пагинация)
const NUMBER_BATCH_LIMIT     = 300;  // резервный проход по номерам — сколько забрать
const TIME_BUDGET_S          = 50;   // укладываемся в минуту, оставляя запас
const DELAY_US               = 350000; // пауза между запросами — не долбим чужой недокументированный API
const MAX_CONSECUTIVE_ERRORS = 5;    // подряд идущие сетевые сбои — похоже, апстрим лёг

function out(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// Не даём крону запустить второй экземпляр поверх ещё не завершённого —
// иначе оба одновременно долбят ws-proxy/Роспатент и мешают друг другу.
$lockFile = sys_get_temp_dir() . '/znak_enrich.lock';
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    out('Предыдущий запуск ещё не завершился, выхожу.');
    exit(0);
}

$startedAt = microtime(true);
$done = 0;
$notFound = 0;
$upstreamErrors = 0;
$consecutiveErrors = 0;
$stoppedOnQuota = false;

// Свой резерв (ENRICH_DAILY_CAP из 100 в сутки) — отдельно от того, сколько
// всего живых запросов вообще осталось у Роспатента. Не даём фоновому
// обогащению съесть всю дневную квоту раньше пользователей.
$quotaUsed = enrichQuotaUsedToday();

function timeLeft(float $startedAt): bool
{
    return microtime(true) - $startedAt <= TIME_BUDGET_S;
}

function enrichQuotaLeft(int $quotaUsed): bool
{
    return $quotaUsed < ENRICH_DAILY_CAP;
}

// ── Фаза 1: по правообладателям ─────────────────────────────────────
$holders = enrichFetchHolderBatch(HOLDER_BATCH_LIMIT);
out('Владельцев в пачке: ' . count($holders));

foreach ($holders as $holderName) {
    if (!timeLeft($startedAt)) { out('Тайм-бюджет исчерпан.'); break; }
    if (!enrichQuotaLeft($quotaUsed)) {
        out('Свой дневной резерв (' . ENRICH_DAILY_CAP . ') исчерпан, оставляю остаток лимита пользователям.');
        $stoppedOnQuota = true;
        break;
    }

    $page = 1;
    while ($page <= MAX_PAGES_PER_HOLDER && timeLeft($startedAt)) {
        if (!enrichQuotaLeft($quotaUsed)) {
            out('Свой дневной резерв (' . ENRICH_DAILY_CAP . ') исчерпан, оставляю остаток лимита пользователям.');
            $stoppedOnQuota = true;
            break 2;
        }

        $data = rospatentSearchByOwner($holderName, $page, 50);
        $quotaUsed++;
        enrichQuotaIncrement();

        if (!empty($data['quota_exceeded'])) {
            out('Квота живого поиска исчерпана, останавливаюсь до завтра.');
            $stoppedOnQuota = true;
            break 2;
        }
        if (isset($data['error'])) {
            out("«{$holderName}» стр.{$page}: ошибка — {$data['error']}");
            $upstreamErrors++;
            $consecutiveErrors++;
            if ($consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
                out("Подряд {$consecutiveErrors} ошибок — апстрим недоступен, останавливаюсь до следующего прогона.");
                break 2;
            }
            usleep(DELAY_US);
            break; // пробуем следующего владельца, не зависаем на этом
        }
        $consecutiveErrors = 0;

        foreach ($data['data'] ?? [] as $item) {
            tmCacheSave($item);
            $done++;
        }

        $totalPages = (int)($data['totalPages'] ?? 1);
        if ($page >= $totalPages) break;
        $page++;
        usleep(DELAY_US);
    }

    // Что не нашлось по имени владельца (расхождение в написании, не хватило
    // страниц и т.п.) — в резерв на медленный перебор по номеру, с 7-дневным
    // откатом, чтобы не повторять то же самое на каждом прогоне.
    foreach (enrichUncachedRegNumbersForHolder($holderName) as $regNumber) {
        enrichMarkFailed($regNumber);
        $notFound++;
    }

    usleep(DELAY_US);
}

// ── Фаза 2: резервный перебор по одному номеру, если время ещё есть ──
$numberDone = 0;
$numberNotFound = 0;
if (!$stoppedOnQuota && timeLeft($startedAt)) {
    $numbers = enrichFetchBatch(NUMBER_BATCH_LIMIT);
    if ($numbers) out('Резерв — номеров в пачке: ' . count($numbers));

    foreach ($numbers as $regNumber) {
        if (!timeLeft($startedAt)) { out('Тайм-бюджет исчерпан (резерв).'); break; }
        if (!enrichQuotaLeft($quotaUsed)) {
            out('Свой дневной резерв (' . ENRICH_DAILY_CAP . ') исчерпан, оставляю остаток лимита пользователям.');
            break;
        }

        $data = rospatentSearch($regNumber, 1, 1);
        $quotaUsed++;
        enrichQuotaIncrement();

        if (!empty($data['quota_exceeded'])) {
            out('Квота живого поиска исчерпана, останавливаюсь до завтра.');
            break;
        }
        if (isset($data['error'])) {
            out("{$regNumber}: ошибка — {$data['error']}");
            $upstreamErrors++;
            $consecutiveErrors++;
            if ($consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
                out("Подряд {$consecutiveErrors} ошибок — апстрим недоступен, останавливаюсь.");
                break;
            }
            usleep(DELAY_US);
            continue;
        }
        $consecutiveErrors = 0;

        $item = null;
        foreach ($data['data'] ?? [] as $candidate) {
            if (($candidate['reg_number'] ?? null) === $regNumber) { $item = $candidate; break; }
        }

        if ($item) {
            tmCacheSave($item);
            enrichMarkDone($regNumber);
            $numberDone++;
        } else {
            enrichMarkFailed($regNumber);
            $numberNotFound++;
        }

        usleep(DELAY_US);
    }
}

$stats = enrichStats();
out("Готово: по владельцам обогащено {$done} карточек, ушло в резерв {$notFound}. " .
    "Резервный проход: обогащено {$numberDone}, не найдено {$numberNotFound}. Ошибок апстрима: {$upstreamErrors}. " .
    "Всего действующих: {$stats['active_total']}, обогащено: {$stats['enriched']}, " .
    "в очереди: {$stats['pending']}, не найдено: {$stats['failed']}.");

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
