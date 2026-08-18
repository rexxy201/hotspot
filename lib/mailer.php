<?php
use PHPMailer\PHPMailer\PHPMailer;

function build_code_email_body(array $settings, string $name, string $code): string {
    $eventName = htmlspecialchars($settings['event_name'], ENT_QUOTES);
    $safeName = htmlspecialchars($name, ENT_QUOTES);
    $safeCode = htmlspecialchars($code, ENT_QUOTES);
    return "<p>Hi {$safeName},</p>" .
           "<p>Thanks for connecting to Wi-Fi at {$eventName}.</p>" .
           "<p>Your code is: <strong>{$safeCode}</strong></p>" .
           "<p>Keep this code — it's also your raffle entry.</p>";
}

function send_code_email(PHPMailer $mail, array $settings, string $toEmail, string $toName, string $code): bool {
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = $settings['event_name'] . ' Wi-Fi Code';
    $mail->Body = build_code_email_body($settings, $toName, $code);
    try {
        return $mail->send();
    } catch (\Exception $e) {
        error_log('send_code_email failed: ' . $e->getMessage());
        return false;
    }
}

function make_smtp_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    // PHPMailer's default is 300s. This runs synchronously inside
    // connect.php, in the middle of an attendee's page load — a slow or
    // dead SMTP server must fail fast (send_code_email() already treats a
    // failure as non-fatal; the code still shows on screen), not leave
    // someone standing at the event staring at "Connecting…" for minutes.
    $mail->Timeout = 10;
    return $mail;
}
