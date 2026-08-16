<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/sms.php';

$settings = ['event_name' => 'Test Event'];

$successTransport = function (...$args) {
    return ['status' => 201, 'body' => '{"sid":"SMxxx"}'];
};
assert_true(send_code_sms($successTransport, $settings, '+2348010000000', '04829371'), 'send_code_sms returns true on a 2xx response');

$failTransport = function (...$args) {
    return ['status' => 400, 'body' => '{"message":"bad request"}'];
};
assert_true(!send_code_sms($failTransport, $settings, '+2348010000000', '04829371'), 'send_code_sms returns false on a non-2xx response');

$capturedBody = null;
$capturingTransport = function ($sid, $token, $from, $to, $body) use (&$capturedBody) {
    $capturedBody = $body;
    return ['status' => 201, 'body' => '{}'];
};
send_code_sms($capturingTransport, $settings, '+2348010000000', '04829371');
assert_true(str_contains($capturedBody, '04829371'), 'the SMS body contains the code');

test_summary();
