<?php
/**
 * Pure IP helpers: CIDR matching and working out the visitor's real IP.
 *
 * Deliberately dependency-free - no config.php, no session, no DB - so
 * tests/visitor_ip_test.php can exercise it without MySQL. config.php
 * requires this file, so every existing call-site of getVisitorIp() and
 * ipMatchesCidr() keeps working unchanged. Every function is
 * function_exists-guarded so a repeat require (or a test harness that stubs
 * one of them) cannot redeclare it.
 *
 * Trust model: REMOTE_ADDR is the only address the client cannot forge. A
 * forwarding header (CF-Connecting-IP, X-Forwarded-For, X-Real-IP) is only
 * believed when REMOTE_ADDR itself is a proxy we trust:
 *   - TRUSTED_PROXIES  comma-separated IPs/CIDRs of our own proxies/load
 *                      balancers (empty by default = trust nobody);
 *   - TRUST_CLOUDFLARE true adds Cloudflare's published edge ranges below.
 * With neither set, every forwarding header is ignored.
 */

if (!function_exists('ipMatchesCidr')) {
    /**
     * Kontrollera om en IP-adress matchar ett CIDR-block.
     *
     * @param string $ip IP-adress att kontrollera
     * @param string $cidr CIDR-notation (t.ex. "192.168.0.0/16")
     * @return bool True om IP:n är inom CIDR-blocket
     */
    function ipMatchesCidr(string $ip, string $cidr): bool {
        // Hantera exakt matchning (ingen slash)
        if (strpos($cidr, '/') === false) {
            if ($ip === $cidr) {
                return true;
            }
            // Compare the binary form too, so "2001:DB8::1" matches "2001:db8::1".
            $a = @inet_pton($ip);
            $b = @inet_pton($cidr);
            return $a !== false && $b !== false && $a === $b;
        }

        list($subnet, $mask) = explode('/', $cidr, 2);
        if ($mask === '' || !ctype_digit($mask)) {
            return false;
        }
        $mask = (int)$mask;

        // Avgör om det är IPv6 eller IPv4
        $isIpv6 = strpos($ip, ':') !== false;
        $isSubnetIpv6 = strpos($subnet, ':') !== false;

        // Blanda inte IPv4 och IPv6
        if ($isIpv6 !== $isSubnetIpv6) {
            return false;
        }

        // An out-of-range prefix length would otherwise make the shift below
        // throw an ArithmeticError (negative shift) for IPv4.
        if ($mask > ($isIpv6 ? 128 : 32)) {
            return false;
        }

        if ($isIpv6) {
            // IPv6-hantering
            $ipBin = @inet_pton($ip);
            $subnetBin = @inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) {
                return false;
            }
            // Skapa mask för IPv6 (128 bitar)
            $maskBin = str_repeat("\xff", (int)($mask / 8));
            if ($mask % 8 > 0) {
                $maskBin .= chr(256 - pow(2, 8 - ($mask % 8)));
            }
            $maskBin = str_pad($maskBin, 16, "\x00");

            return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
        } else {
            // IPv4-hantering
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }
            if ($mask === 0) {
                return true;
            }
            $maskLong = -1 << (32 - $mask);
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }
    }
}

if (!function_exists('cloudflareIpRanges')) {
    /**
     * Cloudflare's published edge ranges (https://www.cloudflare.com/ips-v4
     * and https://www.cloudflare.com/ips-v6). Only used when
     * TRUST_CLOUDFLARE=true.
     *
     * Hardcoded on 2026-09-24 from the list as last known - the build
     * environment could not reach cloudflare.com to re-fetch it. Cloudflare
     * changes this list rarely, but re-check it against those two URLs when
     * touching this file. A missing range only means visitors arriving via
     * that range are seen as the Cloudflare IP (fail-safe), never that a
     * spoofed header is believed.
     *
     * @return string[]
     */
    function cloudflareIpRanges(): array {
        return [
            // IPv4
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // IPv6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
    }
}

if (!function_exists('ipInAnyRange')) {
    /**
     * @param string[] $ranges IPs or CIDRs
     */
    function ipInAnyRange(string $ip, array $ranges): bool {
        foreach ($ranges as $range) {
            if (ipMatchesCidr($ip, $range)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('parseTrustedProxies')) {
    /**
     * Parse a TRUSTED_PROXIES value ("10.0.0.5, 192.168.1.0/24, 2001:db8::/32")
     * into a list of IPs/CIDRs. Entries that are not a valid IP or a valid
     * IP/prefix are dropped, so a typo can never widen the trust.
     *
     * @return string[]
     */
    function parseTrustedProxies(string $value): array {
        $out = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $addr = $entry;
            $prefix = null;
            if (strpos($entry, '/') !== false) {
                list($addr, $prefix) = explode('/', $entry, 2);
            }
            if (!filter_var($addr, FILTER_VALIDATE_IP)) {
                continue;
            }
            if ($prefix !== null) {
                $max = strpos($addr, ':') !== false ? 128 : 32;
                if ($prefix === '' || !ctype_digit($prefix) || (int)$prefix > $max) {
                    continue;
                }
            }
            $out[] = $entry;
        }
        return $out;
    }
}

if (!function_exists('normaliseForwardedIp')) {
    /**
     * Clean one address taken from a forwarding header: trims it, strips an
     * IPv6 "[...]" wrapper and an IPv4 ":port" suffix, and returns it only if
     * FILTER_VALIDATE_IP accepts it.
     */
    function normaliseForwardedIp(string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d+)?$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $m)) {
            $value = $m[1];
        }
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }
}

