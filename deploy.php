<?php

/**
 * Enhanced Self-Contained Deployment Script for Laravel
 * 
 * Handles ZIP creation, FTP upload, and dynamic remote extraction.
 * Location on server: public/deploy_extract.php
 */

set_time_limit(0);

// Load environment variables from .env
function loadEnv($path)
{
    if (!file_exists($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $env[trim($name)] = trim($value, '"\' ');
    }
    return $env;
}

$root = __DIR__;
$env = loadEnv($root . '/.env.prod');

$config = [
    'ftp_host' => $env['FTP_HOST'] ?? '',
    'ftp_user' => $env['FTP_USERNAME'] ?? '',
    'ftp_pass' => $env['FTP_PASSWORD'] ?? '',
    'ftp_port' => $env['FTP_PORT'] ?? 21,
    'ftp_root' => $env['FTP_ROOT'] ?? '',
    'cron_token' => $env['CRON_TOKEN'] ?? '',
    'app_url' => rtrim($env['APP_URL'] ?? '', '/'),
];

if (empty($config['ftp_host']) || empty($config['ftp_user'])) {
    exit("ERROR: FTP configuration missing in .env.prod\n");
}

function logMsg($msg) {
    echo "[" . date('H:i:s') . "] $msg\n";
}

function runCommand($command) {
    logMsg("Running local: $command");
    $output = shell_exec($command . ' 2>&1');
    if ($output) echo $output . "\n";
    return $output;
}

// 1. Determine Deployment Type
$isFirstTime = false;
$manifestPath = $root . '/.deploy_manifest.json';

if (isset($argv[1]) && $argv[1] === '--first-time') {
    $isFirstTime = true;
} else if (!file_exists($manifestPath)) {
    echo "No deployment manifest found. Is this a first-time deployment? (yes/no) [no]: ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim(strtolower($line)) === 'yes' || trim(strtolower($line)) === 'y') {
        $isFirstTime = true;
    }
    fclose($handle);
}

logMsg($isFirstTime ? "Starting FULL deployment..." : "Starting INCREMENTAL deployment...");

// 2. Prepare Local Environment
runCommand('php artisan config:clear');
runCommand('php artisan view:clear');
runCommand('php artisan route:clear');
runCommand('npm run build');

// Ensure required directories exist locally to be included
$requiredDirs = [
    'storage/app/public',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
];
foreach ($requiredDirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) mkdir($path, 0775, true);
    if (!file_exists($path . '/.gitkeep')) file_put_contents($path . '/.gitkeep', '');
}

// 3. Identify Files to Zip
$timestamp = date('Ymd_His');
$zipFilename = "deploy_{$timestamp}.zip";
$zipFile = $root . '/' . $zipFilename;
if (file_exists($zipFile)) unlink($zipFile);

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE) !== TRUE) {
    exit("ERROR: Could not create zip archive\n");
}

$excludedDirs = [
    '.git', '.github', '.playwright-mcp', 'node_modules', 'tests',
    'storage/logs', 'storage/framework/sessions',
    'storage/framework/cache/data'
];


$excludedFiles = [
    '.env', '.env.prod', '.env.example', 'deploy.php', 'remote_reset.php', $zipFilename,
    '.editorconfig', '.gitattributes', '.gitignore', 'package.json',
    'package-lock.json', 'phpunit.xml', 'vite.config.js', 'tailwind.config.js.bak',
    'README.md', 'README.docx', 'STYLE_GUIDE.md', 'ftp-creds.txt', 'public/hot',
    '.deploy_manifest.json'
];

if (!$isFirstTime) {
    $excludedDirs[] = 'vendor';
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

// Load previous manifest
$manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
$newManifest = $manifest; // Keep existing values by default
$count = 0;

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;

    $filePath = $file->getRealPath();
    $relativePath = str_replace($root . DIRECTORY_SEPARATOR, '', $filePath);
    $unixPath = str_replace('\\', '/', $relativePath);

    $skip = false;
    foreach ($excludedDirs as $dir) {
        if (strpos($unixPath, $dir . '/') === 0 || $unixPath === $dir) { $skip = true; break; }
    }
    if ($skip) continue;

    if (in_array(basename($unixPath), $excludedFiles) || in_array($unixPath, $excludedFiles)) continue;
    if (strpos(basename($unixPath), '.env') === 0) continue;

    // Incremental logic: check file content hash
    $fileHash = md5_file($filePath);
    $storedHash = $manifest[$unixPath] ?? '';

    if ($isFirstTime || $fileHash !== $storedHash) {
        $zip->addFile($filePath, $relativePath);
        $count++;
    }
    
    // Update manifest for this file
    $newManifest[$unixPath] = $fileHash;
}

