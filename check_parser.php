<?php
// Quick helper to verify installed MIME parser libraries and autoload

// CLI only, like the other check_*.php scripts: over HTTP it would list the
// installed vendor packages and file paths to anyone.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

echo "== Parser check helper ==\n";
$autoload = __DIR__ . '/../vendor/autoload.php';
echo "vendor/autoload.php exists: " . (file_exists($autoload) ? 'yes' : 'no') . "\n";
if (file_exists($autoload)) {
    require_once $autoload;
}

$candidates = [
    'PhpMimeMailParser\\Parser',
    'PhpMimeMailParser\\MailMimeParser',
    'PhpMimeMailParser\\MailMimeParser\\Parser',
    'ZBateson\\MailMimeParser\\MailMimeParser',
    'ZBateson\\MailMimeParser\\MimeMessage',
    'MailMimeParser\\Parser'
];

foreach ($candidates as $class) {
    $exists = class_exists($class);
    echo sprintf("class_exists('%s') => %s\n", $class, $exists ? 'true' : 'false');
}

// List vendor packages that look like MIME/parsers
echo "\nScanning vendor for packages with 'mime' in name...\n";
$vendorDir = __DIR__ . '/../vendor';
if (!is_dir($vendorDir)) {
    echo "vendor/ directory not found\n";
    exit(0);
}

$it = new DirectoryIterator($vendorDir);
foreach ($it as $vendor) {
    if (!$vendor->isDir() || $vendor->isDot()) continue;
    $vendorName = $vendor->getFilename();
    $sub = new DirectoryIterator($vendor->getPathname());
    foreach ($sub as $pkg) {
        if (!$pkg->isDir() || $pkg->isDot()) continue;
        $pkgName = $pkg->getFilename();
        $path = $pkg->getPathname();
        // Inspect package name or composer.json for clues
        $composerJson = $path . '/composer.json';
        $found = false;
        if (stripos($vendorName . '/' . $pkgName, 'mime') !== false) $found = true;
        if (!$found && file_exists($composerJson)) {
            $json = json_decode(file_get_contents($composerJson), true);
            $packageName = $json['name'] ?? ($vendorName . '/' . $pkgName);
            if (stripos($packageName, 'mime') !== false) $found = true;
        }
        if ($found) {
            echo " - detected package: {$vendorName}/{$pkgName}\n";
            // list php files in src
            $src = $path . '/src';
            if (is_dir($src)) {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));
                foreach ($files as $f) {
                    if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                        echo "    src: " . substr($f->getPathname(), strlen(__DIR__ . '/../')) . "\n";
                    }
                }
            }
        }
    }
}

echo "\nDone. Run: php src/check_parser.php\n";
