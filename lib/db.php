<?php
require_once __DIR__ . '/../config.php';

function dbConnect(): ?PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, PDO::ATTR_TIMEOUT => 3]
        );
        // UTC для сессии — иначе NOW()/TIMESTAMPDIFF() в MySQL считают по системной
        // таймзоне сервера БД, а fetched_at из search.php/refresh.php пишется в PHP-UTC:
        // расхождение ломает счётчик cooldown у кнопки "Обновить".
        $pdo->exec("SET time_zone = '+00:00'");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `trademark_cache` (
            `object_uid`             CHAR(36)     NOT NULL PRIMARY KEY,
            `ois_uid`                CHAR(36)     NULL,
            `appl_number`            VARCHAR(20)  NOT NULL DEFAULT '',
            `appl_date`              DATE         NULL,
            `reg_number`             VARCHAR(20)  NULL,
            `reg_date`               DATE         NULL,
            `reg_publ_date`          DATE         NULL,
            `expiry_date`            DATE         NULL,
            `effective_date`         DATE         NULL,
            `status_code`            VARCHAR(10)  NULL,
            `tmk_kind`               VARCHAR(20)  NOT NULL DEFAULT '',
            `trademark_kind`         TINYINT      NULL,
            `holders`                TEXT         NULL,
            `corr_address`           TEXT         NULL,
            `mark_description_text`  VARCHAR(500) NULL,
            `mark_image_colour`      VARCHAR(255) NULL,
            `goods`                  LONGTEXT     NULL,
            `goods_classes`          VARCHAR(255) NULL,
            `open_registry_url`      VARCHAR(500) NULL,
            `appl_registry_url`      VARCHAR(500) NULL,
            `image_url`              VARCHAR(500) NULL,
            `image_local_path`       VARCHAR(255) NULL,
            `raw_json`               JSON         NULL,
            `fetched_at`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_appl_number` (`appl_number`),
            KEY `idx_reg_number` (`reg_number`),
            FULLTEXT KEY `ft_search` (`holders`, `mark_description_text`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `trademark_classes` (
            `object_uid` CHAR(36)         NOT NULL,
            `mktu_class` TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY (`object_uid`, `mktu_class`),
            KEY `idx_class` (`mktu_class`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Кэш результата конкретного текстового запроса (что реально пришло
        // от живого поиска за один вызов) — см. searchCacheGet/searchCacheSave.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `search_cache` (
            `query_key`   VARCHAR(300) NOT NULL PRIMARY KEY,
            `total`       INT UNSIGNED NOT NULL DEFAULT 0,
            `object_uids` JSON         NOT NULL,
            `is_seeded`   TINYINT(1)   NOT NULL DEFAULT 0,
            `fetched_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `search_log` (
            `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `source`          ENUM('web','telegram','max','api') NOT NULL,
            `user_identifier` VARCHAR(64)  NULL,
            `query_text`      VARCHAR(255) NOT NULL,
            `results_count`   INT          NULL,
            `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Полный юридический реестр из открытого CSV Роспатента (import.php).
        // Картинки/названия/МКТУ тут нет — это только "легальные" поля.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `trademark_registry` (
            `registration_number`   VARCHAR(20)  NOT NULL PRIMARY KEY,
            `registration_date`     DATE         NULL,
            `application_number`    VARCHAR(20)  NULL,
            `application_date`      DATE         NULL,
            `priority_date`         DATE         NULL,
            `expiration_date`       DATE         NULL,
            `right_holder_name`     TEXT         NULL,
            `right_holder_address`  TEXT         NULL,
            `right_holder_country_code` VARCHAR(5) NULL,
            `right_holder_ogrn`     VARCHAR(20)  NULL,
            `right_holder_inn`      VARCHAR(20)  NULL,
            `correspondence_address` TEXT        NULL,
            `collective`            TINYINT(1)   NOT NULL DEFAULT 0,
            `unprotected_elements`  TEXT         NULL,
            `is_3d`                 TINYINT(1)   NOT NULL DEFAULT 0,
            `is_holographic`        TINYINT(1)   NOT NULL DEFAULT 0,
            `is_sound`              TINYINT(1)   NOT NULL DEFAULT 0,
            `is_olfactory`          TINYINT(1)   NOT NULL DEFAULT 0,
            `is_color`              TINYINT(1)   NOT NULL DEFAULT 0,
            `is_light`              TINYINT(1)   NOT NULL DEFAULT 0,
            `is_changing`           TINYINT(1)   NOT NULL DEFAULT 0,
            `is_positional`         TINYINT(1)   NOT NULL DEFAULT 0,
            `actual`                TINYINT(1)   NOT NULL DEFAULT 0,
            `publication_url`       VARCHAR(500) NULL,
            `raw_row`               JSON         NULL,
            `enrich_status`         ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
            `enrich_attempted_at`   DATETIME     NULL,
            `imported_at`           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_application_number` (`application_number`),
            KEY `idx_actual` (`actual`),
            KEY `idx_enrich` (`actual`, `enrich_status`),
            FULLTEXT KEY `ft_holder` (`right_holder_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Собственный дневной счётчик enrich.php (см. ENRICH_DAILY_CAP в config.php) —
        // отдельно от того, сколько живых запросов Роспатент реально позволяет всему сайту.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `enrich_quota` (
            `usage_date`    DATE NOT NULL PRIMARY KEY,
            `requests_used` INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `import_log` (
            `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `started_at`  DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            `source_url`  VARCHAR(500) NULL,
            `inserted`    INT NOT NULL DEFAULT 0,
            `updated`     INT NOT NULL DEFAULT 0,
            `errors`      INT NOT NULL DEFAULT 0,
            `total_rows`  INT NOT NULL DEFAULT 0,
            `status`      ENUM('running','ok','failed') NOT NULL DEFAULT 'running'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Авторизация — нужна только чтобы дать смысл кнопке "Обновить"
        // на карточке знака (не даём анониму долбить живой поиск).
        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email`         VARCHAR(255) NOT NULL UNIQUE,
            `password`      VARCHAR(255) NOT NULL,
            `verified`      TINYINT(1)   NOT NULL DEFAULT 0,
            `verify_token`  VARCHAR(64)  NULL,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_login_at` DATETIME     NULL,
            `is_blocked`    TINYINT(1)   NOT NULL DEFAULT 0,
            `is_admin`      TINYINT(1)   NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `sessions` (
            `token`      VARCHAR(64)  NOT NULL PRIMARY KEY,
            `user_id`    INT UNSIGNED NOT NULL,
            `expires_at` DATETIME     NOT NULL,
            `ip`         VARCHAR(45)  NOT NULL DEFAULT '',
            `ua`         TEXT         NOT NULL,
            INDEX `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    } catch (Exception $e) {
        $pdo = null;
    }
    return $pdo;
}

// Залогиненный пользователь по куке сессии — используется и в auth.php,
// и в refresh.php (кнопка "Обновить" требует авторизации).
function authCurrentUser(): ?array
{
    $token = $_COOKIE['znak_session'] ?? '';
    if (!$token) return null;
    $pdo = dbConnect();
    if (!$pdo) return null;
    $st = $pdo->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
        WHERE s.token = ? AND s.expires_at > NOW()');
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Сохраняет одну карточку знака (как её вернул ws-proxy) в кэш + классы МКТУ
function tmCacheSave(array $item): void
{
    $pdo = dbConnect();
    if (!$pdo || empty($item['object_uid'])) return;

    $image = $item['files'][0]['file_url'] ?? null;

    $st = $pdo->prepare('INSERT INTO trademark_cache
        (object_uid, ois_uid, appl_number, appl_date, reg_number, reg_date, reg_publ_date,
         expiry_date, effective_date, status_code, tmk_kind, trademark_kind, holders,
         corr_address, mark_description_text, mark_image_colour, goods, goods_classes,
         open_registry_url, appl_registry_url, image_url, raw_json, fetched_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())
        ON DUPLICATE KEY UPDATE
            ois_uid=VALUES(ois_uid), appl_number=VALUES(appl_number), appl_date=VALUES(appl_date),
            reg_number=VALUES(reg_number), reg_date=VALUES(reg_date), reg_publ_date=VALUES(reg_publ_date),
            expiry_date=VALUES(expiry_date), effective_date=VALUES(effective_date),
            status_code=VALUES(status_code), tmk_kind=VALUES(tmk_kind), trademark_kind=VALUES(trademark_kind),
            holders=VALUES(holders), corr_address=VALUES(corr_address),
            mark_description_text=VALUES(mark_description_text), mark_image_colour=VALUES(mark_image_colour),
            goods=VALUES(goods), goods_classes=VALUES(goods_classes),
            open_registry_url=VALUES(open_registry_url), appl_registry_url=VALUES(appl_registry_url),
            image_url=VALUES(image_url), raw_json=VALUES(raw_json), fetched_at=NOW()');

    $st->execute([
        $item['object_uid'], $item['ois_uid'] ?? null,
        $item['appl_number'] ?? '', normalizeDateOnly($item['appl_date'] ?? null),
        $item['reg_number'] ?? null, normalizeDateOnly($item['reg_date'] ?? null),
        normalizeDateOnly($item['reg_publ_date'] ?? null),
        normalizeDateOnly($item['expiry_date'] ?? null), normalizeDateOnly($item['effective_date'] ?? null),
        $item['status_code'] ?? null, $item['tmk_kind'] ?? '', $item['trademarkKind'] ?? null,
        $item['holders'] ?? null, $item['corr_address'] ?? null,
        $item['mark_description_text'] ?? null, $item['mark_image_colour'] ?? null,
        $item['goods'] ?? null, $item['goods_classes'] ?? null,
        $item['open_registry_url'] ?? null, $item['appl_registry_url'] ?? null,
        $image, json_encode($item, JSON_UNESCAPED_UNICODE),
    ]);

    $classes = $item['goodClasses'] ?? [];
    if ($classes) {
        $pdo->prepare('DELETE FROM trademark_classes WHERE object_uid = ?')->execute([$item['object_uid']]);
        $ins = $pdo->prepare('INSERT IGNORE INTO trademark_classes (object_uid, mktu_class) VALUES (?, ?)');
        foreach ($classes as $c) {
            $n = (int)$c;
            if ($n > 0) $ins->execute([$item['object_uid'], $n]);
        }
    }
}

function normalizeDateOnly(?string $v): ?string
{
    if (!$v) return null;
    return substr($v, 0, 10); // "2024-02-06T00:00:00" -> "2024-02-06"
}

function logSearch(string $source, ?string $userIdentifier, string $query, ?int $resultsCount): void
{
    $pdo = dbConnect();
    if (!$pdo) return;
    $pdo->prepare('INSERT INTO search_log (source, user_identifier, query_text, results_count) VALUES (?,?,?,?)')
        ->execute([$source, $userIdentifier, mb_substr($query, 0, 255), $resultsCount]);
}

// ── import.php ───────────────────────────────────────────────────────

// $row — ассоциативный массив с ключами-названиями полей CSV Роспатента
// (как они есть в заголовке: "registration number", "right holder name", ...)
function registryUpsertRow(PDO $pdo, array $row, PDOStatement $stmt): void
{
    $stmt->execute([
        $row['registration number'] ?? '',
        csvDate($row['registration date'] ?? null),
        $row['application number'] ?? null,
        csvDate($row['application date'] ?? null),
        csvDate($row['priority date'] ?? null),
        csvDate($row['expiration date'] ?? null),
        $row['right holder name'] ?? null,
        $row['right holder address'] ?? null,
        $row['right holder country code'] ?? null,
        $row['right holder ogrn'] ?? null,
        $row['right holder inn'] ?? null,
        $row['correspondence address'] ?? null,
        csvBool($row['collective'] ?? null),
        $row['unprotected elements'] ?? null,
        csvBool($row['threedimensional'] ?? null),
        csvBool($row['holographic'] ?? null),
        csvBool($row['sound'] ?? null),
        csvBool($row['olfactory'] ?? null),
        csvBool($row['color'] ?? null),
        csvBool($row['light'] ?? null),
        csvBool($row['changing'] ?? null),
        csvBool($row['positional'] ?? null),
        csvBool($row['actual'] ?? null),
        $row['publication URL'] ?? null,
        json_encode($row, JSON_UNESCAPED_UNICODE),
    ]);
}

function registryUpsertStatement(PDO $pdo): PDOStatement
{
    return $pdo->prepare('INSERT INTO trademark_registry
        (registration_number, registration_date, application_number, application_date,
         priority_date, expiration_date, right_holder_name, right_holder_address,
         right_holder_country_code, right_holder_ogrn, right_holder_inn, correspondence_address,
         collective, unprotected_elements, is_3d, is_holographic, is_sound, is_olfactory,
         is_color, is_light, is_changing, is_positional, actual, publication_url, raw_row)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            registration_date=VALUES(registration_date), application_number=VALUES(application_number),
            application_date=VALUES(application_date), priority_date=VALUES(priority_date),
            expiration_date=VALUES(expiration_date), right_holder_name=VALUES(right_holder_name),
            right_holder_address=VALUES(right_holder_address), right_holder_country_code=VALUES(right_holder_country_code),
            right_holder_ogrn=VALUES(right_holder_ogrn), right_holder_inn=VALUES(right_holder_inn),
            correspondence_address=VALUES(correspondence_address), collective=VALUES(collective),
            unprotected_elements=VALUES(unprotected_elements), is_3d=VALUES(is_3d),
            is_holographic=VALUES(is_holographic), is_sound=VALUES(is_sound), is_olfactory=VALUES(is_olfactory),
            is_color=VALUES(is_color), is_light=VALUES(is_light), is_changing=VALUES(is_changing),
            is_positional=VALUES(is_positional), actual=VALUES(actual), publication_url=VALUES(publication_url),
            raw_row=VALUES(raw_row)');
}

function csvDate(?string $v): ?string
{
    $v = trim((string)$v);
    if (!preg_match('/^\d{8}$/', $v)) return null;
    return substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2);
}

function csvBool(?string $v): int
{
    return strtolower(trim((string)$v)) === 'true' ? 1 : 0;
}

function startImportLog(string $sourceUrl): int
{
    $pdo = dbConnect();
    if (!$pdo) return 0;
    $pdo->prepare('INSERT INTO import_log (started_at, source_url, status) VALUES (NOW(), ?, ?)')
        ->execute([$sourceUrl, 'running']);
    return (int)$pdo->lastInsertId();
}

function finishImportLog(int $id, int $inserted, int $updated, int $errors, int $total, string $status): void
{
    $pdo = dbConnect();
    if (!$pdo || !$id) return;
    $pdo->prepare('UPDATE import_log SET finished_at=NOW(), inserted=?, updated=?, errors=?, total_rows=?, status=? WHERE id=?')
        ->execute([$inserted, $updated, $errors, $total, $status, $id]);
}

// ── enrich.php ───────────────────────────────────────────────────────

// Следующая пачка действующих регистраций, для которых ещё нет картинки/названия/МКТУ
function enrichFetchBatch(int $limit): array
{
    $pdo = dbConnect();
    if (!$pdo) return [];
    $st = $pdo->prepare("SELECT r.registration_number
        FROM trademark_registry r
        LEFT JOIN trademark_cache c ON c.reg_number = r.registration_number
        WHERE r.actual = 1
          AND c.object_uid IS NULL
          AND (r.enrich_status = 'pending' OR (r.enrich_status = 'failed' AND r.enrich_attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)))
        LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function enrichMarkDone(string $regNumber): void
{
    $pdo = dbConnect();
    if (!$pdo) return;
    $pdo->prepare("UPDATE trademark_registry SET enrich_status='done', enrich_attempted_at=NOW() WHERE registration_number=?")
        ->execute([$regNumber]);
}

function enrichMarkFailed(string $regNumber): void
{
    $pdo = dbConnect();
    if (!$pdo) return;
    $pdo->prepare("UPDATE trademark_registry SET enrich_status='failed', enrich_attempted_at=NOW() WHERE registration_number=?")
        ->execute([$regNumber]);
}

// Пачка правообладателей (не номеров!), у которых ещё есть необогащённые
// действующие регистрации. Один запрос по имени владельца отдаёт сразу
// до 50 его знаков — на порядок быстрее, чем перебор по одному номеру,
// и заодно находит знаки без словесной части (те, что не отыскать текстовым
// поиском по названию, а по владельцу — можно).
function enrichFetchHolderBatch(int $limit): array
{
    $pdo = dbConnect();
    if (!$pdo) return [];
    $st = $pdo->prepare("SELECT DISTINCT r.right_holder_name
        FROM trademark_registry r
        LEFT JOIN trademark_cache c ON c.reg_number = r.registration_number
        WHERE r.actual = 1
          AND c.object_uid IS NULL
          AND r.right_holder_name IS NOT NULL AND r.right_holder_name != ''
          AND (r.enrich_status = 'pending' OR (r.enrich_status = 'failed' AND r.enrich_attempted_at < DATE_SUB(NOW(), INTERVAL 7 DAY)))
        LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// После обработки владельца — какие его действующие регистрации так и
// остались без карточки (не хватило страниц пагинации, расхождение в
// написании имени и т.п.). Их помечаем 'failed' — уйдут в медленный
// перебор по одному номеру (enrichFetchBatch) с 7-дневным откатом.
function enrichUncachedRegNumbersForHolder(string $holderName): array
{
    $pdo = dbConnect();
    if (!$pdo) return [];
    $st = $pdo->prepare("SELECT r.registration_number
        FROM trademark_registry r
        LEFT JOIN trademark_cache c ON c.reg_number = r.registration_number
        WHERE r.actual = 1 AND r.right_holder_name = ? AND c.object_uid IS NULL");
    $st->execute([$holderName]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

// "Обогащено" — по факту наличия карточки в trademark_cache, а не по enrich_status.
// Основной проход (по владельцам, enrichFetchHolderBatch) карточки добавляет,
// но enrich_status на самих записях реестра не проставляет — это делает только
// резервный проход по одному номеру (enrichMarkDone/enrichMarkFailed). Если
// считать прогресс по enrich_status — цифра будет застревать, хотя реально
// база пополняется. Наличие строки в trademark_cache — источник истины.
function enrichStats(): array
{
    $pdo = dbConnect();
    if (!$pdo) return [];
    $st = $pdo->query("SELECT
        SUM(r.actual=1) AS active_total,
        SUM(r.actual=1 AND c.object_uid IS NOT NULL) AS enriched,
        SUM(r.actual=1 AND c.object_uid IS NULL AND r.enrich_status='pending') AS pending,
        SUM(r.actual=1 AND c.object_uid IS NULL AND r.enrich_status='failed') AS failed
        FROM trademark_registry r
        LEFT JOIN trademark_cache c ON c.reg_number = r.registration_number");
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Сколько живых запросов enrich.php уже сделал сегодня — свой собственный
// резерв (ENRICH_DAILY_CAP из 100 в сутки), отдельно от того, сколько живых
// запросов вообще было потрачено на весь сайт. Цель — не дать фоновому
// обогащению съесть всю дневную квоту раньше, чем ей воспользуются
// пользователи (см. ENRICH_DAILY_CAP в config.php).
function enrichQuotaUsedToday(): int
{
    $pdo = dbConnect();
    if (!$pdo) return 0;
    $st = $pdo->query('SELECT requests_used FROM enrich_quota WHERE usage_date = CURDATE()');
    return (int)$st->fetchColumn();
}

function enrichQuotaIncrement(): void
{
    $pdo = dbConnect();
    if (!$pdo) return;
    $pdo->exec('INSERT INTO enrich_quota (usage_date, requests_used) VALUES (CURDATE(), 1)
        ON DUPLICATE KEY UPDATE requests_used = requests_used + 1');
}

// ── search.php: локальный поиск перед живым запросом ───────────────────

// Точный номер (регистрации ИЛИ заявки) — сперва смотрим в своей БД.
function findLocalByNumber(string $number): ?array
{
    $pdo = dbConnect();
    if (!$pdo) return null;

    $st = $pdo->prepare('SELECT * FROM trademark_cache WHERE reg_number = ? OR appl_number = ? LIMIT 1');
    $st->execute([$number, $number]);
    $cached = $st->fetch(PDO::FETCH_ASSOC);
    if ($cached) return cacheRowToItem($cached);

    // Есть в реестре (юр. данные из CSV), но карточка ещё не обогащена картинкой —
    // отдаём то, что есть, без похода в живой поиск (это сделает enrich.php фоном).
    $st = $pdo->prepare('SELECT * FROM trademark_registry WHERE registration_number = ? LIMIT 1');
    $st->execute([$number]);
    $reg = $st->fetch(PDO::FETCH_ASSOC);
    if ($reg) return registryRowToItem($reg);

    return null;
}

// ИНН правообладателя (10 цифр — юрлицо, 12 — ИП/физлицо) — есть в бесплатном
// CSV-реестре у каждой записи, точный уникальный идентификатор организации.
// В отличие от текстового поиска по названию владельца (нечёткий, матчит
// по отдельным словам — см. "ВУДКРАФТ-УРАЛ" находил и "ЛИТА-Аудит" заодно),
// здесь совпадение либо есть, либо нет. Целиком из своей базы, живой поиск
// не нужен вообще — все марки этой компании отдаём сразу.
function findLocalByInn(string $inn): array
{
    $pdo = dbConnect();
    if (!$pdo) return [];

    $st = $pdo->prepare('SELECT registration_number FROM trademark_registry
        WHERE right_holder_inn = ? ORDER BY actual DESC, registration_date DESC');
    $st->execute([$inn]);
    $regNumbers = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$regNumbers) return [];

    $placeholders = implode(',', array_fill(0, count($regNumbers), '?'));

    $st = $pdo->prepare("SELECT * FROM trademark_cache WHERE reg_number IN ({$placeholders})");
    $st->execute($regNumbers);
    $cacheByRegNumber = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cacheByRegNumber[$row['reg_number']] = cacheRowToItem($row);
    }

    $st = $pdo->prepare("SELECT * FROM trademark_registry WHERE registration_number IN ({$placeholders})");
    $st->execute($regNumbers);
    $registryByRegNumber = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $registryByRegNumber[$row['registration_number']] = registryRowToItem($row);
    }

    $items = [];
    foreach ($regNumbers as $regNumber) {
        $items[] = $cacheByRegNumber[$regNumber] ?? $registryByRegNumber[$regNumber] ?? null;
    }
    return array_values(array_filter($items));
}

// Текстовый поиск (название/владелец) — кэшируем не отдельные карточки,
// а РЕЗУЛЬТАТ КОНКРЕТНОГО ЗАПРОСА целиком (какие именно object_uid пришли
// за один живой вызов, в каком порядке, и сколько всего Роспатент насчитал).
//
// Так и должно быть: у Роспатента поиск "похожих" — это не текстовое
// совпадение куска слова, а какая-то своя логика похожести (визуальная/
// фонетическая), которую нашими средствами не воспроизвести. Пытаться найти
// "похожие" перебором по кэшу всех когда-либо обогащённых карточек не
// работает — например, MONTEX/BONLEX не содержат друг друга как подстроку,
// FULLTEXT их не свяжет. Поэтому просто запоминаем: "по фразе X мы в прошлый
// раз получили от Роспатента вот эти N карточек" — и при точно таком же
// запросе (от кого угодно) отдаём то же самое, без повторного похода вживую.
//
// Ограничение: один живой запрос отдаёт максимум 50 карточек (страница),
// даже если всего у Роспатента совпадений в сотни раз больше — это и есть
// то, что мы вообще в состоянии показать (топ по релевантности), угнаться
// за полной глубиной их поиска при лимите 100 запросов/сутки нереально.
function searchCacheGet(string $query, int $page): ?array
{
    $pdo = dbConnect();
    if (!$pdo) return null;

    $st = $pdo->prepare('SELECT * FROM search_cache WHERE query_key = ?');
    $st->execute([searchCacheKey($query, $page)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    $uids = json_decode($row['object_uids'] ?? '[]', true) ?: [];
    if (!$uids) return ['total' => (int)$row['total'], 'items' => [], 'fetched_at' => $row['fetched_at']];

    $placeholders = implode(',', array_fill(0, count($uids), '?'));
    $st = $pdo->prepare("SELECT * FROM trademark_cache WHERE object_uid IN ({$placeholders})");
    $st->execute($uids);
    $byUid = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byUid[$r['object_uid']] = cacheRowToItem($r);
    }

    // сохраняем исходный порядок релевантности, в котором Роспатент их вернул
    $items = [];
    foreach ($uids as $uid) {
        if (isset($byUid[$uid])) $items[] = $byUid[$uid];
    }

    return ['total' => (int)$row['total'], 'items' => $items, 'fetched_at' => $row['fetched_at']];
}

// $isSeeded=false — настоящий прямой поиск этой фразы (search.php/refresh_search.php).
// $isSeeded=true  — "перепавшая" запись от searchCacheSeedRelated (см. ниже):
// никогда не перезаписывает уже существующий настоящий прямой поиск (is_seeded=0),
// только другую такую же приближённую запись или создаёт новую.
function searchCacheSave(string $query, int $page, int $total, array $objectUids, bool $isSeeded = false): void
{
    $pdo = dbConnect();
    if (!$pdo) return;
    $pdo->prepare('INSERT INTO search_cache (query_key, total, object_uids, is_seeded, fetched_at) VALUES (?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            total       = IF(is_seeded = 1, VALUES(total), total),
            object_uids = IF(is_seeded = 1, VALUES(object_uids), object_uids),
            fetched_at  = IF(is_seeded = 1, VALUES(fetched_at), fetched_at),
            is_seeded   = IF(is_seeded = 1, VALUES(is_seeded), is_seeded)')
        ->execute([searchCacheKey($query, $page), $total, json_encode(array_values($objectUids)), $isSeeded ? 1 : 0]);
}

// Один живой поиск обычно возвращает не только карточку исходного запроса,
// а десятки других настоящих слов знаков (см. пример MONLEX → в выдаче есть
// и BONLEX, и WONLEX, и т.д. — это реальные знаки, не мусор). Раз уж мы их
// всё равно получили — сразу же кладём в search_cache результат и под ИХ
// словами тоже, тем же списком, но с этим словом на первом месте (без дублей).
// Так один живой запрос закрывает не только исходную фразу, а сразу пачку
// вероятных будущих поисков. Это приближение (настоящий прямой поиск "BONLEX"
// мог бы вернуть чуть другой список) — но того же уровня честности, что и уже
// принятый масштаб сервиса "топ-50 релевантных, не исчерпывающе".
function searchCacheSeedRelated(array $liveItems, int $total): void
{
    $orderedUids = [];
    $uidByWord   = [];
    foreach ($liveItems as $it) {
        $uid = $it['object_uid'] ?? null;
        if (!$uid) continue;
        $orderedUids[] = $uid;
        $word = trim((string)($it['mark_description_text'] ?? ''));
        if ($word !== '') $uidByWord[$word] = $uid;
    }
    if (count($orderedUids) < 2) return;

    foreach ($uidByWord as $word => $uid) {
        $reordered = array_values(array_filter($orderedUids, fn($u) => $u !== $uid));
        array_unshift($reordered, $uid);
        searchCacheSave($word, 1, $total, $reordered, true);
    }
}

function searchCacheKey(string $query, int $page): string
{
    $norm = mb_strtolower(preg_replace('/\s+/u', ' ', trim($query)));
    return $norm . '|p' . $page;
}

function cacheRowToItem(array $row): array
{
    $item = json_decode($row['raw_json'] ?? '', true);
    if (!is_array($item)) {
        $item = [
            'object_uid' => $row['object_uid'], 'appl_number' => $row['appl_number'], 'appl_date' => $row['appl_date'],
            'reg_number' => $row['reg_number'], 'reg_date' => $row['reg_date'], 'expiry_date' => $row['expiry_date'],
            'tmk_kind' => $row['tmk_kind'], 'holders' => $row['holders'], 'corr_address' => $row['corr_address'],
            'mark_description_text' => $row['mark_description_text'], 'mark_image_colour' => $row['mark_image_colour'],
            'goods' => $row['goods'], 'goods_classes' => $row['goods_classes'],
            'open_registry_url' => $row['open_registry_url'], 'appl_registry_url' => $row['appl_registry_url'],
            'files' => $row['image_url'] ? [['file_url' => $row['image_url']]] : [],
        ];
    }
    // Дата последнего обновления карточки — для бейджика и кнопки "Обновить" на фронте.
    $item['_fetched_at'] = $row['fetched_at'] ?? null;
    return $item;
}

// Запись из юр. реестра (CSV), у которой ещё нет обогащённой карточки —
// собираем "item" той же формы, что и ответ ws-proxy, просто без картинки/названия/МКТУ.
function registryRowToItem(array $row): array
{
    return [
        'object_uid'            => null,
        'appl_number'           => $row['application_number'],
        'appl_date'             => $row['application_date'],
        'reg_number'            => $row['registration_number'],
        'reg_date'              => $row['registration_date'],
        'expiry_date'           => $row['expiration_date'],
        'tmk_kind'              => $row['actual'] ? 'Регистрация' : 'Регистрация (прекращено)',
        'holders'               => $row['right_holder_name'],
        'corr_address'          => $row['correspondence_address'],
        'mark_description_text' => null,
        'mark_image_colour'     => null,
        'goods'                 => null,
        'goods_classes'         => null,
        'open_registry_url'     => $row['publication_url'],
        'appl_registry_url'     => null,
        'files'                 => [],
        'actual'                => (bool)$row['actual'],
        '_not_enriched'         => true,
    ];
}
