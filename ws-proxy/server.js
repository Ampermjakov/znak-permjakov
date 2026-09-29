// Внутренний HTTP-мост к неофициальному Socket.IO поиску Роспатента
// (searchplatform.rospatent.gov.ru/trademarks). Держит наружу только localhost
// + токен, наружу в интернет не смотрит. PHP (lib/rospatent.php) дёргает его
// по HTTP и кэширует результат в MySQL.
//
// Протокол реверс-инжинирен из фронтенда searchplatform.rospatent.gov.ru:
// он не документирован официально и может измениться без предупреждения.

const http = require('http');
const { URL } = require('url');
const { io } = require('socket.io-client');

const PORT = process.env.PORT || 8091;
const HOST = process.env.HOST || '127.0.0.1';
const TOKEN = process.env.INTERNAL_TOKEN || '';
const ROSPATENT_WS_URL = 'https://searchplatform.rospatent.gov.ru/search';
const TASK_TIMEOUT_MS = 20000;
const MAX_CONCURRENT = 5;

const DATA_SOURCES = ['trademarks', 'known_trademarks', 'international_trademarks'];

let activeCount = 0;

function buildSearchLetterPayload(query, page, size) {
    return {
        method: 'POST',
        service_name: 'esi-search',
        service_path: '/api/v1/search',
        params: { page, size },
        data: {
            query: {
                data: {
                    search_query: query,
                    parameters: {
                        algorithm_id: 1,
                        search_kind_id: 'fuzzy',
                        join_words: true,
                        use_edge_ngram: true,
                        use_reversed_edge_ngram: true,
                        split: true,
                        algorithm_val: 3,
                        data_sources: DATA_SOURCES,
                        similar_letter: false,
                        morphological: false,
                        stopwords: true,
                        translate: false,
                        transliteration: false,
                        languages: [],
                    },
                },
                type: 'search_letter',
            },
            filter: {},
            info: Buffer.from('znak-proxy;null').toString('base64'),
            oisType: 1,
        },
    };
}

// Точный поиск по одному структурированному полю (номер заявки/регистрации,
// правообладатель и т.п.) — режим "Атрибутный" на сайте Роспатента.
// key: "registration_number" | "application_number" | "verbal_elements" |
//      "applicants.name" | "owners.name"
function buildAttributePayload(key, value, name, page, size) {
    return {
        method: 'POST',
        service_name: 'esi-search',
        service_path: '/api/v1/search',
        params: { page, size },
        data: {
            query: {
                data: {
                    data_sources: DATA_SOURCES,
                    search_kind_id: 'template',
                    search_query: [
                        { id: 'attribute-1', name, type: 'TEXT', operator: 'AND', value: String(value), key },
                    ],
                },
                type: 'search_attribute',
            },
            filter: {},
            info: Buffer.from('znak-proxy;null').toString('base64'),
            oisType: 1,
        },
    };
}

// Каждый запрос — отдельное соединение: открыл, отправил задачу, получил
// один ответ, закрыл. У send_results нет id для сопоставления запрос/ответ,
// так что держать один общий сокет на несколько параллельных запросов
// небезопасно — результаты могут перепутаться между пользователями.
function runTask(payload) {
    return new Promise((resolve, reject) => {
        const socket = io(ROSPATENT_WS_URL, {
            transports: ['websocket', 'polling'],
            reconnection: false,
            timeout: 10000,
        });

        let done = false;
        const finish = (err, data) => {
            if (done) return;
            done = true;
            clearTimeout(timer);
            socket.disconnect();
            if (err) reject(err); else resolve(data);
        };

        const timer = setTimeout(() => finish(new Error('timeout')), TASK_TIMEOUT_MS);

        socket.on('connect', () => {
            socket.emit('send_task', payload);
        });

        socket.on('send_results', (data) => {
            if (data && data.message && data.totalResult === undefined) {
                finish(new Error(data.message));
            } else {
                finish(null, data);
            }
        });

        socket.on('connect_error', (err) => finish(err));
    });
}

const NUMERIC_QUERY = /^\d{4,15}$/;

// Если запрос выглядит как номер — сначала пробуем точный поиск по номеру
// регистрации, затем по номеру заявки (нечёткий текстовый поиск по номерам
// не надёжен — он ищет по словесной части и может подсунуть случайное
// совпадение). Иначе — обычный нечёткий текстовый поиск.
//
// attr — явный режим точного атрибутного поиска по конкретному полю
// (используется enrich.php для обхода по правообладателю: один запрос
// по owners.name отдаёт сразу все знаки этого владельца, до 50 за раз,
// а не по одному, как раньше).
async function runSearch(query, page, size, attr) {
    if (attr) {
        return runTask(buildAttributePayload(attr, query, attr, page, size));
    }

    if (NUMERIC_QUERY.test(query)) {
        const byReg = await runTask(buildAttributePayload('registration_number', query, 'Регистрация', page, size));
        if (byReg && byReg.totalResult > 0) return byReg;

        const byAppl = await runTask(buildAttributePayload('application_number', query, 'Заявка', page, size));
        if (byAppl && byAppl.totalResult > 0) return byAppl;

        return byReg; // totalResult: 0 — пусть PHP-сторона решает, что показать
    }

    return runTask(buildSearchLetterPayload(query, page, size));
}

const server = http.createServer((req, res) => {
    const url = new URL(req.url, `http://${req.headers.host}`);

    if (url.pathname === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, active: activeCount }));
        return;
    }

    if (url.pathname !== '/search') {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'not_found' }));
        return;
    }

    if (TOKEN && url.searchParams.get('token') !== TOKEN) {
        res.writeHead(403, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'forbidden' }));
        return;
    }

    const q = (url.searchParams.get('q') || '').trim();
    const page = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1);
    const size = Math.min(50, Math.max(1, parseInt(url.searchParams.get('size') || '20', 10) || 20));
    const attrRaw = url.searchParams.get('attr') || '';
    const ALLOWED_ATTRS = ['owners.name', 'applicants.name'];
    const attr = ALLOWED_ATTRS.includes(attrRaw) ? attrRaw : null;

    if (!q) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'empty_query' }));
        return;
    }

    if (activeCount >= MAX_CONCURRENT) {
        res.writeHead(503, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'busy' }));
        return;
    }

    activeCount++;
    runSearch(q, page, size, attr)
        .then((data) => {
            res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
            res.end(JSON.stringify(data));
        })
        .catch((err) => {
            console.error(`[${new Date().toISOString()}] upstream_error for q="${q}": ${err && err.message} ${err && err.description ? JSON.stringify(err.description) : ''}`);
            res.writeHead(502, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: 'upstream_error', detail: String(err.message || err) }));
        })
        .finally(() => { activeCount--; });
});

server.listen(PORT, HOST, () => {
    console.log(`znak ws-proxy listening on ${HOST}:${PORT}`);
});
