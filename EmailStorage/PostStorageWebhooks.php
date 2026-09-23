<?php

declare(strict_types=1);

// Internal component, not a page: a direct HTTP request must produce nothing.
if (!defined('TEMPMAIL_APP')) {
    http_response_code(403);
    exit;
}

@require_once __DIR__ . '/EmailStorage.php';

/**
 * Pro webhooks as a post-storage consumer of the Email Storage service
 * (epic #169, step 8/10, #196).
 *
 * Until #196 every ingestion path built the webhook payload itself and called
 * ImapProcessor::dispatchWebhooks() after its own store() call. That is the
 * shape that let the DirectAdmin pipe ship without the call at all: Pro
 * webhooks — Pushover included — stayed silent for roughly eight months for
 * every address whose forwarder had switched over (#188). Registering the
 * consumer once, on the storage service, is what removes that class of mistake:
 * every email the service stores is reported, whichever path stored it.
 *
 * The service stays free of webhook knowledge. It runs whatever callables were
 * registered with it (EmailStorage::onStored()) once a result is `stored`, each
 * inside its own try/catch; this file is the only place that names
 * dispatchWebhooks().
 *
 * The payload is the one the paths built before, unchanged: `to`, `from`,
 * `subject`, `body` (HTML when there is one, otherwise text), `received_at` and
 * `temp_email_id`. Entitlement and the Pushover per-address filtering (#174)
 * stay inside dispatchWebhooks(), untouched.
 */
final class PostStorageWebhooks
{
    /**
     * Register the webhook consumer on a storage service. Call it once, right
     * after constructing the service for an ingestion path.
     */
    public static function attach(EmailStorage $storage, array $config, PDO $pdo, bool $debug = false): void
    {
        $storage->onStored(static function (array $stored) use ($config, $pdo, $debug): void {
            $proUserId = $stored['pro_user_id'] ?? null;
            if ($proUserId === null) {
                // A temporary address belongs to no Pro account and therefore
                // has no webhooks. dispatchWebhooks() would find no hooks for
                // it anyway; returning here keeps that common case free of the
                // class load and the database work.
                return;
            }

            // Loaded here rather than at the top of the file: this consumer is
            // attached by parse.php, which has no other use for the processor
            // class that holds the webhook dispatch.
            require_once __DIR__ . '/../php_imap_processor.php';

            $processor = new ImapProcessor($config, $pdo, $debug);
            $processor->dispatchWebhooks((int)$proUserId, [
                'to' => $stored['to_address'],
                'from' => $stored['from_address'],
                'subject' => $stored['subject'],
                'body' => $stored['body_html'] ?? $stored['body_text'],
                'received_at' => $stored['received_at'],
                'temp_email_id' => $stored['temp_email_id'],
            ]);
        });
    }
}
