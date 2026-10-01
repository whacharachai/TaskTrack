<?php
// tests/rollup.php - run: php tests/rollup.php
require_once __DIR__ . '/../api/db.php';

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    $ok = $got === $want;
    if (!$ok) $fails++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : "  got=" . var_export($got, true) . " want=" . var_export($want, true)) . PHP_EOL;
}

// A(100) -> B(40, done 30), C(60, done 10);  C -> D(20, done 20);  E(10, done 5) standalone
$tasks = [
    1 => ['id' => 1, 'parent_id' => null, 'total_amount' => 100],
    2 => ['id' => 2, 'parent_id' => 1, 'total_amount' => 40],
    3 => ['id' => 3, 'parent_id' => 1, 'total_amount' => 60],
    4 => ['id' => 4, 'parent_id' => 3, 'total_amount' => 20],
    5 => ['id' => 5, 'parent_id' => null, 'total_amount' => 10],
];
$own = [2 => 30.0, 3 => 10.0, 4 => 20.0, 5 => 5.0];

check('A = own 0 + B 30 + C(10+20) = 60', rollup(1, $tasks, $own)['done_amount'], 60.0);
check('A percent 60', rollup(1, $tasks, $own)['percent'], 60.0);
check('B percent 75', rollup(2, $tasks, $own)['percent'], 75.0);
check('C rolls up its own child D', rollup(3, $tasks, $own)['done_amount'], 30.0);
check('E standalone percent 50', rollup(5, $tasks, $own)['percent'], 50.0);

// over-report caps at 100 instead of showing 140%
$tasks[5]['total_amount'] = 4;
check('percent capped at 100', rollup(5, $tasks, $own)['percent'], 100.0);

// zero total must not divide by zero
$tasks[6] = ['id' => 6, 'parent_id' => null, 'total_amount' => 0];
check('zero total percent 0', rollup(6, $tasks, $own)['percent'], 0.0);

// a cycle (should not hang, treated as done)
$tasks[7] = ['id' => 7, 'parent_id' => 8, 'total_amount' => 10];
$tasks[8] = ['id' => 8, 'parent_id' => 7, 'total_amount' => 10];
check('cycle does not hang', rollup(7, $tasks, [])['percent'], 0.0);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);