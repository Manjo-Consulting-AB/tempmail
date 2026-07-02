# Client Agent installation for non-technical users

This guide helps you install the client safely, without coding.

## What you will do

1. Upload two files: `agent.php` and `install.php` to your server
2. Run one installer command in SSH
3. Follow on-screen questions
4. Run a test

## Before you start

You need:

- SSH or terminal access to your server
- PHP CLI available (`php -v` should work)
- IMAP details from your email provider:
  - IMAP server string
  - IMAP user/login
  - IMAP password

## Step 1: Upload one file

Upload this file to your server:

- `agent.php`
- `install.php`

## Step 2: Run the installer (recommended)

In SSH, go to the folder where `agent.php` exists and run:

```bash
php install.php --install
```

The installer will ask which `script_id` to configure.

The installer will:

- create key file outside web-root
- ask for IMAP host, port, user and password
- encrypt all values
- create one local config file per script id outside web-root
- write private root + default script id into `agent.php` header config

The installer will save the private files in a folder outside web-root, such as one step above your public folder.

The default secure directory path is derived from the folder you run the installer from, so on shared hosting it will suggest a folder next to your web root rather than a hardcoded system path.

For multiple scripts on the same server:

- run installer once per `script_id`
- each script gets its own encrypted IMAP config

## Step 3: Optional environment variable

Most users do not need this step. The installer writes private root settings into `agent.php`.

If your host supports env vars and you want explicit config, use:

```bash
CLIENT_AGENT_LOCAL_CONFIG_PATH=/home/<YOUR_USER>/tempmail/client-agent.local.php
```

## Step 4: Test

In the web app:

1. Open Client Agent page
2. Select script
3. Click Run cycle
4. Check Live status

---

## Manual fallback (advanced)

Use this only if you cannot run `php install.php --install`.

### Step A: Create a secret key file

Run these commands on your server:

```bash
# Run this from your website root (for example public_html)
mkdir -p ../tempmail
openssl rand -hex 32 > ../tempmail/client-agent.key
chmod 600 ../tempmail/client-agent.key
```

Important:

- Keep this file private.
- Do not place it inside your public website folders.

### Step B: Encrypt your IMAP values

Run this command and replace values in `<>`:

```bash
php /path/to/project/src/client/agent/tools/encrypt_local_config.php \
  --key-file=../tempmail/client-agent.key \
  --imap-server='<IMAP_SERVER>' \
  --imap-user='<IMAP_USER>' \
  --imap-password='<IMAP_PASSWORD>'
```

The command prints 3 encrypted lines.

### Step C: Create local encrypted config file

Create file:

`../tempmail-private/client-agent-<SCRIPT_ID>.local.php`

Paste this template and replace `...` with encrypted output from Step 2:

```php
<?php
return [
    'key_file' => __DIR__ . '/client-agent.key',
    'imap_server_encrypted' => '...',
    'imap_user_encrypted' => '...',
    'imap_password_encrypted' => '...',
];
```

Then lock permissions:

```bash
chmod 600 ../tempmail-private/client-agent-<SCRIPT_ID>.local.php
```

### Step D: Tell client where config is

Set this environment variable in your web server/PHP environment:

```bash
CLIENT_AGENT_LOCAL_CONFIG_PATH=/home/<YOUR_USER>/tempmail/client-agent.local.php
```

If you use Apache or Nginx + PHP-FPM, set this in your server config or pool config.

Tip for shared hosting: the installer will usually suggest a folder like `/home/username/tempmail-private`.

### Step E: Test

Run the same test steps described in Step 4 above.

If it fails, check:

- key file path
- config file path
- file permissions
- IMAP values

## Common mistakes

- Putting key/config inside web root
- Wrong file permissions
- Wrong IMAP server format
- Using old encrypted values after rotating key

## Security summary

- Encrypt all 3 IMAP fields (`server`, `user`, `password`)
- Keep both files outside web root
- Keep file permissions strict (`600`)
- Rotate key by re-encrypting all 3 values
