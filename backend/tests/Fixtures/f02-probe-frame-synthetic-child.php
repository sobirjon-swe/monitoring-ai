<?php
declare(strict_types=1);
require __DIR__.'/../../../scripts/f02-probe-frame-review.php';
try {
    [$record, $password] = f02ReadFrames(STDIN);
    if ($record !== '{"fake":true}' || $password !== 'fictional-value') { throw new RuntimeException(); }
    echo "accepted\n";
} catch (Throwable $e) { fwrite(STDERR, "refused\n"); exit(1); }
