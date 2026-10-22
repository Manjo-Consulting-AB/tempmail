<?php
/**
 * Rewrites `cid:` references in a stored email's body to the signed
 * files.php URLs of its own attachments.
 *
 * A mail client embeds an inline image as an attachment with a Content-ID
 * and points at it with <img src="cid:...">. No browser or feed reader can
 * resolve cid:, and purifyEmailHtml() drops the scheme anyway, so the body
 * has to be rewritten to real URLs *before* it is purified. Shared by the
 * inbox (index.php get_email) and the RSS feed (pro_feed.php) so the two
 * cannot drift apart.
 *
 * Deliberately dependency-free: no config.php, no session, no echo. The
 * caller signs the URLs, because the inbox and the feed want different
 * lifetimes for them.
 */

if (!function_exists('emailCidNormalize')) {
    /** Lowercase alphanumerics only, for matching a cid that was mangled in transit. */
    function emailCidNormalize(string $cid): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $cid) ?? '');
    }
}

if (!function_exists('emailCidUrlMap')) {
    /**
     * content-id / filename -> URL, from attachment rows that carry `id`,
     * `download_url` and optionally `content_id` and `filename`.
     */
    function emailCidUrlMap(array $attachments): array
    {
        $cidMap = [];
        foreach ($attachments as $a) {
            $aid = (int)($a['id'] ?? 0);
            $signedUrl = (string)($a['download_url'] ?? '');
            if ($aid <= 0 || $signedUrl === '') continue;

            // Map by content_id (original and normalized)
            if (!empty($a['content_id'])) {
                $cid = trim((string)$a['content_id']);
                if ($cid !== '') {
                    $cidMap[$cid] = $signedUrl;
                    $normalizedCid = emailCidNormalize($cid);
                    if ($normalizedCid !== '' && $normalizedCid !== $cid) {
                        $cidMap[$normalizedCid] = $signedUrl;
                    }
                }
            }

            // Fallback: map by filename for clients that reference attachments by name
            $fn = (string)($a['filename'] ?? '');
            if ($fn !== '') {
                $base = pathinfo($fn, PATHINFO_FILENAME);
                if (preg_match('/^[A-Za-z0-9_-]{4,}$/', $base)) {
                    $cidMap[$base] = $signedUrl;
                    $normalizedBase = emailCidNormalize($base);
                    if ($normalizedBase !== '' && $normalizedBase !== $base) {
                        $cidMap[$normalizedBase] = $signedUrl;
                    }
                }
            }
        }
        return $cidMap;
    }
}

if (!function_exists('emailCidDbFallback')) {
    /**
     * The last-resort lookup for a cid the map did not match: the email's
     * attachment by content_id (with or without <>, or containing it), then
     * by filename. $signUrl turns an attachment id into its URL. Returns null
     * when nothing matches or the query fails (best effort).
     */
    function emailCidDbFallback(PDO $pdo, int $emailId, callable $signUrl): callable
    {
        return static function (string $token) use ($pdo, $emailId, $signUrl): ?string {
            try {
                $q = $pdo->prepare("SELECT id FROM email_attachments WHERE email_id = ? AND (content_id = ? OR content_id = ? OR content_id LIKE ?) LIMIT 1");
                $q->execute([$emailId, $token, '<' . $token . '>', '%' . $token . '%']);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['id'])) {
                    return $signUrl((int)$r['id']);
                }

                // Fallback: try matching filename (without extension)
                $base = trim($token, '"\'');
                $fn = pathinfo($base, PATHINFO_FILENAME);
                if ($fn !== '') {
                    $q2 = $pdo->prepare("SELECT id FROM email_attachments WHERE email_id = ? AND (filename = ? OR filename LIKE ? ) LIMIT 1");
                    $q2->execute([$emailId, $base, $fn . '.%']);
                    $r2 = $q2->fetch(PDO::FETCH_ASSOC);
                    if ($r2 && !empty($r2['id'])) {
                        return $signUrl((int)$r2['id']);
                    }
                }
            } catch (Exception $_) {
                // ignore DB errors in best-effort replacement
            }
            return null;
        };
    }
}

if (!function_exists('emailRewriteCidReferences')) {
    /**
     * Replaces every cid: reference in $body (plain, <...> or url-encoded)
     * that $cidMap - or, failing that, $fallback - resolves. A reference
     * nothing resolves is left unchanged.
     */
    function emailRewriteCidReferences(string $body, array $cidMap, ?callable $fallback = null): string
    {
        if ($body === '' || (empty($cidMap) && $fallback === null)) {
            return $body;
        }

        $replacer = static function (array $matches) use ($cidMap, $fallback): string {
            $raw = $matches[1] ?? '';
            // Decode potential url-encoded values
            $token = trim(rawurldecode($raw), "<> \t\n\r\0\x0B");

            // 1. Try direct mapping (exact match)
            if (isset($cidMap[$token])) {
                return $cidMap[$token];
            }

            // 2. Try normalized matching
            $normalizedToken = emailCidNormalize($token);
            if ($normalizedToken !== '' && isset($cidMap[$normalizedToken])) {
                return $cidMap[$normalizedToken];
            }

            // 3. Try case-insensitive match against all keys
            $lk = strtolower($token);
            foreach ($cidMap as $k => $v) {
                if (strtolower((string)$k) === $lk) {
                    return $v;
                }
            }

            // 4. As a last resort, ask the caller (index.php looks in the DB)
            if ($fallback !== null) {
                $url = $fallback($token);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }

            // No replacement found — return original match unchanged
            return $matches[0];
        };

        $result = preg_replace_callback('/cid:\s*<?([^>\s"\']+)>?/i', $replacer, $body);
        return $result ?? $body;
    }
}
