<?php

declare(strict_types=1);

/**
 * Regression coverage for getVisitorIp() and the helpers behind it in
 * ip_utils.php: forwarding headers are believed only when REMOTE_ADDR is a
 * trusted proxy (TRUSTED_PROXIES / TRUST_CLOUDFLARE), X-Forwarded-For is
 * walked from the right, and base64_payload alone never flags an IP.
 *
 * Run with:  php tests/visitor_ip_test.php
 *
 * Exits 0 when every check passes, 1 otherwise. Pure functions only: no
 * database, no network, no config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../ip_utils.php';

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

/** Runs getVisitorIp() against a given $_SERVER and environment. */
function visitorIpWith(array $server, string $trustedProxies = '', string $trustCloudflare = ''): string
{
    $saved = $_SERVER;
    $_SERVER = $server;
    $_ENV['TRUSTED_PROXIES'] = $trustedProxies;
    $_ENV['TRUST_CLOUDFLARE'] = $trustCloudflare;
    try {
        return getVisitorIp();
    } finally {
        $_SERVER = $saved;
        unset($_ENV['TRUSTED_PROXIES'], $_ENV['TRUST_CLOUDFLARE']);
    }
}

$cfEdge = '172.68.10.20';        // inside 172.64.0.0/13
$cfEdge6 = '2a06:98c0:3600::103'; // inside 2a06:98c0::/29

// ---------------------------------------------------------------------------
// No proxy configured: headers are never believed
// ---------------------------------------------------------------------------

same('A1. no proxy config + spoofed XFF -> REMOTE_ADDR',
    '203.0.113.7',
    visitorIpWith(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']));

same('A2. no proxy config + every spoofable header -> REMOTE_ADDR',
    '203.0.113.7',
    visitorIpWith([
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        'HTTP_X_REAL_IP' => '198.51.100.2',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.3',
        'HTTP_X_CLIENT_IP' => '198.51.100.4',
        'HTTP_CLIENT_IP' => '198.51.100.5',
    ]));

same('A3. no REMOTE_ADDR -> 0.0.0.0',
    '0.0.0.0',
    visitorIpWith(['HTTP_X_FORWARDED_FOR' => '198.51.100.3']));

same('A4. Cloudflare trust off + CF edge + CF header -> REMOTE_ADDR',
    $cfEdge,
    visitorIpWith(['REMOTE_ADDR' => $cfEdge, 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']));

// ---------------------------------------------------------------------------
// Trusted proxy + X-Forwarded-For
// ---------------------------------------------------------------------------

same('B1. trusted proxy + single XFF -> client',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], '10.0.0.5'));

same('B2. trusted proxy + spoofed left-most XFF -> right-most untrusted',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1, 1.2.3.4, 198.51.100.9'], '10.0.0.5'));

same('B3. two trusted hops (CIDR) are skipped',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9, 10.1.2.3'], '10.0.0.0/8'));

same('B4. untrusted REMOTE_ADDR ignores XFF even with TRUSTED_PROXIES set',
    '203.0.113.7',
    visitorIpWith(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], '10.0.0.5'));

same('B5. garbage right-most XFF entry -> REMOTE_ADDR',
    '10.0.0.5',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, not-an-ip'], '10.0.0.5'));

same('B6. trusted proxy, no XFF, X-Real-IP -> X-Real-IP',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_REAL_IP' => '198.51.100.9'], '10.0.0.5'));

same('B7. trusted proxy without any header -> REMOTE_ADDR',
    '10.0.0.5',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5'], '10.0.0.5'));

same('B8. XFF with IPv4 port suffix is accepted',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9:51234'], '10.0.0.5'));

same('B9. trusted proxy never honours CF header unless it is a Cloudflare edge',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], '10.0.0.5'));

// ---------------------------------------------------------------------------
// Cloudflare
// ---------------------------------------------------------------------------

same('C1. untrusted REMOTE_ADDR + CF header (Cloudflare on) -> REMOTE_ADDR',
    '203.0.113.7',
    visitorIpWith(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'], '', 'true'));

same('C2. Cloudflare on + CF edge + CF-Connecting-IP -> header',
    '198.51.100.1',
    visitorIpWith(['REMOTE_ADDR' => $cfEdge, 'HTTP_CF_CONNECTING_IP' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6'], '', 'true'));

same('C3. Cloudflare on + CF edge + invalid CF header -> falls back to XFF walk',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => $cfEdge, 'HTTP_CF_CONNECTING_IP' => 'bogus', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], '', '1'));

same('C4. TRUST_CLOUDFLARE=false is off',
    $cfEdge,
    visitorIpWith(['REMOTE_ADDR' => $cfEdge, 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'], '', 'false'));

same('C5. local proxy behind Cloudflare: XFF walk skips the CF hop',
    '198.51.100.1',
    visitorIpWith(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.1, ' . $cfEdge], '127.0.0.1', 'yes'));

// ---------------------------------------------------------------------------
// IPv6
// ---------------------------------------------------------------------------

same('D1. IPv6 REMOTE_ADDR, no proxy config -> REMOTE_ADDR',
    '2001:db8::7',
    visitorIpWith(['REMOTE_ADDR' => '2001:db8::7', 'HTTP_X_FORWARDED_FOR' => '::1']));

same('D2. Cloudflare IPv6 edge + IPv6 client header -> header',
    '2001:db8:abcd::1',
    visitorIpWith(['REMOTE_ADDR' => $cfEdge6, 'HTTP_CF_CONNECTING_IP' => '2001:db8:abcd::1'], '', 'true'));

same('D3. IPv6 trusted proxy CIDR + bracketed XFF entry',
    '2001:db8:abcd::1',
    visitorIpWith(['REMOTE_ADDR' => 'fd00::5', 'HTTP_X_FORWARDED_FOR' => '::1, [2001:db8:abcd::1]'], 'fd00::/8'));

same('D4. IPv6 exact entry matches a differently written address',
    '198.51.100.9',
    visitorIpWith(['REMOTE_ADDR' => 'FD00:0:0:0::5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], 'fd00::5'));

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

same('E1. parseTrustedProxies drops invalid entries and bad prefixes',
    ['10.0.0.5', '192.168.0.0/16', '2001:db8::/32'],
    parseTrustedProxies(' 10.0.0.5, nonsense, 192.168.0.0/16, 10.0.0.0/33, 1.2.3.4/, 2001:db8::/32, 2001:db8::/129 '));

same('E2. parseTrustedProxies of empty string',
    [],
    parseTrustedProxies(''));

check('E3. ipMatchesCidr IPv4 in range', ipMatchesCidr('192.168.4.5', '192.168.0.0/16'));
check('E4. ipMatchesCidr IPv4 out of range', !ipMatchesCidr('192.169.0.1', '192.168.0.0/16'));
check('E5. ipMatchesCidr out-of-range prefix does not throw and does not match', !ipMatchesCidr('1.2.3.4', '1.2.3.4/40'));
check('E6. ipMatchesCidr never mixes families', !ipMatchesCidr('::ffff:1.2.3.4', '1.2.3.0/24'));

same('F1. base64_payload alone never warrants a flag',
    [],
    patternsWarrantingIpFlag(['base64_payload']));

same('F2. other patterns still warrant a flag, base64_payload removed',
    ['sql_injection', 'path_traversal'],
    patternsWarrantingIpFlag(['sql_injection', 'base64_payload', 'path_traversal']));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
