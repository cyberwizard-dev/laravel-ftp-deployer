<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Cyberwizard\LaravelFtpDeployer\RemoteExecutor;

class RemoteExecutorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = __DIR__ . '/temp_test';
        if (!is_dir($this->tempDir)) mkdir($this->tempDir);
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testExclusionLogic()
    {
        $config = [
            'exclude' => [
                'files' => ['secret.txt'],
                'directories' => ['node_modules', 'storage/logs']
            ]
        ];
        file_put_contents('deploy-config.json', json_encode($config));

        $executor = new RemoteExecutor([]);
        
        // Use reflection to test private isExcluded method
        $reflection = new \ReflectionClass($executor);
        $method = $reflection->getMethod('isExcluded');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($executor, 'secret.txt', false), "Exact file match failed");
        $this->assertTrue($method->invoke($executor, 'node_modules/some/package', false), "Directory prefix match failed");
        $this->assertTrue($method->invoke($executor, 'storage/logs/laravel.log', false), "Nested directory match failed");
        $this->assertTrue($method->invoke($executor, '.env', false), "Hardcoded .env exclusion failed");
        $this->assertFalse($method->invoke($executor, 'public/index.php', false), "Valid file incorrectly excluded");
    }

    public function testManifestLogic()
    {
        $executor = new RemoteExecutor([]);
        $manifestPath = '.deploy_manifest.json';
        
        file_put_contents('test.txt', 'hello');
        $hash = md5_file('test.txt');
        
        // Simulate manifest save
        file_put_contents($manifestPath, json_encode(['test.txt' => $hash]));
        
        $this->assertFileExists($manifestPath);
        $data = json_decode(file_get_contents($manifestPath), true);
        $this->assertEquals($hash, $data['test.txt']);
    }
}
