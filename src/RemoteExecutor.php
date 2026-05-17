<?php

namespace Cyberwizard\LaravelFtpDeployer;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class RemoteExecutor
{
    private array $config;
    private $ftpConn;
    private array $exclusions = ['files' => [], 'directories' => []];
    private array $postExtractionCommands = [];
    private array $customCommands = [];
    private string $manifestPath;

    public function __construct(array $config)
    {
        $this->config = [
            'ftp_host' => $config['ftp_host'] ?? '',
            'ftp_user' => $config['ftp_user'] ?? '',
            'ftp_pass' => $config['ftp_pass'] ?? '',
            'ftp_port' => (int) ($config['ftp_port'] ?? 21),
            'ftp_root' => rtrim($config['ftp_root'] ?? '', '/'),
            'app_url'  => rtrim($config['app_url'] ?? '', '/'),
            'ftp_ssl'  => $config['ftp_ssl'] ?? true, // Default to attempting secure connection
        ];

        $this->manifestPath = getcwd() . '/.deploy_manifest.json';
        $this->ensureConfigExists();
        $this->loadConfig();
    }

    private function ensureConfigExists(): void
    {
        $configFile = getcwd() . '/deploy.json';
        if (!file_exists($configFile)) {
            $defaultConfig = [
                '_comment' => "Laravel FTP Deployer Configuration. 'exclude' defines files/folders to skip. 'post_extraction_commands' run automatically after a code sync. 'custom_commands' can be triggered manually via CLI.",
                'exclude' => [
                    'files' => [
                        '.env', '.env.prod', 'composer.lock', '.gitignore',
                        'README.md', 'deploy.json', '.deploy_manifest.json'
                    ],
                    'directories' => [
                        '.git', 'node_modules', 'vendor', 'storage/framework/cache',
                        'storage/framework/sessions', 'storage/framework/views',
                        'storage/logs', 'tests'
                    ]
                ],
                'post_extraction_commands' => [
                    'config:clear', 'cache:clear', 'view:clear', 'route:clear',
                    'route:cache', 'config:cache', 'migrate --force', 'up'
                ],
                'custom_commands' => [
                    'cache-clear' => ['optimize:clear'],
                    'db-migrate'  => ['migrate --force']
                ]
            ];
            file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT));
            throw new \Exception("Configuration file 'deploy.json' was missing and has been created. Please review it before running deployment.");
        }
    }

    private function loadConfig(): void
    {
        $configFile = getcwd() . '/deploy.json';
        if (file_exists($configFile)) {
            $data = json_decode(file_get_contents($configFile), true);
            if (!$data) return;

            if (isset($data['exclude'])) {
                $this->exclusions['files']       = $data['exclude']['files']       ?? $this->exclusions['files'];
                $this->exclusions['directories'] = $data['exclude']['directories'] ?? $this->exclusions['directories'];
            }

            $this->postExtractionCommands = $data['post_extraction_commands'] ?? ($data['remote_commands'] ?? $this->postExtractionCommands);
            $this->customCommands         = $data['custom_commands'] ?? $this->customCommands;
        }
    }

    public function getCustomCommand(string $name): ?array
    {
        return $this->customCommands[$name] ?? null;
    }

    private function fixLocalPermissions(): void
    {
        $cwd = getcwd();

        $targets = [
            $cwd . '/storage',
            $cwd . '/bootstrap/cache',
        ];

        $this->log("Fixing local permissions before zipping...", '34');

        foreach ($targets as $dir) {
            if (!is_dir($dir)) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @chmod($item->getRealPath(), 0775);
                } else {
                    @chmod($item->getRealPath(), 0664);
                }
            }

            @chmod($dir, 0775);

            $label = str_replace($cwd . '/', '', $dir);
            $this->log("  chmod 775/664 → $label", '36');
        }

        $artisan = $cwd . '/artisan';
        if (file_exists($artisan)) {
            @chmod($artisan, 0755);
            $this->log("  chmod 755    → artisan", '36');
        }
    }

    public function deploy(bool $isFirstTime = false, bool $dryRun = false): bool
    {
        $this->log("Preparing local environment...", '34');

        if (!$dryRun && file_exists(getcwd() . '/artisan')) {
            $this->log("Running locally: php artisan optimize:clear", '36');
            shell_exec("php artisan optimize:clear 2>&1");
            $this->fixLocalPermissions();
        } elseif ($dryRun) {
            $this->log("[DRY RUN] Would run local optimize:clear and fix permissions.", '36');
        }

        $this->log($isFirstTime ? "Starting FULL deployment..." : "Starting INCREMENTAL deployment...", '34');

        $timestamp   = date('Ymd_His');
        $zipFilename = "deploy_{$timestamp}.zip";
        $zipFile     = getcwd() . '/' . $zipFilename;
        if (file_exists($zipFile)) unlink($zipFile);

        $zip = null;
        if (!$dryRun) {
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
                $this->log("ERROR: Could not create ZIP archive.", '31');
                return false;
            }
        }

        $manifest    = file_exists($this->manifestPath) ? json_decode(file_get_contents($this->manifestPath), true) : [];
        $newManifest = $manifest;
        $count       = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(getcwd(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $filePath     = $file->getRealPath();
            $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $filePath);
            $unixPath     = str_replace('\\', '/', $relativePath);

            if ($this->isExcluded($unixPath, false, $isFirstTime) || basename($unixPath) === $zipFilename) continue;

            $currentHash = md5_file($filePath);
            $storedHash  = $manifest[$unixPath] ?? '';

            if ($isFirstTime || $currentHash !== $storedHash) {
                if (!$dryRun && $zip) {
                    $zip->addFile($filePath, $relativePath);
                }
                $count++;
                $this->log("  [Add] $unixPath", '36');
            }
            $newManifest[$unixPath] = $currentHash;
        }

        if ($count === 0) {
            if (!$dryRun && $zip) {
                $zip->close();
                if (file_exists($zipFile)) unlink($zipFile);
            }
            $this->log("No changes detected. Deployment skipped.", '33');
            return true;
        }

        if ($dryRun) {
            $this->log("\n[DRY RUN] $count files would be packed and uploaded.", '32');
            $this->log("[DRY RUN] The following commands would be executed remotely:", '34');
            foreach ($this->postExtractionCommands as $cmd) {
                $this->log("  - php artisan $cmd", '36');
            }
            return true;
        }

        $zip->close();
        $this->log("ZIP archive created with $count files: $zipFilename", '32');

        if (!$this->connect()) return false;

        $remoteZip = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . $zipFilename;
        $helperName = 'deploy_extract_' . bin2hex(random_bytes(8)) . '.php';
        $remoteHelper = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . 'public/' . $helperName;
        $token = bin2hex(random_bytes(32));

        $this->log("Uploading $zipFilename...", '34');
        if (!ftp_put($this->ftpConn, $remoteZip, $zipFile, FTP_BINARY)) {
            $this->log("ERROR: Failed to upload $zipFilename", '31');
            $this->close();
            return false;
        }

        $this->log("Uploading remote extractor...", '34');
        $helperCode = $this->generateExtractorCode($zipFilename, $token);
        if (!$this->uploadHelper($remoteHelper, $helperCode)) {
            $this->close();
            return false;
        }

        $this->log("Triggering remote extraction via HTTP...", '34');

        $urlsToTry = [
            $this->config['app_url'] . "/" . $helperName,
            $this->config['app_url'] . "/public/" . $helperName
        ];

        $success = false;
        foreach ($urlsToTry as $triggerUrl) {
            $this->log("Trying URL: $triggerUrl", '36');
            $response = $this->sendRequest($triggerUrl, $token);

            if ($response['code'] === 200) {
                $data = json_decode($response['body'], true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $this->log("Remote Execution Logs:", '32');
                    foreach ($data['logs'] ?? [] as $logLine) {
                        $this->log("  " . trim($logLine), '36');
                    }
                    file_put_contents($this->manifestPath, json_encode($newManifest, JSON_PRETTY_PRINT));
                    $this->log("Manifest updated locally.", '32');
                    $success = true;
                    break;
                } else {
                    $this->log("Remote returned error: " . ($data['message'] ?? 'Unknown JSON format'), '31');
                    $this->log("Raw output: " . $response['body'], '33');
                }
            } else {
                $this->log("Failed with HTTP " . $response['code'], '33');
            }
        }

        if (!$success) {
            $this->log("ERROR: Remote extraction failed.", '31');
        }

        $this->log("Cleaning up local ZIP...", '34');
        if (file_exists($zipFile)) unlink($zipFile);
        $this->close();

        return $success;
    }

    public function runCommands(array $commands, bool $dryRun = false): bool
    {
        if ($dryRun) {
            $this->log("[DRY RUN] Would execute the following commands remotely:", '34');
            foreach ($commands as $cmd) {
                $this->log("  - php artisan $cmd", '36');
            }
            return true;
        }

        if (!$this->connect()) return false;

        $helperName   = 'artisan_run_' . bin2hex(random_bytes(8)) . '.php';
        $remoteHelper = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . 'public/' . $helperName;
        $token = bin2hex(random_bytes(32));

        $this->log("Uploading temporary command runner...", '34');
        $helperCode = $this->generateCommandRunnerCode($commands, $token);

        if (!$this->uploadHelper($remoteHelper, $helperCode)) {
            $this->close();
            return false;
        }

        $this->log("Triggering commands via HTTP...", '34');

        $urlsToTry = [
            $this->config['app_url'] . "/" . $helperName,
            $this->config['app_url'] . "/public/" . $helperName
        ];

        $success = false;
        foreach ($urlsToTry as $triggerUrl) {
            $this->log("Trying URL: $triggerUrl", '36');
            $response = $this->sendRequest($triggerUrl, $token);

            if ($response['code'] === 200) {
                $data = json_decode($response['body'], true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $this->log("Remote Execution Logs:", '32');
                    foreach ($data['logs'] ?? [] as $logLine) {
                        $this->log("  " . trim($logLine), '36');
                    }
                    $success = true;
                    break;
                } else {
                    $this->log("Remote returned error: " . ($data['message'] ?? 'Unknown JSON format'), '31');
                    $this->log("Raw output: " . $response['body'], '33');
                }
            } else {
                $this->log("Failed with HTTP " . $response['code'], '33');
            }
        }

        if (!$success) {
            $this->log("ERROR: HTTP request failed on all attempted URLs.", '31');
        }

        $this->close();
        return $success;
    }

    private function isExcluded(string $path, bool $isDir, bool $isFirstTime = false): bool
    {
        if (str_contains($path, '.env') || basename($path) === 'deploy.json' || basename($path) === '.deploy_manifest.json') {
            return true;
        }

        if ($isFirstTime) {
            if (
                str_starts_with($path, '.git/')         ||
                str_starts_with($path, 'node_modules/') ||
                str_starts_with($path, 'tests/')
            ) {
                return true;
            }
            return false;
        }

        foreach ($this->exclusions['directories'] as $dir) {
            $normalizedDir = trim($dir, '/');
            if (str_starts_with($path, $normalizedDir . '/') || $path === $normalizedDir) {
                return true;
            }
        }
        foreach ($this->exclusions['files'] as $file) {
            if (basename($path) === $file || $path === $file) return true;
        }
        return false;
    }

    private function generateCommandRunnerCode(array $commands, string $token): string
    {
        $cmdsExport = var_export($commands, true);

        return <<<PHP
<?php
set_time_limit(0);
header('Content-Type: application/json');

\$expectedToken = '$token';
\$authHeader = \$_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty(\$authHeader) && function_exists('apache_request_headers')) {
    \$headers = apache_request_headers();
    \$authHeader = \$headers['Authorization'] ?? (\$headers['authorization'] ?? '');
}
\$providedToken = str_replace('Bearer ', '', \$authHeader);
if (empty(\$providedToken)) \$providedToken = \$_GET['token'] ?? '';

