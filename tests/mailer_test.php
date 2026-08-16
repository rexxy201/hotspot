<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;

class FakeMailer extends PHPMailer {
    public bool $sent = false;
    public function send(): bool {
        $this->sent = true;
        return true;
    }
}

$settings = ['event_name' => 'Test Event'];
$fake = new FakeMailer();
$result = send_code_email($fake, $settings, 'jane@example.com', 'Jane', '04829371');

assert_true($result, 'send_code_email returns true when PHPMailer::send() succeeds');
assert_true($fake->sent, 'send_code_email actually calls PHPMailer::send()');
assert_true(str_contains($fake->Body, '04829371'), 'the email body contains the code');
assert_equals('Test Event Wi-Fi Code', $fake->Subject, 'the email subject includes the event name');

test_summary();
