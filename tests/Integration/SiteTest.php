<?php

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Filesystem;
use Valet\Site;

class SiteTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['SUDO_USER'] = user();

        Container::setInstance(new Container);
    }


    protected function tearDown(): void
    {
        exec('rm -rf ' . __DIR__ . '/output');
        mkdir(__DIR__ . '/output');
        touch(__DIR__ . '/output/.gitkeep');

        Mockery::close();
    }


    public function test_symlink_creates_symlink_to_given_path()
    {
        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('ensureDirExists')->once()->with(VALET_HOME_PATH . '/Sites', user());
        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('prependPath')->once()->with(VALET_HOME_PATH . '/Sites');
        $files->shouldReceive('symlinkAsUser')->once()->with('target', VALET_HOME_PATH . '/Sites/link');

        swap(Filesystem::class, $files);
        swap(Configuration::class, $config);

        $linkPath = resolve(Site::class)->link('target', 'link');
        $this->assertSame(VALET_HOME_PATH . '/Sites/link', $linkPath);
    }


    public function test_unlink_removes_existing_symlink()
    {
        file_put_contents(__DIR__ . '/output/file.out', 'test');
        symlink(__DIR__ . '/output/file.out', __DIR__ . '/output/link');
        $site = resolve(StubForRemovingLinks::class);
        $site->unlink('link');
        $this->assertFileDoesNotExist(__DIR__ . '/output/link');

        $site = resolve(StubForRemovingLinks::class);
        $site->unlink('link');
        $this->assertFileDoesNotExist(__DIR__ . '/output/link');
    }


    public function test_prune_links_removes_broken_symlinks_in_sites_path()
    {
        file_put_contents(__DIR__ . '/output/file.out', 'test');
        symlink(__DIR__ . '/output/file.out', __DIR__ . '/output/link');
        unlink(__DIR__ . '/output/file.out');
        $site = resolve(StubForRemovingLinks::class);
        $site->pruneLinks();
        $this->assertFileDoesNotExist(__DIR__ . '/output/link');
    }


    public function test_unlink_returns_the_name_of_the_link()
    {
        file_put_contents(__DIR__ . '/output/file.out', 'test');
        symlink(__DIR__ . '/output/file.out', __DIR__ . '/output/link');

        $this->assertSame('link', resolve(StubForRemovingLinks::class)->unlink('link'));
    }


    public function test_domain_appends_the_configured_domain_once()
    {
        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('read')->andReturn(['domain' => 'test']);
        swap(Configuration::class, $config);

        $site = resolve(Site::class);

        $this->assertSame('foo.test', $site->domain('foo'));
        $this->assertSame('foo.test', $site->domain('foo.test'));
        $this->assertSame('foo.test', $site->domain('foo/'));
    }


    public function test_secured_only_lists_certificate_files()
    {
        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('scandir')->andReturn(['.gitkeep', 'foo.test.crt', 'foo.test.key', 'foo.test.csr', 'foo.test.conf', 'bar.test.crt']);
        swap(Filesystem::class, $files);

        $this->assertSame(['foo.test', 'bar.test'], resolve(Site::class)->secured()->all());
    }


    public function test_secured_with_dates_reads_the_expiration_of_each_certificate()
    {
        $files = Mockery::mock(Filesystem::class);
        $files->shouldReceive('scandir')->andReturn(['foo.test.crt']);
        $cli = Mockery::mock(CommandLine::class);
        $cli->shouldReceive('run')->twice()->andReturn("notAfter=Jan  1 00:00:00 2030 GMT\n");
        swap(Filesystem::class, $files);
        swap(CommandLine::class, $cli);

        $sites = resolve(Site::class)->securedWithDates(true);

        $this->assertSame('Certificate Authority', $sites[0]['site']);
        $this->assertSame('foo.test', $sites[1]['site']);
        $this->assertSame('2030', $sites[1]['exp']->format('Y'));
    }


    public function test_valet_rc_and_php_rc_version_are_read_from_the_working_directory()
    {
        file_put_contents(__DIR__ . '/output/.valetrc', "FOO=bar\nPHP=php@8.1\n");

        $site = resolve(Site::class);

        $this->assertSame(['foo' => 'bar', 'php' => 'php@8.1'], $site->valetRc('app', __DIR__ . '/output'));
        $this->assertSame('8.1', $site->phpRcVersion('app', __DIR__ . '/output'));
    }


    public function test_valetphprc_takes_precedence_over_valetrc()
    {
        file_put_contents(__DIR__ . '/output/.valetrc', "PHP=8.1\n");
        file_put_contents(__DIR__ . '/output/.valetphprc', "php7.4\n");

        $this->assertSame('7.4', resolve(Site::class)->phpRcVersion('app', __DIR__ . '/output'));
    }


    public function test_php_rc_version_is_null_without_configuration()
    {
        $this->assertNull(resolve(Site::class)->phpRcVersion('app', __DIR__ . '/output'));
    }


    public function test_php_composer_version_prefers_the_current_php_version()
    {
        file_put_contents(__DIR__ . '/output/composer.json', json_encode(['require' => ['php' => '>=7.0']]));

        $this->assertSame(
            substr(PHP_VERSION, 0, 3),
            resolve(Site::class)->phpComposerVersion('app', __DIR__ . '/output')
        );
    }


    public function test_php_composer_version_picks_the_lowest_supported_version_satisfying_the_constraint()
    {
        file_put_contents(__DIR__ . '/output/composer.json', json_encode(['require' => ['php' => '^5.6']]));

        $pm = Mockery::mock(PackageManager::class);
        $pm->shouldReceive('supportedPhpVersions')->andReturn(collect(['php', 'php83', 'php56', 'php74']));
        swap(PackageManager::class, $pm);

        $this->assertSame('5.6', resolve(Site::class)->phpComposerVersion('app', __DIR__ . '/output'));
    }


    public function test_php_composer_version_is_null_when_nothing_satisfies_the_constraint()
    {
        file_put_contents(__DIR__ . '/output/composer.json', json_encode(['require' => ['php' => '^4.0']]));

        $pm = Mockery::mock(PackageManager::class);
        $pm->shouldReceive('supportedPhpVersions')->andReturn(collect(['php', 'php83']));
        swap(PackageManager::class, $pm);

        $this->assertNull(resolve(Site::class)->phpComposerVersion('app', __DIR__ . '/output'));
    }


    public function test_php_composer_version_is_null_without_a_php_requirement()
    {
        file_put_contents(__DIR__ . '/output/composer.json', json_encode(['require' => ['foo/bar' => '^1.0']]));

        $this->assertNull(resolve(Site::class)->phpComposerVersion('app', __DIR__ . '/output'));
    }
}


class StubForRemovingLinks extends Site
{
    function sitesPath()
    {
        return __DIR__ . '/output';
    }
}
