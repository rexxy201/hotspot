<?php
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
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $statusCode, 'body' => $response];
}

function send_code_sms(callable $transport, array $settings, string $toPhone, string $code): bool {
    $body = "Your {$settings['event_name']} Wi-Fi code is {$code}. This is also your raffle entry.";
    $result = $transport(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER, $toPhone, $body);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        error_log('send_code_sms failed: HTTP ' . $result['status'] . ' ' . $result['body']);
        return false;
    }
    return true;
}
