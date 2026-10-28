<?php
// Synthetic-only framed-input experiment. Never use for real credentials.
declare(strict_types=1);
try {
    $header = '';
    while (strlen($header) < 4 && !feof(STDIN)) {
        $chunk = fread(STDIN, 4 - strlen($header));
        if ($chunk === false || $chunk === '') { break; }
        $header .= $chunk;
    }
    if (strlen($header) !== 4) { throw new RuntimeException(); }
    $length = unpack('Vlength', $header)['length'];
    if ($length < 1 || $length > 64) { throw new RuntimeException(); }
    $data = '';
    while (strlen($data) < $length && !feof(STDIN)) {
        $data .= fread(STDIN, $length - strlen($data));
    }
    if (strlen($data) !== $length || fread(STDIN, 1) !== '' || $data !== 'synthetic-value') { throw new RuntimeException(); }
    echo "accepted\n";
} catch (Throwable $error) {
    fwrite(STDERR, "refused\n");
    exit(1);
}
