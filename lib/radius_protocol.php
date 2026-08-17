<?php

/**
 * RADIUS wire-protocol helpers (RFC 2865).
 *
 * Deliberately free of sockets, database access and application state so the
 * whole protocol layer can be unit-tested; radius_server.php is a thin socket
 * loop on top of this.
 */

// Packet codes.
const R_ACCESS_REQUEST      = 1;
const R_ACCESS_ACCEPT       = 2;
const R_ACCESS_REJECT       = 3;
const R_ACCOUNTING_REQUEST  = 4;
const R_ACCOUNTING_RESPONSE = 5;

// Attribute types.
const R_ATTR_USER_NAME       = 1;
const R_ATTR_USER_PASSWORD   = 2;   // PAP — obfuscated with the shared secret
const R_ATTR_CHAP_PASSWORD   = 3;   // 1-byte id + 16-byte MD5 response
const R_ATTR_REPLY_MESSAGE   = 18;
const R_ATTR_VENDOR_SPECIFIC = 26;
const R_ATTR_SESSION_TIMEOUT = 27;
const R_ATTR_CALLING_STATION = 31;  // the client MAC
const R_ATTR_CHAP_CHALLENGE  = 60;

// Mikrotik vendor-specific attributes.
const VENDOR_MIKROTIK = 14988;
const MT_GROUP        = 5;  // hotspot user profile / group
const MT_UPTIME_LIMIT = 7;  // session seconds
const MT_RATE_LIMIT   = 9;  // e.g. "5M/5M"

/**
 * Split a RADIUS attribute blob into [type => value].
 *
 * Malformed input is discarded rather than throwing: this parses packets from
 * the network, and the daemon must not die on a bad one. Duplicate types keep
 * the last occurrence, which is all we need for the attributes we read.
 */
function radius_parse_attributes(string $data): array
{
    $attrs = [];
    $i = 0;
    $len = strlen($data);
    while ($i + 2 <= $len) {
        $type = ord($data[$i]);
        $length = ord($data[$i + 1]);
        // length < 2 would mean no forward progress (infinite loop); a length
        // past the end of the buffer means a truncated or hostile packet.
        if ($length < 2 || $i + $length > $len) {
            break;
        }
        $attrs[$type] = substr($data, $i + 2, $length - 2);
        $i += $length;
    }
    return $attrs;
}

/** Encode one attribute: type, total length, value. */
function radius_encode_attr(int $type, string $value): string
{
    return chr($type) . chr(2 + strlen($value)) . $value;
}

/** Encode a vendor-specific attribute, wrapped in attribute 26. */
function radius_encode_vsa(int $vendor, int $subType, string $value): string
{
    $inner = pack('N', $vendor) . chr($subType) . chr(2 + strlen($value)) . $value;
    return radius_encode_attr(R_ATTR_VENDOR_SPECIFIC, $inner);
}

/**
 * Build a complete reply packet including its Response Authenticator, which is
 * MD5(code + id + length + request authenticator + attributes + secret).
 */
function radius_build_reply(int $code, int $id, string $requestAuthenticator, string $secret, string $attrs): string
{
    $length = 20 + strlen($attrs);
    $header = chr($code) . chr($id) . pack('n', $length);
    $responseAuth = md5($header . $requestAuthenticator . $attrs . $secret, true);
    return $header . $responseAuth . $attrs;
}

/**
 * Reverse RFC 2865's PAP obfuscation.
 *
 * b1 = MD5(secret + request authenticator); p1 = c1 XOR b1
 * bN = MD5(secret + c(N-1));               pN = cN XOR bN
 */
function radius_decrypt_password(string $encrypted, string $requestAuthenticator, string $secret): string
{
    $result = '';
    $last = $requestAuthenticator;
    for ($i = 0; $i < strlen($encrypted); $i += 16) {
        $chunk = substr($encrypted, $i, 16);
        $result .= $chunk ^ md5($secret . $last, true);
        $last = $chunk;
    }
    return rtrim($result, "\x00");
}

/** The inverse of radius_decrypt_password (tests and the loopback probe). */
function radius_encrypt_password(string $plain, string $requestAuthenticator, string $secret): string
{
    // RFC 2865 pads the password to a multiple of 16 bytes with NULs.
    $padded = $plain;
    $remainder = strlen($padded) % 16;
    if ($remainder !== 0 || $padded === '') {
        $padded .= str_repeat("\x00", 16 - $remainder);
    }
    $result = '';
    $last = $requestAuthenticator;
    for ($i = 0; $i < strlen($padded); $i += 16) {
        $chunk = substr($padded, $i, 16);
        $cipher = $chunk ^ md5($secret . $last, true);
        $result .= $cipher;
        $last = $cipher;
    }
    return $result;
}

/**
 * Verify a CHAP-Password attribute.
 *
 * The response is MD5(chap id + plaintext password + challenge). The shared
 * secret is not involved, so CHAP works purely off the stored password.
 */
function radius_check_chap(string $chapPassword, string $challenge, string $plainPassword): bool
{
    if (strlen($chapPassword) !== 17) {
        return false;
    }
    $chapId = substr($chapPassword, 0, 1);
    $response = substr($chapPassword, 1, 16);
    return hash_equals(md5($chapId . $plainPassword . $challenge, true), $response);
}
