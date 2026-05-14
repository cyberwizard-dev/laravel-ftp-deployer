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

            // Support both keys for backward compatibility
            $this->postExtractionCommands = $data['post_extraction_commands'] ?? ($data['remote_commands'] ?? $this->postExtractionCommands);
            $this->customCommands         = $data['custom_commands'] ?? $this->customCommands;
        }
    }

    public function getCustomCommand(string $name): ?array
    {
        return $this->customCommands[$name] ?? null;
    }

    // ─── Fix local permissions before zipping ────────────────────────────────

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

            @chmod($dir, 0775); // the root target dir itself

            $label = str_replace($cwd . '/', '', $dir);
            $this->log("  chmod 775/664 → $label", '36');
        }

        // Ensure artisan is executable locally too
        $artisan = $cwd . '/artisan';
        if (file_exists($artisan)) {
            @chmod($artisan, 0755);
            $this->log("  chmod 755    → artisan", '36');
        }
    }

    // ─── Deploy ──────────────────────────────────────────────────────────────

    public function deploy(bool $isFirstTime = false): bool
    {
        $this->log("Preparing local environment...", '34');

        if (file_exists(getcwd() . '/artisan')) {
            $this->log("Running locally: php artisan optimize:clear", '36');
            shell_exec("php artisan optimize:clear 2>&1");
        }

        // Fix local permissions BEFORE building the ZIP so the correct
        // mode bits are preserved inside the archive.
        $this->fixLocalPermissions();

        $this->log($isFirstTime ? "Starting FULL deployment..." : "Starting INCREMENTAL deployment...", '34');

        $timestamp   = date('Ymd_His');
        $zipFilename = "deploy_{$timestamp}.zip";
        $zipFile     = getcwd() . '/' . $zipFilename;
        if (file_exists($zipFile)) unlink($zipFile);

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            $this->log("ERROR: Could not create ZIP archive.", '31');
            return false;
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
                $zip->addFile($filePath, $relativePath);
                $count++;
            }
            $newManifest[$unixPath] = $currentHash;
        }

        if ($count === 0) {
            $zip->close();
            if (file_exists($zipFile)) unlink($zipFile);
            $this->log("No changes detected. Deployment skipped.", '33');
            return true;
        }

        $zip->close();
        $this->log("ZIP archive created with $count files: $zipFilename", '32');

        if (!$this->connect()) return false;

        $remoteZip    = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . $zipFilename;
        $remoteHelper = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . 'public/deploy_extract.php';

        $this->log("Uploading $zipFilename...", '34');
        if (!ftp_put($this->ftpConn, $remoteZip, $zipFile, FTP_BINARY)) {
            $this->log("ERROR: Failed to upload $zipFilename", '31');
            $this->close();
            return false;
        }

        $this->log("Uploading remote extractor...", '34');
        $helperCode = $this->generateExtractorCode($zipFilename);
        if (!$this->uploadHelper($remoteHelper, $helperCode)) {
            $this->close();
            return false;
        }

        $this->log("Triggering remote extraction via HTTP...", '34');

        $urlsToTry = [
            $this->config['app_url'] . "/deploy_extract.php",
            $this->config['app_url'] . "/public/deploy_extract.php"
        ];

        $success = false;
        foreach ($urlsToTry as $triggerUrl) {
            $this->log("Trying URL: $triggerUrl", '36');
            $response = $this->sendRequest($triggerUrl);

            if ($response['code'] === 200) {
                $this->log("Remote Output:\n" . $response['body'], '32');
                file_put_contents($this->manifestPath, json_encode($newManifest, JSON_PRETTY_PRINT));
                $this->log("Manifest updated locally.", '32');
                $success = true;
                break;
            } else {
                $this->log("Failed with HTTP " . $response['code'], '33');
            }
        }

        if (!$success) {
            $this->log("ERROR: Remote extraction failed on all attempted URLs.", '31');
        }

        $this->log("Cleaning up remote helper...", '34');
        @ftp_delete($this->ftpConn, $remoteHelper);
        $this->close();

        if (file_exists($zipFile)) unlink($zipFile);
        return $success;
    }

    // ─── Run custom artisan commands remotely ────────────────────────────────

    public function runCommands(array $commands): bool
    {
        if (!$this->connect()) return false;

        $helperName   = 'artisan_run_' . bin2hex(random_bytes(4)) . '.php';
        $remoteHelper = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . 'public/' . $helperName;

        $this->log("Uploading temporary command runner...", '34');
        $helperCode = $this->generateCommandRunnerCode($commands);

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
            $response = $this->sendRequest($triggerUrl);

            if ($response['code'] === 200) {
                $this->log("Remote Output:\n" . $response['body'], '36');
                $success = true;
                break;
            } else {
                $this->log("Failed with HTTP " . $response['code'], '33');
            }
        }

        if (!$success) {
            $this->log("ERROR: HTTP request failed on all attempted URLs.", '31');
        }

        $this->log("Cleaning up remote helper...", '34');
        @ftp_delete($this->ftpConn, $remoteHelper);
        $this->close();

        return $success;
    }

    // ─── Exclusion logic ─────────────────────────────────────────────────────

    private function isExcluded(string $path, bool $isDir, bool $isFirstTime = false): bool
    {
        // Always exclude these regardless of mode
        if (str_contains($path, '.env') || basename($path) === 'deploy.json' || basename($path) === '.deploy_manifest.json') {
            return true;
        }

        if ($isFirstTime) {
            // Full deployment: only skip truly massive dev-only folders.
            // vendor/ is intentionally allowed so the server gets all dependencies.
            if (
                str_starts_with($path, '.git/')         ||
                str_starts_with($path, 'node_modules/') ||
                str_starts_with($path, 'tests/')
            ) {
                return true;
            }
            return false;
        }

        // Incremental: apply deploy.json exclusion rules
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

    // ─── Remote script generators ────────────────────────────────────────────

    private function generateCommandRunnerCode(array $commands): string
    {
        $commandList = "";
        foreach ($commands as $cmd) {
            $escapedCmd   = addslashes($cmd);
            $commandList .= "    echo \"Running: php artisan $escapedCmd\\n\";\n";
            $commandList .= "    echo shell_exec(\"php \$artisan $escapedCmd 2>&1\");\n";
        }

        return <<<PHP
<?php
set_time_limit(0);
\$artisan = __DIR__ . '/../artisan';
echo "Executing remote artisan commands...\\n";
if (file_exists(\$artisan)) {
$commandList
} else {
    die("Artisan not found.");
}
PHP;
    }

    private function generateExtractorCode(string $zipFilename): string
    {
        $commandList = "";
        foreach ($this->postExtractionCommands as $cmd) {
            $escapedCmd   = addslashes($cmd);
            $commandList .= "    echo \"Running: php artisan $escapedCmd\\n\";\n";
            $commandList .= "    echo shell_exec(\"php \$artisan $escapedCmd 2>&1\");\n";
        }

        return <<<PHP
<?php
set_time_limit(0);
\$zipFile      = __DIR__ . '/../$zipFilename';
\$extractTo    = __DIR__ . '/../';
\$artisan      = __DIR__ . '/../artisan';
\$storageDir   = __DIR__ . '/../storage';
\$bootstrapDir = __DIR__ . '/../bootstrap/cache';

if (!file_exists(\$zipFile)) die("Archive '$zipFilename' not found.");

echo "Initializing remote deployment...\\n";

if (file_exists(\$artisan)) {
    echo "Putting application in maintenance mode...\\n";
    shell_exec("php \$artisan down 2>&1");
}

\$zip = new ZipArchive;
if (\$zip->open(\$zipFile) === TRUE) {
    \$zip->extractTo(\$extractTo);
    \$zip->close();
    unlink(\$zipFile);
    echo "Extraction successful.\\n";

    // ── Fix permissions after extraction ──────────────────────────────────
    echo "Setting permissions on storage/ and bootstrap/cache/...\\n";
    shell_exec("chmod -R 775 " . escapeshellarg(\$storageDir)   . " 2>&1");
    shell_exec("chmod -R 775 " . escapeshellarg(\$bootstrapDir) . " 2>&1");

    if (file_exists(\$artisan)) {
        shell_exec("chmod 755 " . escapeshellarg(\$artisan) . " 2>&1");
        echo "chmod 755 applied to artisan.\\n";
    }

    echo "Permissions set.\\n";

    // ── Purge stale bootstrap cache to prevent Artisan crashes ────────────
    echo "Purging stale bootstrap cache...\\n";
    \$cacheFiles = ['config.php', 'events.php', 'packages.php', 'routes.php', 'services.php'];
    foreach (\$cacheFiles as \$cf) {
        \$cFile = \$bootstrapDir . '/' . \$cf;
        if (file_exists(\$cFile)) @unlink(\$cFile);
    }

    // ── Post-extraction artisan commands ──────────────────────────────────
    if (file_exists(\$artisan)) {
        echo "Running post-extraction tasks...\\n";
$commandList
        echo "Application is back online.\\n";
    }
} else {
    if (file_exists(\$artisan)) shell_exec("php \$artisan up 2>&1");
    http_response_code(500);
    die("Failed to open zip archive.");
}
PHP;
    }

    // ─── FTP helpers ─────────────────────────────────────────────────────────

    private function connect(): bool
    {
        $this->log("Connecting to FTP: " . $this->config['ftp_host'], '34');
        $this->ftpConn = ftp_connect($this->config['ftp_host'], $this->config['ftp_port']);
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

    private function sendRequest(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
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
        if ($this->ftpConn) ftp_close($this->ftpConn);
    }

    public function getPostExtractionCommands(): array { return $this->postExtractionCommands; }
    public function execute(array $commands): bool { return $this->runCommands($commands); }
}