if (\$providedToken !== \$expectedToken) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

\$log = [];

register_shutdown_function(function() {
    @unlink(__FILE__);
});

try {
    require __DIR__ . '/../vendor/autoload.php';
    \$app = require_once __DIR__ . '/../bootstrap/app.php';
    \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
    \$kernel->bootstrap();

    \$commands = $cmdsExport;
    foreach (\$commands as \$cmd) {
        \$input = new \Symfony\Component\Console\Input\StringInput(\$cmd);
        \$output = new \Symfony\Component\Console\Output\BufferedOutput();
        \$kernel->handle(\$input, \$output);
        \$log[] = "Ran: \$cmd\\n" . trim(\$output->fetch());
    }

    echo json_encode(['status' => 'success', 'logs' => \$log]);

} catch (\Exception \$e) {
    echo json_encode(['status' => 'error', 'message' => 'Artisan error: ' . \$e->getMessage()]);
}
PHP;
    }

    private function generateExtractorCode(string $zipFilename, string $token): string
    {
        $cmdsExport = var_export($this->postExtractionCommands, true);

        return <<<PHP
<?php
set_time_limit(0);
header('Content-Type: application/json');

\$expectedToken = '$token';
\$authHeader = \$_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty(\$authHeader) && function_exists('apache_request_headers')) {
    \$headers = apache_request_headers();
    \$authHeader = \$headers['Authorization'] ?? (\$headers['authorization'] ?? '');
}
\$providedToken = str_replace('Bearer ', '', \$authHeader);
if (empty(\$providedToken)) \$providedToken = \$_GET['token'] ?? '';

