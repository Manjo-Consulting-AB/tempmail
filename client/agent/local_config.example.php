<?php
// Copy this file to a path outside web-root and set CLIENT_AGENT_LOCAL_CONFIG_PATH to that path.
// Keep key_file outside web-root as well.
return [
    'key_file' => '/etc/tempmail/client-agent.key',
    'imap_server_encrypted' => 'BASE64_NONCE_CIPHERTEXT_TAG',
    'imap_user_encrypted' => 'BASE64_NONCE_CIPHERTEXT_TAG',
    'imap_password_encrypted' => 'BASE64_NONCE_CIPHERTEXT_TAG',
];
