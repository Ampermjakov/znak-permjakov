<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/lib/db.php';

error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . SITE_URL);

function ok(array $data = []): void  { echo json_encode(['ok' => true]  + $data, JSON_UNESCAPED_UNICODE); exit; }
function err(string $msg):    void  { echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        $v = $_SERVER[$h] ?? '';
        if ($v) return trim(explode(',', $v)[0]);
    }
    return '';
}

$action = $_GET['action'] ?? '';
$pdo = dbConnect();
if (!$pdo) err('Нет соединения с БД');

// GET verify — подтверждение email по ссылке из письма
if ($action === 'verify' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');
    if (!$token) { header('Location: ' . SITE_URL . '?msg=invalid_token'); exit; }
    $st = $pdo->prepare('SELECT id FROM users WHERE verify_token = ? AND verified = 0');
    $st->execute([$token]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user) { header('Location: ' . SITE_URL . '?msg=invalid_token'); exit; }
    $pdo->prepare('UPDATE users SET verified = 1, verify_token = NULL WHERE id = ?')->execute([$user['id']]);
    header('Location: ' . SITE_URL . '?msg=verified'); exit;
}

// GET me — текущий пользователь
if ($action === 'me') {
    $user = authCurrentUser();
    ok(['user' => $user ? [
        'id'       => $user['id'],
        'email'    => $user['email'],
        'is_admin' => (bool)$user['is_admin'],
    ] : null]);
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];

// POST register
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Некорректный email');
    if (strlen($pass) < 8) err('Пароль минимум 8 символов');

    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) err('Этот email уже зарегистрирован');

    $isAdminEmail = (strtolower($email) === strtolower(ADMIN_EMAIL));
    $hash = password_hash($pass, PASSWORD_BCRYPT);

    if ($isAdminEmail) {
        $pdo->prepare('INSERT INTO users (email, password, verified, is_admin) VALUES (?,?,1,1)')
            ->execute([$email, $hash]);
        ok(['message' => 'Аккаунт администратора создан. Войдите.', 'admin_ready' => true]);
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO users (email, password, verify_token) VALUES (?,?,?)')
        ->execute([$email, $hash, $token]);

    $link = SITE_URL . '/auth.php?action=verify&token=' . $token;
    $html = "<p>Здравствуйте!</p>
             <p>Для завершения регистрации на <strong>znak.permjakov.ru</strong> подтвердите ваш email:</p>
             <a class='btn' href='{$link}'>Подтвердить email →</a>
             <p style='color:#3d4a62;font-size:12px;margin-top:16px'>Если вы не регистрировались — проигнорируйте это письмо.</p>";
    sendMail($email, 'Подтверждение регистрации — znak.permjakov.ru', emailHtml($html));
    ok(['message' => 'Письмо с подтверждением отправлено на ' . $email]);
}

// POST login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';
    $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($pass, $user['password'])) err('Неверный email или пароль');
    if ($user['is_blocked']) err('Ваш аккаунт заблокирован');
    if (strtolower($email) === strtolower(ADMIN_EMAIL)) {
        $pdo->prepare('UPDATE users SET is_admin = 1, verified = 1 WHERE id = ?')->execute([$user['id']]);
        $user['is_admin'] = 1;
        $user['verified'] = 1;
    }
    if (!$user['verified']) err('Подтвердите email перед входом');

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_DAYS * 86400);
    $pdo->prepare('INSERT INTO sessions (token, user_id, expires_at, ip, ua) VALUES (?,?,?,?,?)')
        ->execute([$token, $user['id'], $expires, clientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '']);
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

    setcookie('znak_session', $token, time() + SESSION_DAYS * 86400, '/', '', true, true);
    ok(['user' => [
        'id'       => $user['id'],
        'email'    => $user['email'],
        'is_admin' => (bool)$user['is_admin'],
    ]]);
}

// POST logout
if ($action === 'logout') {
    $token = $_COOKIE['znak_session'] ?? '';
    if ($token) $pdo->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    setcookie('znak_session', '', time() - 3600, '/', '', true, true);
    ok();
}

err('Неизвестное действие');