if (\$providedToken !== \$expectedToken) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

\$zipFile      = __DIR__ . '/../$zipFilename';
\$extractTo    = __DIR__ . '/../';
\$storageDir   = __DIR__ . '/../storage';
\$bootstrapDir = __DIR__ . '/../bootstrap/cache';

\$log = [];

register_shutdown_function(function() use (\$zipFile) {
    @unlink(\$zipFile);
    @unlink(__FILE__);
});

if (!file_exists(\$zipFile)) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => "Archive '\$zipFile' not found."]);
    exit;
}

\$log[] = "Initializing remote deployment...";

\$zip = new ZipArchive;
if (\$zip->open(\$zipFile) === TRUE) {
    \$zip->extractTo(\$extractTo);
    \$zip->close();
    \$log[] = "Extraction successful.";

    // Fix permissions
    shell_exec("chmod -R 775 " . escapeshellarg(\$storageDir)   . " 2>&1");
    shell_exec("chmod -R 775 " . escapeshellarg(\$bootstrapDir) . " 2>&1");
    \$log[] = "Permissions set on storage/ and bootstrap/cache/.";

    // Purge stale bootstrap cache
    \$cacheFiles = ['config.php', 'events.php', 'packages.php', 'routes.php', 'services.php'];
    foreach (\$cacheFiles as \$cf) {
        \$cFile = \$bootstrapDir . '/' . \$cf;
        if (file_exists(\$cFile)) @unlink(\$cFile);
    }
    \$log[] = "Stale bootstrap cache purged.";

    try {
        require __DIR__ . '/../vendor/autoload.php';
        \$app = require_once __DIR__ . '/../bootstrap/app.php';
        \$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
        \$kernel->bootstrap();
        
        \$kernel->call('down');

        \$commands = $cmdsExport;
        foreach (\$commands as \$cmd) {
            \$input = new \Symfony\Component\Console\Input\StringInput(\$cmd);
            \$output = new \Symfony\Component\Console\Output\BufferedOutput();
            \$kernel->handle(\$input, \$output);
            \$log[] = "Ran: \$cmd\\n" . trim(\$output->fetch());
        }

        \$kernel->call('up');
        \$log[] = "Application is back online.";

    } catch (\Exception \$e) {
        \$log[] = "Artisan error: " . \$e->getMessage();
        // Failsafe to bring app up if down failed partway
        if (isset(\$kernel)) {
            try { \$kernel->call('up'); } catch (\Exception \$ex) {}
        }
    }
    
    echo json_encode(['status' => 'success', 'logs' => \$log]);
} else {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => "Failed to open zip archive."]);
}
PHP;
    }

    private function connect(): bool
    {
        $this->log("Connecting to FTP: " . $this->config['ftp_host'], '34');
        
        $useFtps = $this->config['ftp_ssl'] ?? true;
        if ($useFtps && function_exists('ftp_ssl_connect')) {
            $this->ftpConn = @ftp_ssl_connect($this->config['ftp_host'], $this->config['ftp_port']);
            if ($this->ftpConn) {
                $this->log("Using secure FTPS connection.", '32');
            }
        }
        
        if (!$this->ftpConn) {
            if ($useFtps) $this->log("FTPS not available or failed, falling back to standard FTP...", '33');
            $this->ftpConn = @ftp_connect($this->config['ftp_host'], $this->config['ftp_port']);
        }

        if (!$this->ftpConn || !@ftp_login($this->ftpConn, $this->config['ftp_user'], $this->config['ftp_pass'])) {
            $this->log("ERROR: FTP Connection failed.", '31');
            return false;
        }
        ftp_pasv($this->ftpConn, true);
        return true;
    }

    private function uploadHelper(string $remotePath, string $content): bool
    {
        $dir = dirname($remotePath);
        if ($dir && $dir !== '.') {
            @ftp_mkdir($this->ftpConn, $dir);
        }

        $temp = tmpfile();
        fwrite($temp, $content);
        fseek($temp, 0);
        return ftp_fput($this->ftpConn, $remotePath, $temp, FTP_BINARY);
    }

    private function sendRequest(string $url, string $token): array
    {
        $url .= (parse_url($url, PHP_URL_QUERY) ? '&' : '?') . 'token=' . urlencode($token);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Accept: application/json"
        ]);
        
        $res      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'code' => $httpCode,
            'body' => $res !== false ? $res : curl_error($ch)
        ];
    }

    private function log(string $message, string $color = '37'): void
    {
        echo "\033[" . $color . "m[" . date('H:i:s') . "] $message\033[0m\n";
    }

    private function close(): void
    {
        if ($this->ftpConn) {
            @ftp_close($this->ftpConn);
            $this->ftpConn = null;
        }
    }

    public function getPostExtractionCommands(): array { return $this->postExtractionCommands; }
    public function execute(array $commands, bool $dryRun = false): bool { return $this->runCommands($commands, $dryRun); }
}