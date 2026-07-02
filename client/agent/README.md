# Agent scaffold

The agent is the local component that will eventually:

- load settings from disk
- receive signed webhooks from the backend
- verify webhook signatures before applying updates
- apply whitelist/blacklist/greylist rules against IMAP mail
- log local actions

The current scaffold provides a runnable bootstrap and a simple entrypoint for later expansion.

If you want a non-technical setup walkthrough, use:

- `INSTALL_NON_TECHNICAL.md`

## Current behavior

The agent will accept webhook updates, verify them with the shared secret, save the new settings to disk and then run its normal cycle if requested.

## Deployment mode

For client installations, `agent.php` can be deployed as a single file.
It contains embedded runtime logic and can run even if `bootstrap.php` is not present on the client server.
By default, `agent.php` uses its embedded logic even if a `bootstrap.php` file exists.
Only set `CLIENT_AGENT_USE_EXTERNAL_BOOTSTRAP=1` if you intentionally want to load external bootstrap code.

## IMAP credentials model (required)

IMAP credentials must not be sent from backend to client in webhook payloads.

The client owner must configure IMAP credentials locally in an encrypted config file and keep the decryption key outside web-root.

Default behavior: installer writes private root + default script id into `agent.php`.

Optional environment variables (override):

- `CLIENT_AGENT_PRIVATE_ROOT`
- `CLIENT_AGENT_LOCAL_CONFIG_PATH`

The local config file must return an array with these keys:

- `key_file`
- `imap_server_encrypted`
- `imap_user_encrypted`
- `imap_password_encrypted`

`key_file` must point to a file outside web-root containing a 32-byte key (plain 32 chars, base64 32-byte, or 64-char hex).

Encryption format for each encrypted field is base64 of:

- 12-byte nonce + ciphertext + 16-byte GCM tag

Cipher:

- AES-256-GCM

### Should IMAP user/email be encrypted too?

Yes. Encrypt all three fields:

- `imap_server`
- `imap_user` (email/login)
- `imap_password`

Reason: even if `imap_user` is less sensitive than password, it is still account metadata and should not be exposed in a config file under web-served paths.

### Recommended setup steps

1. Preferred: run installer from CLI in same folder as `agent.php` and `install.php`:

```bash
php install.php --install
```

The installer will ask which `script_id` to configure.

2. Installer creates key + encrypted local config and writes private root + default script id into `agent.php`.
	It stores the private config and script map in a private directory outside web-root.
	For multi-script setups, it writes a `script_id -> config path` map there.
	The suggested secure directory is derived from the home/document-root parent of the server you run it on.

3. Manual fallback: generate a 32-byte key outside web-root:

```bash
# Run this from website root (for example public_html)
mkdir -p ../tempmail
openssl rand -hex 32 > ../tempmail/client-agent.key
chmod 600 ../tempmail/client-agent.key
```

4. Manual fallback: use helper script to encrypt values:

```bash
php src/client/agent/tools/encrypt_local_config.php \
	--key-file=../tempmail/client-agent.key \
	--imap-server='{imap.example.com:993/imap/ssl}INBOX' \
	--imap-user='user@example.com' \
	--imap-password='super-secret-password'
```

5. Manual fallback: copy output values into local config file (outside web-root), for example:

```php
<?php
return [
	'key_file' => __DIR__ . '/client-agent.key',
	'imap_server_encrypted' => '...',
	'imap_user_encrypted' => '...',
	'imap_password_encrypted' => '...',
];
```

6. Optional: set environment variable on client server:

```bash
export CLIENT_AGENT_LOCAL_CONFIG_PATH=/home/<YOUR_USER>/tempmail/client-agent.local.php
```

### Security notes

- Keep both key file and local config outside web-root.
- Set strict file permissions (owner read/write only).
- Rotate key by re-encrypting all three fields with a new key and updating `key_file`.
- Never send IMAP credentials through backend webhook payloads.
