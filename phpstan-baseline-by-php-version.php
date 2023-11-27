<?php

$includes = [];
if (PHP_VERSION_ID >= 80000) {
    $includes[] = __DIR__ . '/phpstan-baseline-8x.neon';
} else {
    $includes[] = __DIR__ . '/phpstan-baseline-7x.neon';
}

if (PHP_VERSION_ID >= 80200) {
    $includes[] = __DIR__ . '/phpstan-baseline-8.2+.neon';
} else {
    $includes[] = __DIR__ . '/phpstan-baseline-pre-8.2.neon';
}

return [
    'includes' => $includes
];
