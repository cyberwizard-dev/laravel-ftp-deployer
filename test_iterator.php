<?php
require 'src/RemoteExecutor.php';
class TestExecutor extends \Cyberwizard\LaravelFtpDeployer\RemoteExecutor {
    public function __construct() {
        $this->exclusions = [
            'directories' => ['.git', 'node_modules', 'vendor', 'storage/logs'],
            'files' => ['.env']
        ];
    }
    public function test() {
        $count = 0;
        $vendorCount = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(getcwd(), RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $filePath = $file->getRealPath();
            $relativePath = str_replace(getcwd() . DIRECTORY_SEPARATOR, '', $filePath);
            $unixPath = str_replace('\\', '/', $relativePath);

            // test with isFirstTime = true
            $excluded = false;
            foreach ($this->exclusions['directories'] as $dir) {
                $normalizedDir = trim($dir, '/');
                if (true && $normalizedDir === 'vendor') {
                    continue; // Skip vendor exclusion
                }
                if (str_starts_with($unixPath, $normalizedDir . '/') || $unixPath === $normalizedDir) {
                    $excluded = true; break;
                }
            }
            if (!$excluded) {
                foreach ($this->exclusions['files'] as $f) {
                    if (basename($unixPath) === $f || $unixPath === $f) { $excluded = true; break; }
                }
            }
            
            if (!$excluded) {
                $count++;
                if (str_starts_with($unixPath, 'vendor/')) $vendorCount++;
            }
        }
        echo "Total added: $count\n";
        echo "Vendor files added: $vendorCount\n";
    }
}
$t = new TestExecutor();
$t->test();
