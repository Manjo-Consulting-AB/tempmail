<?php

declare(strict_types=1);

/**
 * Regression coverage for the pure Pro-trial helpers in pro_trial.php:
 * proTrialNormalizeEmail() and proTrialEmailHash() (epic #267, step 1/4).
 *
 * Run with:  php tests/pro_trial_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Pure functions only: no
 * database, no network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../pro_trial.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "[OK]  {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    check($label, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

// ---------------------------------------------------------------------------
// proTrialNormalizeEmail()
// ---------------------------------------------------------------------------

same('N1. trim, lowercase, drop +tag, remove dots',
    'johndoe@example.com',
    proTrialNormalizeEmail('  John.Doe+news@Example.COM '));

same('N2. dots removed from the local part', 'john@gmail.com',
    proTrialNormalizeEmail('j.o.h.n@gmail.com'));

same('N3. only the first + cuts the local part', 'john@gmail.com',
    proTrialNormalizeEmail('john+a+b@gmail.com'));

same('N4. splits on the last @ only', '"a@b"@example.com',
    proTrialNormalizeEmail('"a@b"@example.com'));

same('N5. an empty local part after the tag cut is null', null,
    proTrialNormalizeEmail('+tag@example.com'));

same('N6. an empty local part after removing dots is null', null,
    proTrialNormalizeEmail('...@example.com'));

same('N7. an empty domain is null', null, proTrialNormalizeEmail('nodomain@'));

same('N8. no @ at all is null', null, proTrialNormalizeEmail('noat'));

same('N9. an empty string is null', null, proTrialNormalizeEmail(''));

// ---------------------------------------------------------------------------
// proTrialEmailHash()
// ---------------------------------------------------------------------------

$key = str_repeat('k', 32);

same('H1. normalisation happens before hashing',
    proTrialEmailHash('John.Doe+x@example.com', $key),
    proTrialEmailHash('johndoe@EXAMPLE.com', $key));

$hash = proTrialEmailHash('john@example.com', $key);
check('H2. the hash is 64 lowercase hex characters',
    is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
    var_export($hash, true));

check('H3. a different key gives a different hash',
    proTrialEmailHash('john@example.com', str_repeat('x', 32)) !== $hash);

same('H4. a 31-character key returns null', null,
    proTrialEmailHash('john@example.com', str_repeat('k', 31)));

check('H5. a 32-character key returns a hash',
    proTrialEmailHash('john@example.com', str_repeat('k', 32)) !== null);

check('H6. the hash does not contain the normalised address',
    is_string($hash) && strpos($hash, 'john@example.com') === false);

echo "\n" . ($passed + $failed) . " checks run, {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
