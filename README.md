# Laravel FTP Deployer

A professional tool for managing Laravel applications on restricted hosting environments by executing Artisan commands and synchronizing files via an FTP-to-HTTP bridge.

## The Problem

Modern Laravel development relies heavily on the Artisan CLI for essential tasks such as database migrations, clearing caches, and managing maintenance mode. However, many shared hosting providers restrict server access to FTP/SFTP only, completely omitting SSH access.

This limitation creates a significant "deployment gap" where database syncing, maintenance control, and cache management become difficult and error-prone.

## The Solution: ZIP + Manifest Workflow

This package provides a high-performance deployment strategy:

1. **Incremental Detection**: The tool uses a local `.deploy_manifest.json` to track file content changes using MD5 hashes.
2. **Efficient Packaging**: Only new or modified files (detected by hash mismatch) are added to a timestamped `deploy_{timestamp}.zip` archive.
3. **Atomic Upload**: The single ZIP file is uploaded via FTP (much faster than thousands of small files).
4. **Remote Extraction**: A temporary PHP helper script is uploaded to extract the ZIP and run a sequence of Artisan commands (migrations, cache clearing, etc.) while the app is in maintenance mode.
5. **Auto-Cleanup**: Both the ZIP and the helper script are deleted immediately after execution.

## Installation

Install the package via Composer:

```bash
composer require cyberwizard/laravel-ftp-deployer
```

## Configuration

### Environment Variables

The tool automatically detects your credentials. It searches for a `.env.prod` file first (recommended for security), and falls back to `.env` if it's not found:

```env
FTP_HOST=ftp.example.com
FTP_USERNAME=your-ftp-user
FTP_PASSWORD=your-ftp-password
FTP_PORT=21
FTP_ROOT=/path/to/laravel/root
APP_URL=https://example.com
```
### Deployment Configuration (deploy.json)

The tool will automatically create a `deploy.json` file in your project root on its first run if it is missing. You should review this file to manage exclusions and remote Artisan commands:

```json
{
    "_comment": "...",
    "exclude": { ... },
    "post_extraction_commands": [
        "config:cache",
        "migrate --force",
        "up"
    ],
    "custom_commands": {
        "cache-clear": [
            "optimize:clear"
        ],
        "db-reset": [
            "migrate"
        ],
        "optimize": [
            "optimize:clear",
            "optimize"
        ]
    }
}
```

## Usage

### CLI Commands

**Help** (Display usage and available commands):
```bash
vendor/bin/ftp-deploy help
# or
vendor/bin/ftp-deploy --help
```

**Full Deployment** (Code Sync + Post-Extraction Commands):
```bash
vendor/bin/ftp-deploy
```

**Custom Command Set** (Executes a specific group from `custom_commands` without syncing files):
```bash
vendor/bin/ftp-deploy db-reset
```

**Full Sync** (Ignores the manifest and uploads all files):
```bash
vendor/bin/ftp-deploy --first-time
```


To perform a full deployment (ignoring the manifest):
```bash
vendor/bin/ftp-deploy --first-time
```

### Programmatic Usage

```php
use Cyberwizard\LaravelFtpDeployer\RemoteExecutor;

$config = [
    'ftp_host' => '...',
    'ftp_user' => '...',
    'ftp_pass' => '...',
    'app_url'  => '...',
];

$executor = new RemoteExecutor($config);

// Run the full deployment workflow
$executor->deploy(isFirstTime: false);
```

## Donations & Custom Projects

If you find this tool useful and would like to support its development, or if you need a custom deployment solution for your specific infrastructure, feel free to reach out:

* **Email**: [eminibest@gmail.com](mailto:eminibest@gmail.com)
* **WhatsApp**: [+2347085307378](https://wa.me/2347085307378)

## Security

* **Ephemeral Scripts**: Helper scripts use randomized filenames to prevent external discovery.
* **Instant Cleanup**: Files are deleted the moment the HTTP request completes.
* **Maintenance Mode**: The application is put into maintenance mode *before* extraction begins to ensure data integrity.

## License

The MIT License (MIT).