if ($count === 0) {
    $zip->close();
    if (file_exists($zipFile)) unlink($zipFile);
    logMsg("No changes detected. Deployment skipped.");
    exit;
}

if (!$zip->close()) {
    exit("ERROR: Could not finalize ZIP archive.\n");
}
logMsg("ZIP archive created with $count changed files: $zipFilename");

// 4. FTP Upload
logMsg("Connecting to FTP: " . $config['ftp_host']);
$conn = ftp_connect($config['ftp_host'], $config['ftp_port']);
if (!$conn || !@ftp_login($conn, $config['ftp_user'], $config['ftp_pass'])) {
    exit("ERROR: FTP Connection or Login failed\n");
}
ftp_pasv($conn, true);

$remoteRoot = trim($config['ftp_root'], '/');
$remoteZip = ($remoteRoot ? $remoteRoot . '/' : '') . $zipFilename;
$remoteHelper = ($remoteRoot ? $remoteRoot . '/' : '') . 'public/deploy_extract.php';

logMsg("Uploading $zipFilename...");
if (!ftp_put($conn, $remoteZip, $zipFile, FTP_BINARY)) {
    ftp_close($conn);
    exit("ERROR: Failed to upload $zipFilename\n");
}

// Create and upload the remote extractor helper
logMsg("Uploading remote extractor (deploy_extract.php)...");
$extractCode = <<<PHP
<?php
/**
 * Remote Deployment Helper
 */

\$zipFile = __DIR__ . '/../$zipFilename';
\$extractTo = __DIR__ . '/../';

if (!file_exists(\$zipFile)) {
    die("Archive '$zipFilename' not found at: " . realpath(\$zipFile));
}

echo "Initializing remote deployment...\\n";
\$artisan = __DIR__ . '/../artisan';

// 1. Put app in maintenance mode FIRST
if (file_exists(\$artisan)) {
    echo "Putting application in maintenance mode...\\n";
    echo shell_exec("php \$artisan down 2>&1");
}


echo "Starting extraction...\\n";
\$zip = new ZipArchive;
if (\$zip->open(\$zipFile) === TRUE) {
    \$zip->extractTo(\$extractTo);
    \$zip->close();
    unlink(\$zipFile);
    echo "Extraction successful. $zipFilename removed.\\n";
    
    // 2. Run remaining maintenance tasks
    echo "Running post-extraction tasks...\\n";
    if (file_exists(\$artisan)) {
        echo shell_exec("php \$artisan config:clear 2>&1");
        echo shell_exec("php \$artisan cache:clear 2>&1");
        echo shell_exec("php \$artisan view:clear 2>&1");
        echo shell_exec("php \$artisan route:clear 2>&1");
        echo shell_exec("php \$artisan route:cache 2>&1");
        echo shell_exec("php \$artisan config:cache 2>&1");
        echo shell_exec("php \$artisan migrate --force 2>&1");
        echo shell_exec("php \$artisan db:seed --force 2>&1");
        echo shell_exec("php \$artisan up 2>&1");
        echo "Artisan commands completed. Application is back online.\\n";
    }
} else {
    // If extraction fails, try to bring app back up
    if (file_exists(\$artisan)) {
        shell_exec("php \$artisan up 2>&1");
    }
    http_response_code(500);
    die("Failed to open zip archive.");
}
PHP;

$tempFile = tempnam(sys_get_temp_dir(), 'deploy');
file_put_contents($tempFile, $extractCode);

if (!ftp_put($conn, $remoteHelper, $tempFile, FTP_BINARY)) {
    unlink($tempFile);
    ftp_close($conn);
    exit("ERROR: Failed to upload deploy_extract.php\n");
}
unlink($tempFile);

// 5. Trigger Remote Extraction
logMsg("Triggering remote extraction via HTTP...");
$extractUrl = $config['app_url'] . "/deploy_extract.php";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $extractUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 DeploymentScript/1.0');
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 200) {
    logMsg("Remote Output:\n$res");
    // Save the new manifest only on successful remote extraction
    file_put_contents($manifestPath, json_encode($newManifest, JSON_PRETTY_PRINT));
    logMsg("Manifest updated locally.");
} else {
    logMsg("Remote Error (HTTP $httpCode):\n$res");
}

// 6. Cleanup Remote Helper
logMsg("Cleaning up remote helper...");
if (ftp_delete($conn, $remoteHelper)) {
    logMsg("Remote helper 'deploy_extract.php' deleted successfully.");
} else {
    logMsg("Warning: Failed to delete remote helper.");
}

ftp_close($conn);

// Final Cleanup local
if (file_exists($zipFile)) unlink($zipFile);

logMsg("Deployment finished successfully.");
