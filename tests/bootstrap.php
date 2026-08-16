<?php
$GLOBALS['__failures'] = 0;

function assert_equals($expected, $actual, string $message): void {
    if ($expected === $actual) {
        echo "PASS: $message\n";
    } else {
        echo "FAIL: $message\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
        $GLOBALS['__failures']++;
    }
}

function assert_true($condition, string $message): void {
    assert_equals(true, (bool) $condition, $message);
}

function test_summary(): void {
    if ($GLOBALS['__failures'] > 0) {
        echo "\n{$GLOBALS['__failures']} FAILURE(S)\n";
        exit(1);
    }
    echo "\nALL PASSED\n";
    exit(0);
}
