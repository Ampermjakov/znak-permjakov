<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Проверка товарного знака — Реестр Роспатента | permjakov.ru</title>
    <meta name="description" content="Проверка товарного знака по номеру свидетельства, названию или правообладателю. Статус, классы МКТУ, изображение знака — по данным открытого реестра Роспатента.">
    <link rel="icon" href="https://permjakov.ru/amper.svg" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>

<body>

    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <div id="app">

        <nav class="topnav">
            <div class="topnav-inner">
                <a href="https://permjakov.ru" class="brand"><span>//</span> permjakov.ru</a>
                <div class="nav-tools">
                    <a href="https://pd.permjakov.ru/" class="nav-tool">РНК ПД</a>
                    <a href="https://chek.permjakov.ru/" class="nav-tool">РКН Аудит</a>
                    <a href="https://rkn.permjakov.ru" class="nav-tool">РКН Блок</a>
                    <a href="https://cbr.permjakov.ru" class="nav-tool">ЦБ Стоп-лист</a>
                    <a href="https://whois.permjakov.ru" class="nav-tool">Whois</a>
                    <a href="https://znak.permjakov.ru" class="nav-tool active">Товарные знаки</a>
                    <a href="https://monitor.permjakov.ru" class="nav-tool">Мониторинг</a>
                </div>
                <div class="nav-auth">
                    <span class="nav-user-info" v-if="authUser">
                        <span class="nav-email">{{ authUser.email }}</span>
                        <button class="nav-tool" @click="logout" style="color:#6b7a99">Выйти</button>
                    </span>
                    <template v-else>
                        <button class="nav-tool nav-login-btn" @click="openAuthModal('login')">Войти</button>
                        <button class="nav-tool nav-reg-btn" @click="openAuthModal('register')" style="color:#f43f5e">Регистрация</button>
                    </template>
                </div>
                <button class="nav-burger" id="navBurger" aria-label="Меню">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </nav>

        <div class="nav-mobile" id="navMobile">
            <button class="nav-mobile-close" id="navMobileClose">✕</button>
            <a href="https://pd.permjakov.ru/" class="m-link">РНК ПД</a>
            <a href="https://chek.permjakov.ru/" class="m-link">РКН Аудит</a>
            <a href="https://rkn.permjakov.ru" class="m-link">РКН Блок</a>
            <a href="https://cbr.permjakov.ru" class="m-link">ЦБ Стоп-лист</a>
            <a href="https://whois.permjakov.ru" class="m-link">Whois</a>
            <a href="https://znak.permjakov.ru" class="m-link active">Товарные знаки</a>
            <a href="https://monitor.permjakov.ru" class="m-link">Мониторинг</a>
        </div>

        <div class="privacy-overlay" id="privacyModal" role="dialog" aria-modal="true" aria-labelledby="privacyTitle">
            <div class="privacy-modal">
                <div class="privacy-head">
                    <span class="privacy-title" id="privacyTitle">Политика конфиденциальности</span>
                    <button class="privacy-close" id="privacyClose" aria-label="Закрыть">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                </div>
                <div class="privacy-body">
                    <span class="privacy-updated">Последнее обновление: 17 июля 2026 г.</span>
                    <h3>1. Общие положения</h3>
                    <p>Настоящая Политика определяет порядок обработки данных пользователей сервиса <strong>znak.permjakov.ru</strong>, принадлежащего ИП Пермяков.</p>
                    <h3>2. Данные, которые мы собираем</h3>
                    <p>Поиск по товарным знакам доступен без регистрации. Для всех пользователей сохраняется текст поискового запроса, IP-адрес (для веб) или идентификатор чата (для ботов Telegram/MAX), и время обращения — для статистики использования и защиты от злоупотреблений.</p>
                    <p>Регистрация нужна только для кнопки «Обновить» (получение свежих данных по конкретному знаку не чаще раза в сутки). При регистрации мы храним email и хэш пароля (сам пароль нам не виден и не хранится). Пароли можно восстановить только через повторную регистрацию — функции сброса пароля пока нет.</p>
                    <h3>3. Источники данных</h3>
                    <p>Сведения о товарных знаках предоставлены Роспатентом на условиях Открытой лицензии (<a href="https://rospatent.gov.ru/opendata" target="_blank" rel="noreferrer">rospatent.gov.ru/opendata</a>) и через открытый поисковый сервис <strong>searchplatform.rospatent.gov.ru</strong>. Мы не изменяем и не искажаем эти данные.</p>
                    <h3>4. Cookies</h3>
                    <p>Используется только один аналитический cookie-файл (Яндекс.Метрика), который загружается исключительно после вашего согласия в баннере cookie. Отказ от cookie не ограничивает функциональность поиска.</p>
                    <h3>5. Контакты</h3>
                    <p>Email: <a href="mailto:mail@permjakov.ru">mail@permjakov.ru</a> · Telegram: <a href="https://t.me/permjakov" target="_blank" rel="noopener">@permjakov</a></p>
                </div>
            </div>
        </div>

        <!-- Auth Modal -->
        <div class="auth-overlay" :class="{ open: authModalOpen }">
            <div class="auth-modal">
                <button class="auth-close" @click="closeAuthModal">✕</button>
                <div class="auth-tabs">
                    <button class="auth-tab" :class="{ active: authTab === 'login' }" @click="authTab = 'login'; authMsg = ''">Войти</button>
                    <button class="auth-tab" :class="{ active: authTab === 'register' }" @click="authTab = 'register'; authMsg = ''">Регистрация</button>
                </div>
                <div class="auth-msg" v-if="authMsg">{{ authMsg }}</div>
                <form v-if="!authSuccessMsg" @submit.prevent="submitAuth">
                    <input type="email" v-model="authEmail" placeholder="Email" required class="auth-input">
                    <input type="password" v-model="authPassword" placeholder="Пароль (мин. 8 символов)" required class="auth-input">
                    <button type="submit" class="auth-submit" :disabled="authSubmitting">
                        {{ authSubmitting ? '...' : (authTab === 'login' ? 'Войти' : 'Зарегистрироваться') }}
                    </button>
                </form>
                <div v-else style="text-align:center;padding:20px">
                    <div style="font-size:32px;margin-bottom:12px">✉️</div>
                    <div>{{ authSuccessMsg }}</div>
                </div>
                <p style="margin-top:14px;font-size:12px;color:var(--text-3);text-align:center">
                    Авторизация нужна только для кнопки «Обновить» на карточке знака — сама проверка доступна без входа.
                </p>
            </div>
        </div>

        <div class="container">

            <header class="header">
                <div class="header-badge">
                    <span class="badge-dot"></span>
                    Реестр Роспатента
                </div>
                <h1>Товарные знаки</h1>
                <p>Проверка по номеру свидетельства, названию знака, правообладателю или ИНН — статус, классы МКТУ, изображение</p>
            </header>

            <!-- Search -->
            <div class="card ad-1">
                <form @submit.prevent="doSearch(1)" class="search-form">
                    <input
                        v-model="query"
                        type="text"
                        class="search-input"
                        :placeholder="innMode ? 'ИНН правообладателя — 10 цифр (юрлицо) или 12 (ИП)' : '998616, АМПЕР или Иванов Иван Иванович'"
                        autocomplete="off"
                        spellcheck="false"
                        :disabled="loading"
                        ref="inputRef">
                    <button type="submit" class="search-btn" :disabled="loading || !query.trim()">
                        <span v-if="loading">Ищу...</span>
                        <span v-else>Найти →</span>
                    </button>
                </form>
                <label class="owner-mode-toggle" title="Точный поиск по ИНН правообладателя — без нечёткого совпадения по названию/словам">
                    <input type="checkbox" v-model="innMode">
                    Точный поиск по ИНН правообладателя
                </label>
                <div class="examples">
                    <span class="examples-label">Примеры:</span>
                    <button class="example-btn" @click="fill('998616')">998616</button>
                    <button class="example-btn" @click="fill('Яндекс')">Яндекс</button>
                    <button class="example-btn" @click="fill('Сбербанк')">Сбербанк</button>
                </div>
            </div>

            <!-- Error -->
            <div class="card" v-if="error && !loading">
                <div class="error-box">{{ error }}</div>
            </div>

            <!-- Loading -->
            <div class="card" v-if="loading">
                <div class="spinner-wrap">
                    <div class="spinner"></div>
                    <p>Запрос к реестру Роспатента...</p>
                </div>
            </div>

            <!-- Results -->
            <template v-if="result && !loading">

                <div class="result-meta" v-if="result.total > 0">
                    <span>Найдено: {{ result.total.toLocaleString('ru') }}</span>
                    <span>Страница {{ result.page }} из {{ result.total_pages.toLocaleString('ru') }}</span>
                </div>

                <div class="cache-bar" v-if="result.search_fetched_at" style="margin-bottom:16px">
                    <span class="cache-info">📦 Этот поиск обновлён: {{ formatDate(result.search_fetched_at) }}</span>
                    <button
                        class="cache-refresh-btn"
                        :disabled="refreshingSearch"
                        @click="refreshSearch"
                        :title="authUser ? 'Не чаще раза в сутки на одну фразу' : 'Нужна авторизация'">
                        {{ refreshingSearch ? 'Обновляю...' : (authUser ? 'Обновить поиск ↺' : 'Войти, чтобы обновить поиск') }}
                    </button>
                </div>

                <div class="card" v-if="result.notice">
                    <div class="notice-box">
                        ⏳ {{ result.notice }}
                        <span v-if="result.total > 0"> Ниже — то, что уже есть в нашей базе (не исчерпывающий список).</span>
                    </div>
                </div>

                <div class="card" v-if="result.total === 0 && !result.notice">
                    <div class="notice-box">Ничего не найдено по запросу «{{ result.query }}». Проверьте номер или попробуйте часть названия.</div>
                </div>

                <div class="card ad-2" v-for="item in result.results" :key="item.object_uid">
                    <div class="tm-card">
                        <div class="tm-thumb" :class="{ 'no-image': !item.image_url }">
                            <img v-if="item.image_url" :src="item.image_url" :alt="item.name || item.reg_number" loading="lazy">
                            <span v-else>без изображения</span>
                        </div>
                        <div class="tm-body">
                            <div class="tm-head">
                                <div class="tm-name" :class="{ empty: !item.name }">{{ item.name || '(словесная часть не выделена)' }}</div>
                                <span class="status-badge" :class="item.status.code">{{ item.status.icon }} {{ item.status.label }}</span>
                            </div>

                            <div class="tm-numbers">
                                <div class="tm-num-item" v-if="item.reg_number">
                                    <span class="tm-num-label">Регистрация №</span>
                                    <span class="tm-num-val mono-accent">{{ item.reg_number }}</span>
                                </div>
                                <div class="tm-num-item" v-if="item.reg_date">
                                    <span class="tm-num-label">Дата регистрации</span>
                                    <span class="tm-num-val">{{ item.reg_date }}</span>
                                </div>
                                <div class="tm-num-item" v-if="item.expiry_date">
                                    <span class="tm-num-label">Действует до</span>
                                    <span class="tm-num-val">{{ item.expiry_date }}</span>
                                </div>
                                <div class="tm-num-item" v-if="item.appl_number">
                                    <span class="tm-num-label">Заявка №</span>
                                    <span class="tm-num-val">{{ item.appl_number }}</span>
                                </div>
                                <div class="tm-num-item" v-if="item.appl_date">
                                    <span class="tm-num-label">Дата подачи</span>
                                    <span class="tm-num-val">{{ item.appl_date }}</span>
                                </div>
                            </div>

                            <div class="tm-holder" v-if="item.holders">
                                <span class="tm-holder-label">Правообладатель / заявитель</span>
                                {{ item.holders }}
                            </div>

                            <div class="tm-classes" v-if="item.goods_classes">
                                <span class="class-chip" v-for="c in item.goods_classes.split(';').map(s => s.trim()).filter(Boolean)" :key="c">{{ c }}</span>
                            </div>

                            <div v-if="item.goods">
                                <span class="tm-goods-toggle" @click="toggleGoods(item.object_uid)">
                                    {{ openGoods.has(item.object_uid) ? 'Скрыть перечень товаров и услуг' : 'Показать перечень товаров и услуг (МКТУ)' }}
                                </span>
                                <div class="tm-goods" v-if="openGoods.has(item.object_uid)">{{ item.goods }}</div>
                            </div>

                            <div class="tm-links" v-if="item.registry_url">
                                <a :href="item.registry_url" target="_blank" rel="noreferrer">Открыть карточку на fips.ru →</a>
                            </div>

                            <div class="cache-bar" v-if="item.reg_number" style="margin-top:12px">
                                <span class="cache-info">
                                    <span v-if="item.fetched_at">📦 Обновлено: {{ formatDate(item.fetched_at) }}</span>
                                    <span v-else>📦 Только юр. данные из реестра, без картинки</span>
                                </span>
                                <button
                                    class="cache-refresh-btn"
                                    :disabled="refreshingId === item.reg_number"
                                    @click="refreshItem(item)"
                                    :title="authUser ? 'Не чаще раза в сутки на один знак' : 'Нужна авторизация'">
                                    {{ refreshingId === item.reg_number ? 'Обновляю...' : (authUser ? 'Обновить ↺' : 'Войти, чтобы обновить') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pagination" v-if="result.total_pages > 1">
                    <button class="page-btn" :disabled="result.page <= 1 || loading" @click="doSearch(result.page - 1)">← Назад</button>
                    <span class="page-info">{{ result.page }} / {{ result.total_pages }}</span>
                    <button class="page-btn" :disabled="result.page >= result.total_pages || loading" @click="doSearch(result.page + 1)">Вперёд →</button>
                </div>

            </template>

            <footer class="footer">
                <p>© <a href="https://permjakov.ru">permjakov.ru</a></p>
                <div class="footer-links">
                    <a href="https://pd.permjakov.ru/" class="nav-tool">РНК ПД</a>
                    <a href="https://chek.permjakov.ru/" class="nav-tool">РКН Аудит</a>
                    <a href="https://rkn.permjakov.ru" class="nav-tool">РКН Блок</a>
                    <a href="https://cbr.permjakov.ru" class="nav-tool">ЦБ Стоп-лист</a>
                    <a href="https://whois.permjakov.ru" class="nav-tool">Whois</a>
                    <a href="https://znak.permjakov.ru" class="nav-tool active">Товарные знаки</a>
                    <a href="https://monitor.permjakov.ru" class="nav-tool">Мониторинг</a>
                </div>
                <div class="footer-source">
                    Данные предоставлены Роспатентом на условиях Открытой лицензии
                    (<a href="https://rospatent.gov.ru/opendata" target="_blank" rel="noreferrer">rospatent.gov.ru/opendata</a>),
                    часть данных — через открытый поисковый сервис searchplatform.rospatent.gov.ru
                </div>
            </footer>

        </div>
    </div>

    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script>
        const { createApp, ref, onMounted } = Vue;

        createApp({
            setup() {
                const query     = ref('');
                const innMode   = ref(false);
                const loading   = ref(false);
                const result    = ref(null);
                const error     = ref('');
                const openGoods = ref(new Set());
                const inputRef  = ref(null);

                // Авторизация — нужна только для кнопки "Обновить"
                const authUser        = ref(null);
                const authModalOpen   = ref(false);
                const authTab         = ref('login');
                const authEmail       = ref('');
                const authPassword    = ref('');
                const authMsg         = ref('');
                const authSubmitting  = ref(false);
                const authSuccessMsg  = ref('');
                const refreshingId    = ref(null);
                const refreshingSearch = ref(false);

                function fill(v) {
                    query.value = v;
                    doSearch(1);
                }

                function toggleGoods(uid) {
                    const s = new Set(openGoods.value);
                    s.has(uid) ? s.delete(uid) : s.add(uid);
                    openGoods.value = s;
                }

                function formatDate(iso) {
                    if (!iso) return '';
                    const d = new Date(iso.replace(' ', 'T'));
                    if (isNaN(d)) return iso;
                    return d.toLocaleDateString('ru') + ' ' + d.toLocaleTimeString('ru', { hour: '2-digit', minute: '2-digit' });
                }

                function openAuthModal(tab) {
                    authTab.value = tab || 'login';
                    authModalOpen.value = true;
                    authMsg.value = '';
                    authSuccessMsg.value = '';
                    authEmail.value = '';
                    authPassword.value = '';
                }

                function closeAuthModal() {
                    authModalOpen.value = false;
                }

                async function submitAuth() {
                    authSubmitting.value = true;
                    authMsg.value = '';
                    try {
                        const r = await fetch('auth.php?action=' + authTab.value, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ email: authEmail.value, password: authPassword.value }),
                        });
                        const data = await r.json();
                        if (!data.ok) { authMsg.value = data.error; return; }

                        if (authTab.value === 'register') {
                            if (data.admin_ready) {
                                authTab.value = 'login';
                                authMsg.value = '✓ ' + data.message;
                            } else {
                                authSuccessMsg.value = data.message;
                            }
                        } else {
                            authUser.value = data.user;
                            closeAuthModal();
                        }
                    } catch (e) {
                        authMsg.value = 'Ошибка соединения';
                    } finally {
                        authSubmitting.value = false;
                    }
                }

                async function logout() {
                    await fetch('auth.php?action=logout', { method: 'POST' });
                    authUser.value = null;
                }

                async function loadAuthUser() {
                    try {
                        const r = await fetch('auth.php?action=me');
                        const data = await r.json();
                        if (data.ok) authUser.value = data.user;
                    } catch (e) {}
                }

                async function refreshItem(item) {
                    if (!item.reg_number) return;
                    if (!authUser.value) { openAuthModal('login'); return; }

                    refreshingId.value = item.reg_number;
                    try {
                        const r = await fetch('refresh.php?reg_number=' + encodeURIComponent(item.reg_number));
                        const data = await r.json();
                        if (data.error) { alert(data.error); return; }
                        // Подменяем карточку на свежую прямо в списке — без повторного
                        // похода в живой поиск всего запроса (это отдельная квота).
                        const idx = result.value.results.findIndex(r => r.reg_number === item.reg_number);
                        if (idx !== -1) result.value.results.splice(idx, 1, data.item);
                    } catch (e) {
                        alert('Ошибка соединения');
                    } finally {
                        refreshingId.value = null;
                    }
                }

                async function refreshSearch() {
                    if (!authUser.value) { openAuthModal('login'); return; }
                    if (!result.value) return;

                    const q = query.value.trim();
                    const page = result.value.page || 1;
                    refreshingSearch.value = true;
                    try {
                        const r = await fetch('refresh_search.php?q=' + encodeURIComponent(q) + '&page=' + page);
                        const data = await r.json();
                        if (data.error) { alert(data.error); return; }
                        // Заменяем список результатов и метаданные, не трогая сам query —
                        // это тот же поиск, просто со свежим ответом Роспатента.
                        result.value = {
                            ...result.value,
                            total: data.total,
                            page: data.page,
                            total_pages: data.total_pages,
                            per_page: data.per_page,
                            results: data.results,
                            search_fetched_at: data.search_fetched_at,
                        };
                    } catch (e) {
                        alert('Ошибка соединения');
                    } finally {
                        refreshingSearch.value = false;
                    }
                }

                onMounted(() => {
                    loadAuthUser();
                    const params = new URLSearchParams(location.search);
                    const tz = (params.get('q') || params.get('tz') || '').trim();
                    if (tz) {
                        query.value = tz;
                        doSearch(1);
                    }
                });

                async function doSearch(page) {
                    const q = query.value.trim();
                    if (!q) return;
                    if (innMode.value && !/^\d{10}$|^\d{12}$/.test(q)) {
                        error.value = 'ИНН — это 10 цифр (юрлицо) или 12 цифр (ИП), без пробелов и лишних символов';
                        return;
                    }
                    loading.value = true;
                    error.value = '';
                    if (page === 1) result.value = null;

                    try {
                        const r = await fetch('search.php?q=' + encodeURIComponent(q) + '&page=' + page);
                        const text = await r.text();
                        let data;
                        try { data = JSON.parse(text); }
                        catch (e) {
                            error.value = 'Ответ сервера повреждён (HTTP ' + r.status + ')';
                            return;
                        }
                        if (data.error) { error.value = data.error; return; }
                        result.value = data;
                        openGoods.value = new Set();
                        window.scrollTo({ top: 0, behavior: 'smooth' });

                        // Точный поиск по номеру — отражаем в URL, чтобы ссылку на результат
                        // можно было скопировать и переслать (см. ?tz=998616).
                        if (!innMode.value && /^\d{4,15}$/.test(q)) {
                            const url = new URL(location.href);
                            url.searchParams.set('tz', q);
                            history.replaceState(null, '', url);
                        }
                    } catch (e) {
                        error.value = 'Ошибка соединения с сервисом.';
                    } finally {
                        loading.value = false;
                    }
                }

                return {
                    query, innMode, loading, result, error, openGoods, inputRef, fill, doSearch, toggleGoods, formatDate,
                    authUser, authModalOpen, authTab, authEmail, authPassword, authMsg, authSubmitting, authSuccessMsg,
                    openAuthModal, closeAuthModal, submitAuth, logout,
                    refreshingId, refreshItem,
                    refreshingSearch, refreshSearch,
                };
            }
        }).mount('#app');

        // Burger menu
        (function() {
            const burger = document.getElementById('navBurger');
            const mobile = document.getElementById('navMobile');
            const close  = document.getElementById('navMobileClose');
            if (!burger) return;
            burger.addEventListener('click', () => { burger.classList.toggle('open'); mobile.classList.toggle('open'); });
            close.addEventListener('click', () => { burger.classList.remove('open'); mobile.classList.remove('open'); });
        })();

        // Privacy modal
        const _pm = document.getElementById('privacyModal');
        function openPrivacyModal() {
            _pm.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closePrivacyModal() {
            _pm.classList.remove('open');
            document.body.style.overflow = '';
        }
        document.getElementById('privacyClose').addEventListener('click', closePrivacyModal);
        _pm.addEventListener('click', (e) => { if (e.target === _pm) closePrivacyModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && _pm.classList.contains('open')) closePrivacyModal(); });
    </script>

    <?php if (isset($_COOKIE['cookie_consent']) && $_COOKIE['cookie_consent'] === 'accepted'): ?>
        <?php if (file_exists(__DIR__ . '/metrika.php') && filesize(__DIR__ . '/metrika.php') > 0): ?>
            <?php include 'metrika.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!isset($_COOKIE['cookie_consent'])): ?>
        <?php include 'cookie-banner.php'; ?>
    <?php endif; ?>

</body>
</html>
