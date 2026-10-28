<?php
declare(strict_types=1);
// Bounded binary stdin frames; this file contains no database calls.
function f02ReadExact($stream, int $length): string {
    $result = '';
    while (strlen($result) < $length && !feof($stream)) {
        $chunk = fread($stream, $length - strlen($result));
        if ($chunk === false || $chunk === '') { break; }
        $result .= $chunk;
    }
    if (strlen($result) !== $length) { throw new RuntimeException('Input refused'); }
    return $result;
}
function f02ReadFrames($stream): array {
    $values = [];
    foreach ([65536, 4096] as $max) {
        $length = unpack('Vn', f02ReadExact($stream, 4))['n'];
        if ($length < 1 || $length > $max) { throw new RuntimeException('Input refused'); }
        $values[] = f02ReadExact($stream, $length);
    }
    if (fread($stream, 1) !== '') { throw new RuntimeException('Input refused'); }
    return $values;
}
