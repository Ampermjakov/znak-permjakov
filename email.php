<?php
require_once __DIR__ . '/config.php';

function sendMail(string $to, string $subject, string $html): bool {
    $autoload = __DIR__ . '/../permjakov.ru/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('znak email error: ' . $e->getMessage());
        }
    }
    // Fallback: PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    return mail($to, $subject, $html, $headers);
}

function emailHtml(string $body): string {
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>
  body{margin:0;padding:0;background:#0a0a0f;font-family:'Helvetica Neue',Arial,sans-serif;}
  .wrap{max-width:560px;margin:40px auto;background:#111118;border:1px solid #1e1e2e;border-radius:12px;overflow:hidden;}
  .hdr{background:linear-gradient(135deg,#f43f5e,#8b5cf6);padding:28px 32px;}
  .hdr h1{margin:0;color:#fff;font-size:20px;font-weight:700;}
  .hdr p{margin:6px 0 0;color:rgba(255,255,255,.7);font-size:13px;}
  .bdy{padding:28px 32px;color:#c4c4d4;font-size:14px;line-height:1.7;}
  .btn{display:inline-block;background:linear-gradient(135deg,#f43f5e,#fb7185);color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-weight:700;font-size:14px;margin:16px 0;}
  .ftr{padding:20px 32px;border-top:1px solid #1e1e2e;color:#3d4a62;font-size:12px;}
  a{color:#fb7185;}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h1>znak.permjakov.ru</h1><p>Проверка товарных знаков — реестр Роспатента</p></div>
  <div class="bdy">{$body}</div>
  <div class="ftr">© 2026 permjakov.ru · <a href="https://znak.permjakov.ru">znak.permjakov.ru</a></div>
</div>
</body></html>
HTML;
}
