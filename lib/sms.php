<?php
require_once __DIR__ . '/app_log.php';

function twilio_http_post(string $accountSid, string $authToken, string $fromNumber, string $toPhone, string $body): array {
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'From' => $fromNumber,
        'To' => $toPhone,
        'Body' => $body,
    ]));
    // No timeout here previously meant curl_exec() could block on a slow or
    // dead Twilio for as long as PHP's own execution limit allowed. This
    // runs synchronously inside connect.php, in the middle of an attendee's
    // page load, so a hung provider must fail fast (send_code_sms() already
    // treats a failure as non-fatal) rather than leave someone standing at
    // the event staring at "Connecting…".
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    return ['status' => $statusCode, 'body' => $response, 'error' => $curlError];
}

function send_code_sms(callable $transport, array $settings, string $toPhone, string $code): bool {
    $body = "Your {$settings['event_name']} Wi-Fi code is {$code}. This is also your raffle entry.";
    $result = $transport(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER, $toPhone, $body);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        // A transport-level failure (DNS, timeout, connection refused) has
        // no HTTP status at all — curl_getinfo() reports 0 for it, which
        // already fails the check above, but the log line would otherwise
        // read "HTTP 0" with an empty body and no clue why. The 'error' key
        // is only ever non-empty on exactly that kind of failure.
        $detail = $result['error'] ?? '';
        $suffix = $detail !== '' ? " ({$detail})" : '';
        app_log('send_code_sms failed: HTTP ' . $result['status'] . ' ' . $result['body'] . $suffix);
        return false;
    }
    return true;
}
