<?php

declare(strict_types=1);

/**
 * migrate_pushover_credentials.php — CLI migration: encrypt the Pushover
 * token and user key already stored in pro_webhooks.config.
 *
 * New hooks are stored encrypted by pro_profile.php (webhookConfigSeal() in
 * webhook_secret.php); this seals the ones created before that. Delivery reads
 * both forms, so it can run before or after the deploy, and a re-run changes
 * nothing (already-sealed values are skipped). Refuses to run without
 * WEBHOOKS_KEY, and checks every sealed row opens again before it is written.
 *
 * Usage: php migrate_pushover_credentials.php [--dry-run]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('TEMPMAIL_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/webhook_secret.php';

$dryRun = in_array('--dry-run', $argv, true);

if (webhookSecretKey() === null) {
    fwrite(STDERR, "WEBHOOKS_KEY is not set - nothing can be encrypted. Set it first.\n");
    exit(2);
}

$rows = $pdo->query("SELECT id, config FROM pro_webhooks WHERE kind = 'pushover'")->fetchAll(PDO::FETCH_ASSOC);
$sealed = 0;
$already = 0;
$skipped = 0;

$update = $pdo->prepare('UPDATE pro_webhooks SET config = ? WHERE id = ?');
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $cfg = json_decode((string) $row['config'], true);
    if (!is_array($cfg)) {
        echo "hook {$id}: config is not a JSON object - skipped\n";
        $skipped++;
        continue;
    }
    $next = webhookConfigSeal($cfg, 'pushover');
    if ($next === null) {
        fwrite(STDERR, "hook {$id}: could not be encrypted - stopping, nothing more is written\n");
        exit(1);
    }
    if ($next === $cfg) {
        $already++;
        continue;
    }
    // Never write a value that would not open again.
    if (webhookConfigOpen($next, 'pushover') != $cfg) {
        fwrite(STDERR, "hook {$id}: round-trip check failed - stopping, nothing more is written\n");
        exit(1);
    }
    if (!$dryRun) {
        $update->execute([json_encode($next), $id]);
    }
    echo "hook {$id}: " . ($dryRun ? 'would be sealed' : 'sealed') . "\n";
    $sealed++;
}

printf("%d Pushover hook(s): %d %s, %d already sealed, %d skipped.\n",
    count($rows), $sealed, $dryRun ? 'to seal (dry run)' : 'sealed', $already, $skipped);
exit(0);
