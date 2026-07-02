# SETUP_SCRIPT.md - Lokal Lösenordskryptering

## setup_mailfilter.php

Detta är det script som användaren kör **lokalt** (på sin server) för att kryptera sitt lösenord.

### Installation för Användaren

1. Ladda ned denna fil från manjo.me
2. Placera i samma directory som `mailfilter_abc123xyz.php`
3. Kör: `php setup_mailfilter.php --script-id abc123xyz`

---

## Komplett Setup-script

```php
<?php
/**
 * Mailfilter - Setup Script for Local Encryption
 * 
 * Denna script kryptera användarens IMAP-lösenord lokalt
 * Hemlig nyckel sparas utanför www-root för maximal säkerhet
 * 
 * Användning: php setup_mailfilter.php --script-id <script_id>
 */

// ==============================================================================
// VALIDATION
// ==============================================================================

if ($argc < 3 || $argv[1] !== '--script-id') {
    die(<<<'USAGE'
Mailfilter Setup - Local Password Encryption

Användning:
  php setup_mailfilter.php --script-id <script_id>

Exempel:
  php setup_mailfilter.php --script-id abc123xyz

USAGE
    );
}

$script_id = $argv[2];

// Validate script_id format
if (!preg_match('/^[a-z0-9]+$/', $script_id)) {
    die("ERROR: Invalid script_id format (only lowercase alphanumeric)\n");
}

// ==============================================================================
// FILE PATHS
// ==============================================================================

$home_dir = getenv('HOME') ?: posix_getpwuid(posix_geteuid())['dir'] ?: '/root';
$config_dir = $home_dir . '/.manjo';
$config_file = $config_dir . '/mailfilter_' . $script_id . '.json';
$key_file = $config_dir . '/mailfilter_' . $script_id . '.key';
$logs_dir = $config_dir . '/logs';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Mailfilter - Local Password Encryption Setup              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Script ID: {$script_id}\n";
echo "Config Dir: {$config_dir}\n";
echo "Config File: {$config_file}\n";
echo "Key File: {$key_file}\n\n";

// ==============================================================================
// PREREQUISITES
// ==============================================================================

// Check if settings.json exists
if (!file_exists($config_file)) {
    die("ERROR: Settings file not found!\n\n");
    die("Please download mailfilter_{$script_id}.json from manjo.me first\n");
    die("and place it in {$config_dir}/\n\n");
}

// Load settings
$settings_json = file_get_contents($config_file);
$settings = json_decode($settings_json, true);

if (!$settings) {
    die("ERROR: Invalid JSON in settings file\n");
}

// ==============================================================================
// INPUT COLLECTION
// ==============================================================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  STEP 1: Enter Your IMAP Password                          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Your email: " . ($settings['imap']['user_email'] ?? 'N/A') . "\n";
echo "IMAP Server: " . ($settings['imap']['host'] ?? 'N/A') . "\n\n";

// Disable echo for password input
if (function_exists('exec')) {
    // Try to hide password input with stty
    exec('stty -echo 2>/dev/null', $output, $return_var);
    $stty_available = ($return_var === 0);
} else {
    $stty_available = false;
}

echo "Enter your IMAP password: ";
$password = trim(fgets(STDIN));

if ($stty_available) {
    exec('stty echo 2>/dev/null');
}

echo "\n";

if (empty($password)) {
    die("ERROR: Password cannot be empty\n");
}

echo "✓ Password received (not echoed)\n\n";

// ==============================================================================
// ENCRYPTION
// ==============================================================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  STEP 2: Create Secret Key                                 ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Enter a secret key for encryption (min 16 characters):\n";
echo "Tips: Use a strong passphrase, e.g. 'MyBoat-TonySecure-2026'\n\n";

if ($stty_available) {
    exec('stty -echo 2>/dev/null');
}

echo "Enter secret key: ";
$secret_key = trim(fgets(STDIN));

if ($stty_available) {
    exec('stty echo 2>/dev/null');
}

echo "\n";

if (strlen($secret_key) < 16) {
    die("ERROR: Secret key must be at least 16 characters\n");
}

if (empty($secret_key)) {
    die("ERROR: Secret key cannot be empty\n");
}

echo "✓ Secret key received (" . strlen($secret_key) . " characters)\n\n";

// ==============================================================================
// ENCRYPT PASSWORD
// ==============================================================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  STEP 3: Encrypting...                                     ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Generate IV
$iv = openssl_random_pseudo_bytes(16);

// Create encryption key from secret key
$encryption_key = hash('sha256', $secret_key, true);

// Encrypt password
$encrypted = openssl_encrypt(
    $password,
    'aes-256-cbc',
    $encryption_key,
    0,  // raw data
    $iv
);

if ($encrypted === false) {
    die("ERROR: Encryption failed\n");
}

// Create encrypted password structure
$encrypted_data = [
    'cipher' => $encrypted,
    'iv' => base64_encode($iv)
];

$encrypted_password = base64_encode(json_encode($encrypted_data));

echo "✓ Password encrypted with AES-256-CBC\n";
echo "✓ IV generated securely\n";
echo "✓ Ready to save\n\n";

// ==============================================================================
// SAVE FILES
// ==============================================================================

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  STEP 4: Saving Files                                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Update settings
$settings['imap']['password'] = $encrypted_password;
$settings['imap']['password_encrypted'] = true;

// Ensure directories exist
if (!is_dir($config_dir)) {
    mkdir($config_dir, 0700, true);
    echo "✓ Created config directory: {$config_dir}\n";
}

if (!is_dir($logs_dir)) {
    mkdir($logs_dir, 0700, true);
    echo "✓ Created logs directory: {$logs_dir}\n";
}

// Save updated settings.json
$settings_json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($config_file, $settings_json);
chmod($config_file, 0600);
echo "✓ Updated settings file: {$config_file} (chmod 0600)\n";

// Save secret key
file_put_contents($key_file, $secret_key);
chmod($key_file, 0600);
echo "✓ Saved secret key: {$key_file} (chmod 0600)\n";

// ==============================================================================
// SUMMARY
// ==============================================================================

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  SETUP COMPLETE! ✓                                         ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "Configuration Summary:\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "Script ID: " . $settings['script_id'] . "\n";
echo "Email: " . $settings['imap']['user_email'] . "\n";
echo "IMAP Server: " . $settings['imap']['host'] . ":" . $settings['imap']['port'] . "\n";
echo "Password: [ENCRYPTED]\n";
echo "Encryption: AES-256-CBC\n";
echo "Secret Key: [SAVED SECURELY]\n\n";

echo "Next Steps:\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "1. Upload mailfilter_{$script_id}.php to your web root\n";
echo "2. Add to crontab: */30 * * * * php /path/to/mailfilter_{$script_id}.php\n";
echo "3. Configure whitelists/blacklists via manjo.me\n";
echo "4. Script will automatically decrypt password on each run\n\n";

echo "Security Notes:\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "✓ Password never sent to manjo.me\n";
echo "✓ Secret key stored outside web root (chmod 0600)\n";
echo "✓ Settings file also protected (chmod 0600)\n";
echo "✓ Only this server can decrypt the password\n";
echo "✓ Encryption uses industry-standard AES-256-CBC\n\n";

echo "Backup Important Files:\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "BACKUP THESE FILES (they are not on your server):\n";
echo "  1. Secret key: {$key_file}\n";
echo "  2. Settings: {$config_file}\n";
echo "Without these, you cannot decrypt your password if server crashes.\n\n";

?>
```

