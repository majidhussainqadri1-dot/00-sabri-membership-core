<?php
declare(strict_types=1);
define('ABSPATH', __DIR__ . '/');
require dirname( __DIR__ ) . '/source/sabri-membership-core/includes/class-smc-admin.php';
$method = new ReflectionMethod('SMC_Admin', 'approval_gate');
$method->setAccessible(true);
$cases = [
    [0, 2, false, 'pending_votes', 'no votes'],
    [1, 2, false, 'pending_votes', 'first ordinary vote'],
    [2, 2, false, 'pending_senior', 'two ordinary/independent votes retained'],
    [2, 2, true, 'finalize', 'senior finalizes two votes'],
    [1, 1, false, 'finalize', 'single-vote nonprofessional'],
];
$pass = 0;
foreach ($cases as [$votes, $required, $canFinalize, $expected, $name]) {
    $actual = $method->invoke(null, $votes, $required, $canFinalize);
    if ($actual !== $expected) {
        fwrite(STDERR, "$name: expected $expected, got $actual\n");
        exit(1);
    }
    $pass++;
}
echo "approval gate runtime: {$pass} PASS, 0 FAIL\n";
