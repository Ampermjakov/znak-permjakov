<?php
// CLI-скрипт: раз в сутки/месяц скачивает открытый CSV-реестр товарных знаков
// Роспатента целиком и заливает в trademark_registry (юридические поля —
// номер, даты, статус, правообладатель; без картинки/названия/МКТУ — их
// в этом датасете нет, см. enrich.php).
//
// Запуск: php import.php
// Cron:   0 4 * * * /path/to/php /path/to/import.php >> import.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Только из командной строки\n");
}

require_once __DIR__ . '/lib/db.php';

const CATALOG_URL = 'https://rospatent.gov.ru/opendata/7730176088-tz';
const BATCH_SIZE   = 2000;

function out(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

$pdo = dbConnect();
if (!$pdo) {
    out('Нет соединения с БД, прерываю.');
    exit(1);
}

// ── 1. Находим актуальную ссылку на CSV ──────────────────────────────
// rospatent.gov.ru не досылает промежуточный сертификат в цепочке (недонастройка
// на их стороне — SSL verify: "unable to verify the first certificate"), из-за чего
// строгая проверка обрывает соединение ещё до ответа. Данные публичные, открытые,
// без каких-либо секретов ни на вход, ни на выход — отключаем проверку для этого
// конкретного хоста, как и в checker.php у chek.permjakov.ru для внешних запросов.
out('Ищу актуальную ссылку на CSV на ' . CATALOG_URL);
$html = curlGet(CATALOG_URL, 20);
if (!$html || !preg_match('~href="(https://rospatent\.gov\.ru/opendata/7730176088-tz/data-\d{8}-structure-\d{8}\.csv)"~', $html, $m)) {
    out('Не удалось найти ссылку на CSV в каталоге открытых данных.');
    exit(1);
}
$csvUrl = $m[1];
out('Файл: ' . $csvUrl);

// ── 2. Скачиваем во временный файл (стримом, не в память) ────────────
$tmpFile = sys_get_temp_dir() . '/rospatent_tm_' . date('Ymd') . '.csv';
if (!file_exists($tmpFile) || filesize($tmpFile) < 1000000) {
    out('Скачиваю в ' . $tmpFile . ' ...');
    $fp = fopen($tmpFile, 'w');
    $ch = curl_init($csvUrl);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_TIMEOUT => 3600,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    fclose($fp);
    if (!$ok) {
        out('Ошибка скачивания: ' . $err);
        @unlink($tmpFile);
        exit(1);
    }
    out('Скачано: ' . round(filesize($tmpFile) / 1048576, 1) . ' МБ');
} else {
    out('Файл уже скачан сегодня, использую его: ' . $tmpFile);
}

// ── 3. Стримовый парсинг + пакетный upsert ───────────────────────────
$logId = startImportLog($csvUrl);

$handle = fopen($tmpFile, 'r');
if (!$handle) {
    out('Не удалось открыть файл для чтения.');
    exit(1);
}

// Файл в UTF-8 с BOM — снимаем BOM с первой строки перед разбором заголовка.
$firstLine = fgets($handle);
$firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
$header = str_getcsv($firstLine);
if (!$header || !in_array('registration number', $header, true)) {
    out('Не удалось разобрать заголовок CSV — формат датасета изменился?');
    finishImportLog($logId, 0, 0, 0, 0, 'failed');
    exit(1);
}
$columnCount = count($header);

$stmt = registryUpsertStatement($pdo);

$total = 0;
$errors = 0;
$batchCount = 0;
$pdo->beginTransaction();

while (($row = fgetcsv($handle)) !== false) {
    $total++;

    if (count($row) !== $columnCount) {
        // Кривая строка (перенос строки внутри поля не по стандарту и т.п.) — пропускаем
        $errors++;
        continue;
    }

    $assoc = array_combine($header, $row);
    if (empty($assoc['registration number'])) {
        $errors++;
        continue;
    }

    try {
        registryUpsertRow($pdo, $assoc, $stmt);
    } catch (Throwable $e) {
        $errors++;
    }

    $batchCount++;
    if ($batchCount >= BATCH_SIZE) {
        $pdo->commit();
        $pdo->beginTransaction();
        $batchCount = 0;
        if ($total % 50000 === 0) out("Обработано {$total} строк...");
    }
}

if ($batchCount > 0) $pdo->commit();
else $pdo->rollBack();

fclose($handle);
@unlink($tmpFile);

out("Готово. Всего строк: {$total}, ошибок: {$errors}.");
finishImportLog($logId, $total - $errors, 0, $errors, $total, 'ok');

function curlGet(string $url, int $timeout): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $html = curl_exec($ch);
    return $html !== false ? $html : null;
}