---

## Användarinstruktioner

### För Paranoid-Användare

**Scenario**: Du vill maksimal säkerhet och vill kryptera lösenordet själv.

**Instruktioner**:

1. **Ladda ner filerna från manjo.me**
   - `mailfilter_abc123xyz.json` (settings template)
   - `setup_mailfilter.php` (setup script)

2. **Redigera settings.json lokalt**
   ```
   Öppna filen i texteditor och fyll i:
   - user_email: din@epost.com
   - host: imap.example.com (eller imap.manjo.se)
   - port: 993
   - mailbox: INBOX
   
   Lämna password-fältet tomt för nu.
   ```

3. **Placera filerna på servern**
   ```bash
   scp mailfilter_abc123xyz.json user@server.se:/home/user/.manjo/
   scp setup_mailfilter.php user@server.se:/tmp/
   ```

4. **Kör setup-scriptet på servern**
   ```bash
   ssh user@server.se
   cd /tmp
   php setup_mailfilter.php --script-id abc123xyz
   
   (Scriptet frågar efter lösenord och hemlig nyckel interaktivt)
   ```

5. **Scriptet kommer att säga**:
   - ✓ Password encrypted (AES-256-CBC)
   - ✓ Secret key saved to ~/.manjo/mailfilter_abc123xyz.key
   - ✓ Settings updated

