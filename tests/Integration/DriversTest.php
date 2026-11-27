<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../cli/drivers/require.php';

class DriversTest extends TestCase
{
    private $site;

    protected function setUp(): void
    {
        $this->site = __DIR__ . '/output/driver-site';
        exec('rm -rf ' . escapeshellarg($this->site));
        mkdir($this->site, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(__DIR__ . '/output'));
        mkdir(__DIR__ . '/output');
        touch(__DIR__ . '/output/.gitkeep');
    }

    private function touchFile($path)
    {
        @mkdir(dirname($this->site . '/' . $path), 0777, true);
        touch($this->site . '/' . $path);
    }

    public function test_nette_driver_serves_nette_applications()
    {
        foreach (['www/index.php', 'www/.htaccess', 'config/common.neon', 'config/services.neon'] as $file) {
            $this->touchFile($file);
        }

        $this->assertInstanceOf(NetteValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));
        $this->assertSame($this->site . '/www/index.php', (new NetteValetDriver)->frontControllerPath($this->site, 'site', '/'));
    }

    public function test_radicle_driver_serves_radicle_applications()
    {
        foreach (['public/content/mu-plugins/bedrock-autoloader.php', 'public/wp-config.php', 'bedrock/application.php'] as $file) {
            $this->touchFile($file);
        }

        $this->assertInstanceOf(RadicleValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));
    }

    public function test_statamic_driver_requires_the_please_script_of_statamic_3()
    {
        $this->touchFile('public/index.php');
        $this->touchFile('artisan');

        $this->assertInstanceOf(LaravelValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));

        $this->touchFile('please');

        $this->assertInstanceOf(StatamicValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));
    }

    public function test_legacy_statamic_is_served_by_the_v2_driver()
    {
        mkdir($this->site . '/statamic');

        $this->assertInstanceOf(StatamicV2ValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));
    }

    public function test_contao_driver_supports_the_public_directory()
    {
        mkdir($this->site . '/vendor/contao', 0777, true);
        $this->touchFile('public/index.php');

        $driver = ValetDriver::assign($this->site, 'site', '/');

        $this->assertInstanceOf(ContaoValetDriver::class, $driver);
        $this->assertSame($this->site . '/public/index.php', $driver->frontControllerPath($this->site, 'site', '/'));
    }

    public function test_basic_with_public_driver_serves_sites_with_a_public_directory()
    {
        $this->touchFile('public/index.php');

        $this->assertInstanceOf(BasicWithPublicValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));
    }

    public function test_basic_driver_is_the_fallback()
    {
        $this->touchFile('index.php');

        $this->assertInstanceOf(BasicValetDriver::class, ValetDriver::assign($this->site, 'site', '/'));
    }
}