if (!function_exists('resolveClientIp')) {
    /**
     * Work out the client IP from a $_SERVER-shaped array. Pure: no globals,
     * no environment, so it can be tested directly.
     *
     * - REMOTE_ADDR not a trusted proxy -> REMOTE_ADDR, headers ignored.
     * - REMOTE_ADDR a Cloudflare edge (and $trustCloudflare) -> a valid
     *   CF-Connecting-IP, which Cloudflare overwrites on every request.
     * - Otherwise X-Forwarded-For walked from the RIGHT: each hop is appended
     *   by the proxy that received it, so the right-most entries are the only
     *   ones our proxies vouch for. Trusted hops are skipped and the first
     *   untrusted address is the client. Anything to the left of it is
     *   client-supplied and never read.
     * - No usable XFF -> a valid X-Real-IP from the trusted proxy.
     * - Anything unparsable -> REMOTE_ADDR.
     *
     * @param array $server $_SERVER or a test double
     * @param string[] $trustedProxies IPs/CIDRs from TRUSTED_PROXIES
     */
    function resolveClientIp(array $server, array $trustedProxies, bool $trustCloudflare): string {
        $remote = normaliseForwardedIp((string)($server['REMOTE_ADDR'] ?? ''));
        if ($remote === null) {
            return '0.0.0.0';
        }

        $trusted = $trustedProxies;
        $cfRanges = $trustCloudflare ? cloudflareIpRanges() : [];
        if ($cfRanges) {
            $trusted = array_merge($trusted, $cfRanges);
        }

        if (!$trusted || !ipInAnyRange($remote, $trusted)) {
            return $remote;
        }

        // Directly from a Cloudflare edge: Cloudflare sets CF-Connecting-IP
        // itself, overwriting whatever the client sent.
        if ($cfRanges && ipInAnyRange($remote, $cfRanges)) {
            $cf = normaliseForwardedIp((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
            if ($cf !== null) {
                return $cf;
            }
        }

        $xff = (string)($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if (trim($xff) !== '') {
            $hops = explode(',', $xff);
            $candidate = $remote;
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $hop = normaliseForwardedIp($hops[$i]);
                if ($hop === null) {
                    // A garbage entry: stop, and fall back to the last hop a
                    // trusted proxy handed us rather than guess past it.
                    return $candidate;
                }
                $candidate = $hop;
                if (!ipInAnyRange($hop, $trusted)) {
                    return $hop;
                }
            }
            // Every hop was a trusted proxy: the left-most is the origin.
            return $candidate;
        }

        $realIp = normaliseForwardedIp((string)($server['HTTP_X_REAL_IP'] ?? ''));
        if ($realIp !== null) {
            return $realIp;
        }

        return $remote;
    }
}

if (!function_exists('visitorIpEnv')) {
    /** Read one env var the way config.php populates it ($_ENV + putenv). */
    function visitorIpEnv(string $name): string {
        $value = $_ENV[$name] ?? getenv($name);
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('getVisitorIp')) {
    /**
     * Hämta besökarens riktiga IP-adress.
     *
     * Forwarding headers are honoured only when REMOTE_ADDR is a trusted
     * proxy (TRUSTED_PROXIES / TRUST_CLOUDFLARE, see the file header).
     * Handles both IPv4 and IPv6.
     *
     * @return string IP-adress
     */
    function getVisitorIp(): string {
        $trustedProxies = parseTrustedProxies(visitorIpEnv('TRUSTED_PROXIES'));
        $trustCloudflare = filter_var(visitorIpEnv('TRUST_CLOUDFLARE'), FILTER_VALIDATE_BOOLEAN);
        return resolveClientIp($_SERVER, $trustedProxies, $trustCloudflare);
    }
}

if (!function_exists('patternsWarrantingIpFlag')) {
    /**
     * The subset of detectSuspiciousPatterns() hits that may lead to
     * flagMaliciousActivity() (and so an IP block). base64_payload matches any
     * run of 50+ alphanumerics - long tokens, webhook secrets, URLs - so it is
     * still logged and still rejects the input at the call-site, but it never
     * blocks an IP on its own.
     *
     * @param string[] $patterns
     * @return string[]
     */
    function patternsWarrantingIpFlag(array $patterns): array {
        return array_values(array_diff($patterns, ['base64_payload']));
    }
}