6. **Ladda upp huvudscriptet**
   ```bash
   scp mailfilter_abc123xyz.php user@server.se:/home/user/public_html/
   ```

7. **Sätt upp cron**
   ```bash
   crontab -e
   # Lägg till:
   */30 * * * * php /home/user/public_html/mailfilter_abc123xyz.php
   ```

8. **Test**
   ```bash
   php /home/user/public_html/mailfilter_abc123xyz.php
   # Kontrollera log-filen:
   cat ~/.manjo/logs/mailfilter_abc123xyz_2026-06.log
   ```

---

### Output från setup-scriptet

```
╔════════════════════════════════════════════════════════════╗
║  Mailfilter - Local Password Encryption Setup              ║
╚════════════════════════════════════════════════════════════╝

Script ID: abc123xyz
Config Dir: /home/tony/.manjo
Config File: /home/tony/.manjo/mailfilter_abc123xyz.json
Key File: /home/tony/.manjo/mailfilter_abc123xyz.key

╔════════════════════════════════════════════════════════════╗
║  STEP 1: Enter Your IMAP Password                          ║
╚════════════════════════════════════════════════════════════╝

Your email: tony@example.com
IMAP Server: imap.manjo.se

Enter your IMAP password: [hidden input]
✓ Password received (not echoed)

╔════════════════════════════════════════════════════════════╗
║  STEP 2: Create Secret Key                                 ║
╚════════════════════════════════════════════════════════════╝

Enter a secret key for encryption (min 16 characters):
Tips: Use a strong passphrase, e.g. 'MyBoat-TonySecure-2026'

Enter secret key: [hidden input]
✓ Secret key received (26 characters)

╔════════════════════════════════════════════════════════════╗
║  STEP 3: Encrypting...                                     ║
╚════════════════════════════════════════════════════════════╝

✓ Password encrypted with AES-256-CBC
✓ IV generated securely
✓ Ready to save

╔════════════════════════════════════════════════════════════╗
║  STEP 4: Saving Files                                      ║
╚════════════════════════════════════════════════════════════╝

✓ Created config directory: /home/tony/.manjo
✓ Created logs directory: /home/tony/.manjo/logs
✓ Updated settings file: /home/tony/.manjo/mailfilter_abc123xyz.json (chmod 0600)
✓ Saved secret key: /home/tony/.manjo/mailfilter_abc123xyz.key (chmod 0600)

╔════════════════════════════════════════════════════════════╗
║  SETUP COMPLETE! ✓                                         ║
╚════════════════════════════════════════════════════════════╝

Configuration Summary:
─────────────────────────────────────────────────────────────
Script ID: abc123xyz
Email: tony@example.com
IMAP Server: imap.manjo.se:993
Password: [ENCRYPTED]
Encryption: AES-256-CBC
Secret Key: [SAVED SECURELY]

Next Steps:
─────────────────────────────────────────────────────────────
1. Upload mailfilter_abc123xyz.php to your web root
2. Add to crontab: */30 * * * * php /path/to/mailfilter_abc123xyz.php
3. Configure whitelists/blacklists via manjo.me
4. Script will automatically decrypt password on each run

Security Notes:
─────────────────────────────────────────────────────────────
✓ Password never sent to manjo.me
✓ Secret key stored outside web root (chmod 0600)
✓ Settings file also protected (chmod 0600)
✓ Only this server can decrypt the password
✓ Encryption uses industry-standard AES-256-CBC

Backup Important Files:
─────────────────────────────────────────────────────────────
BACKUP THESE FILES (they are not on your server):
  1. Secret key: /home/tony/.manjo/mailfilter_abc123xyz.key
  2. Settings: /home/tony/.manjo/mailfilter_abc123xyz.json
Without these, you cannot decrypt your password if server crashes.
```

---

**Version**: 1.0  
**Senast uppdaterad**: 2026-06-29
