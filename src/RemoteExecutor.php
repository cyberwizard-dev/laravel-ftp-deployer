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
            'app_url' => rtrim($config['app_url'] ?? '', '/'),
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
                    'db-migrate' => ['migrate --force']
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
                $this->exclusions['files'] = $data['exclude']['files'] ?? $this->exclusions['files'];
                $this->exclusions['directories'] = $data['exclude']['directories'] ?? $this->exclusions['directories'];
            }
            
            // Support both keys for backward compatibility
            $this->postExtractionCommands = $data['post_extraction_commands'] ?? ($data['remote_commands'] ?? $this->postExtractionCommands);
            $this->customCommands = $data['custom_commands'] ?? $this->customCommands;
        }
    }

    public function getCustomCommand(string $name): ?array
    {
        return $this->customCommands[$name] ?? null;
    }

    public function runCommands(array $commands): bool
    {
        if (!$this->connect()) return false;

        $helperName = 'artisan_run_' . bin2hex(random_bytes(4)) . '.php';
        $remoteHelper = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . 'public/' . $helperName;

        $this->log("Uploading temporary command runner...", '34');
        $helperCode = $this->generateCommandRunnerCode($commands);
        
        if (!$this->uploadHelper($remoteHelper, $helperCode)) {
            $this->close();
            return false;
        }

        $this->log("Triggering commands via HTTP...", '34');
        $triggerUrl = $this->config['app_url'] . "/" . $helperName;
        $response = $this->sendRequest($triggerUrl);

        if ($response === false) {
            $this->log("ERROR: HTTP request failed.", '31');
        } else {
            $this->log("Remote Output:\n$response", '36');
        }

        $this->log("Cleaning up remote helper...", '34');
        @ftp_delete($this->ftpConn, $remoteHelper);
        $this->close();

        return $response !== false;
    }

    public function deploy(bool $isFirstTime = false): bool
    {
        $this->log($isFirstTime ? "Starting FULL deployment..." : "Starting INCREMENTAL deployment...", '34');

        $timestamp = date('Ymd_His');
        $zipFilename = "deploy_{$timestamp}.zip";
        $zipFile = getcwd() . '/' . $zipFilename;
        if (file_exists($zipFile)) unlink($zipFile);

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
            $this->log("ERROR: Could not create ZIP archive.", '31');
            return false;
        }

        $manifest = file_exists($this->manifestPath) ? json_decode(file_get_contents($this->manifestPath), true) : [];
        $newManifest = $manifest;
        $count = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(getcwd(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $filePath = $file->getRealPath();
            $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $filePath);
            $unixPath = str_replace('\\', '/', $relativePath);

            if ($this->isExcluded($unixPath, false) || basename($unixPath) === $zipFilename) continue;

            $currentHash = md5_file($filePath);
            $storedHash = $manifest[$unixPath] ?? '';

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

        $remoteZip = ($this->config['ftp_root'] ? $this->config['ftp_root'] . '/' : '') . $zipFilename;
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
        $triggerUrl = $this->config['app_url'] . "/deploy_extract.php";
        $response = $this->sendRequest($triggerUrl);

        if ($response === false) {
            $this->log("ERROR: Remote extraction failed (HTTP request failed).", '31');
        } else {
            $this->log("Remote Output:\n$response", '36');
            file_put_contents($this->manifestPath, json_encode($newManifest, JSON_PRETTY_PRINT));
            $this->log("Manifest updated locally.", '32');
        }

        $this->log("Cleaning up remote helper...", '34');
        @ftp_delete($this->ftpConn, $remoteHelper);
        $this->close();

        if (file_exists($zipFile)) unlink($zipFile);
        return $response !== false;
    }

    private function isExcluded(string $path, bool $isDir): bool
    {
        foreach ($this->exclusions['directories'] as $dir) {
            if (str_starts_with($path, $dir . '/') || $path === $dir) return true;
        }
        foreach ($this->exclusions['files'] as $file) {
            if (basename($path) === $file || $path === $file) return true;
        }
        if (str_contains($path, '.env')) return true;
        return false;
    }

    private function generateCommandRunnerCode(array $commands): string
    {
        $commandList = "";
        foreach ($commands as $cmd) {
            $escapedCmd = addslashes($cmd);
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
            $escapedCmd = addslashes($cmd);
            $commandList .= "    echo \"Running: php artisan $escapedCmd\\n\";\n";
            $commandList .= "    echo shell_exec(\"php \$artisan $escapedCmd 2>&1\");\n";
        }

        return <<<PHP
<?php
set_time_limit(0);
\$zipFile = __DIR__ . '/../$zipFilename';
\$extractTo = __DIR__ . '/../';
\$artisan = __DIR__ . '/../artisan';

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
        $temp = tmpfile();
        fwrite($temp, $content);
        fseek($temp, 0);
        return ftp_fput($this->ftpConn, $remotePath, $temp, FTP_BINARY);
    }

    private function sendRequest(string $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        $res = curl_exec($ch);
        return $res;
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
