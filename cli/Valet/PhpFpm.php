<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\PackageManagers\Homebrew;
use Valet\Contracts\ServiceManager;
use Valet\PackageManagers\Dnf;

class PhpFpm
{
    public $pm;
    public $sm;
    public $cli;
    public $files;
    public $site;
    public $nginx;
    public $version;

    /**
     * Create a new PHP FPM class instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine $cli
     * @param Filesystem $files
     * @param Site $site
     * @param Nginx $nginx
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files, Site $site, Nginx $nginx)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
        $this->site = $site;
        $this->nginx = $nginx;
    }

    /**
     * Install and configure PHP FPM.
     *
     * @return void
     */
    public function install()
    {
        if (!$this->pm->installed("php{$this->getPhpVersion()}-fpm")) {
            $this->pm->ensureInstalled("php{$this->getPhpVersion()}-fpm");
            $this->sm->enable($this->fpmServiceName());
        }
        output('<info>PHP Logs');
        $this->files->ensureDirExists('/var/log', user());

        output('<info>Installing php config');
        $this->installConfiguration();

        output('<info>Restarting php-fpm');
        $this->restart();

        $this->symlinkPrimaryValetSock();

    }

    /**
     * Symlink (Capistrano-style) a given Valet.sock file to be the primary valet.sock.
     *
     * @param string $phpVersion
     * @return void
     */
    public function symlinkPrimaryValetSock($phpVersion = null)
    {
        if (!$phpVersion) {
            $phpVersion = $this->getPhpVersion();
        }

        $this->files->symlinkAsUser(VALET_HOME_PATH . '/' . $this->fpmSockName($phpVersion), VALET_HOME_PATH . '/valet.sock');
    }

    /**
     * If passed php7.4, or php74, 7.4, or 74 formats, normalize to php@7.4 format.
     */
    public static function normalizePhpVersion($version)
    {
        return substr(preg_replace('/(?:php@?)?([0-9+])(?:.)?([0-9+])/i', '$1.$2', (string)$version), 0, 3);
    }


    /**
     * Validate the requested version to be sure we can support it.
     *
     * @param string $version
     * @return string
     */
    public function validateRequestedVersion($version): string
    {
        if (is_null($version)) {
            throw new DomainException("Please specify a PHP version (try something like 'php8.1')");
        }

        $version = $this->normalizePhpVersion($version);

        if (!$this->pm->installed("php{$version}-fpm")) {
            $this->pm->ensureInstalled("php{$version}-fpm");
            $this->sm->enable($this->fpmServiceName());
        }

        return $version;
    }


    /**
     * Uninstall PHP FPM valet config.
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->files->exists('/etc/systemd/system/php-fpm.service.d/valet.conf')) {
            unlink('/etc/systemd/system/php-fpm.service.d/valet.conf');
        }

        if ($this->files->exists($this->fpmConfigPath() . '/valet.conf')) {
            $this->files->unlink($this->fpmConfigPath() . '/valet.conf');
            $this->stop();
        }
    }

    /**
     * Stop only the running php services.
     */
    public function stopRunning()
    {
        $this->sm->stop(
            $this->sm->getAllRunningServices()
                ->filter(function ($service) {
                    return substr($service, 0, 3) === 'php';
                })
                ->all()
        );
    }


    /**
     * Isolate a given directory to use a specific version of PHP.
     *
     * @param string $directory
     * @param string $version
     * @return void
     */
    public function isolateDirectory($directory, $version)
    {
        $site = $this->site->getSiteUrl($directory);

        $version = $this->validateRequestedVersion($version);

        $this->pm->ensureInstalled("php" . $version . "-fpm");

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"
        $this->createConfigurationFiles($version);

        $this->site->isolate($site, $version);

        $this->stopIfUnused($oldCustomPhpVersion);
        $this->restart($version);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using %s.', $site, $version));
    }

    /**
     * Remove PHP version isolation for a given directory.
     *
     * @param string $directory
     * @return void
     */
    public function unIsolateDirectory($directory)
    {
        $site = $this->site->getSiteUrl($directory);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"

        $this->site->removeIsolation($site);
        $this->stopIfUnused($oldCustomPhpVersion);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using the default PHP version.', $site));
    }

    /**
     * List all directories with PHP isolation configured.
     *
     * @return \Illuminate\Support\Collection
     */
    public function isolatedDirectories()
    {
        return $this->nginx->configuredSites()->filter(function ($item) {
            return strpos($this->files->get(VALET_HOME_PATH . '/Nginx/' . $item), ISOLATED_PHP_VERSION) !== false;
        })->map(function ($item) {
            return ['url' => $item, 'version' => $this->normalizePhpVersion($this->site->customPhpVersion($item))];
        });
    }

    /**
     * Change the php-fpm version.
     *
     * @param string|float|int $version
     *
     * @return void
     */
    public function changeVersion($version = null)
    {
        $oldVersion = $this->getPhpVersion();
        $exception = null;

        $this->stop();
        info('Disabling php' . $oldVersion . '-fpm...');
        $this->sm->disable($this->fpmServiceName());

        if (!isset($version) || strtolower($version) === 'default') {
            $this->version = $this->getVersion(true);
        } else {
            $this->version = $version;
        }
        output("<info>Changing php version from $oldVersion to {$this->getPhpVersion()}...</info> ");

        try {
            $this->install();
        } catch (DomainException $e) {
            $this->version = $oldVersion;
            $exception = $e;
        }

        if ($this->sm->disabled($this->fpmServiceName())) {
            info('Enabling php' . $this->getPhpVersion() . '-fpm...');
            $this->sm->enable($this->fpmServiceName());
        }

        if ($this->getPhpVersion() !== $this->getVersion(true)) {
            $this->files->putAsUser(VALET_HOME_PATH . '/use_php_version', $this->getPhpVersion());
        } else {
            $this->files->unlink(VALET_HOME_PATH . '/use_php_version');
        }

        if ($exception) {
            info('Changing version failed');
            throw $exception;
        }

        $this->updateCliVersion();
    }

    /**
     * Update the PHP CLI version.
     *
     * @return void
     */
    protected function updateCliVersion()
    {
        $path = $this->cli->run("which php{$this->getPhpVersion()}");

        $this->cli->run("update-alternatives --set php $path");
    }

    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     *
     * @param string|null $phpVersion
     * @return void
     */
    public function stopIfUnused($phpVersion = null)
    {
        if (!$phpVersion) {
            return;
        }

        $phpVersion = $this->normalizePhpVersion($phpVersion);

        if (!in_array($phpVersion, $this->utilizedPhpVersions())) {

            $this->sm->stop($this->fpmServiceName($phpVersion));
        }
    }

    /**
     * Update the PHP FPM configuration to use the current user.
     *
     * @return void
     */
    public function installConfiguration($phpVersion = null)
    {
        $contents = $this->files->get(__DIR__ . '/../stubs/fpm.conf');
        if (!$phpVersion) {
            $phpVersion = $this->getPhpVersion();
        }

        $this->files->putAsUser(
            $this->fpmConfigPath() . '/valet.conf',
            str_array_replace([
                'VALET_USER' => user(),
                'VALET_GROUP' => group(),
                'VALET_FPM_SOCK' => VALET_HOME_PATH . "/".  $this->fpmSockName($phpVersion),
            ], $contents)
        );

        if (($this->sm) instanceof \Valet\ServiceManagers\Systemd) {
            $this->systemdDropInOverride();
        }
    }

    /**
     * Install Drop-In systemd override for php-fpm service
     *
     * @return void
     */
    public function systemdDropInOverride()
    {
        $this->files->ensureDirExists('/etc/systemd/system/php-fpm.service.d');
        $this->files->putAsUser(
            '/etc/systemd/system/php-fpm.service.d/valet.conf',
            $this->files->get(__DIR__ . '/../stubs/php-fpm.service.d/valet.conf')
        );
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    public function restart($version = null)
    {
        if (!$version) {
            $version = $this->getPhpVersion();
        }

        $serviceName = "php{$version}-fpm";

        if ($this->pm instanceof Homebrew) {
            return resolve(\Valet\ServiceManagers\Homebrew::class)->restart("php{$this->getPhpVersion()}");
        }
        $this->sm->restart($serviceName);
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop($this->fpmServiceName());
    }

    /**
     * PHP-FPM service status.
     *
     * @return void
     */
    public function status()
    {
        $this->sm->printStatus($this->fpmServiceName());
    }

    /**
     * Get installed PHP version.
     *
     * @param string $real force getting version from /usr/bin/php.
     *
     * @return string
     */
    public function getVersion($real = false)
    {
        if (!$real && $this->files->exists(VALET_HOME_PATH . '/use_php_version')) {
            $version = $this->files->get(VALET_HOME_PATH . '/use_php_version');
        } else {
            $version = explode('php', basename($this->files->readLink('/usr/bin/php')))[1];
        }

        return $version;
    }
    
    /**
     * Get the possible PHP FPM service names.
     *
     * @return array
     */
    public function getFpmServiceNames()
    {
        return [
            "php-fpm",
            "php-fpm{$this->version}",
            "php{$this->version}-fpm",
        ];
    }

    /**
     * Determine php service name
     *
     * @return string
     */
    public function fpmServiceName()
    {
        $services = array_map(function ($serviceName) {
            return [
                'name' => $serviceName,
                'status' => $this->sm->status($serviceName),
            ];
        }, $this->getFpmServiceNames());
        $services = array_filter($services, function ($service) {
            return false === strpos($service['status'], 'not-found') && false === strpos($service['status'], 'not be found');
        });
        $service = reset($services);
        if (is_array($service) && ! empty($service['name'])) {
            return $service['name'];
        }

        return new DomainException('Unable to determine PHP service name.');
    }
    
    /**
     * Get FPM sock file name for a given PHP version.
     *
     * @param string|null $phpVersion
     * @return string
     */
    public static function fpmSockName($phpVersion = null)
    {

        $versionInteger = preg_replace('~[^\d]~', '', $phpVersion);

        return "valet{$versionInteger}.sock";
    }

    /**
     * Create (or re-create) the PHP FPM configuration files.
     *
     * Writes FPM config file, pointing to the correct .sock file, and log and ini files.
     *
     * @param string $phpVersion
     * @return void
     */
    public function createConfigurationFiles($phpVersion)
    {
        info("Updating PHP configuration for {$phpVersion}...");

        $fpmConfigFile = $this->fpmConfigPath($phpVersion) . '/valet.conf';

        $this->files->ensureDirExists(dirname($fpmConfigFile), user());

        // rename (to disable) old FPM Pool configuration, regardless of whether it's a default config or one customized by an older Valet version
        $oldFile = dirname($fpmConfigFile) . '/www.conf';
        if (file_exists($oldFile)) {
            rename($oldFile, $oldFile . '-backup');
        }

        // Create FPM Config File from stub
        $contents = str_replace(
            ['VALET_USER', 'VALET_HOME_PATH', 'valet.sock'],
            [user(), VALET_HOME_PATH, self::fpmSockName($phpVersion)],
            $this->files->get(__DIR__ . '/../stubs/etc-phpfpm-valet.conf')
        );
        $this->files->put($fpmConfigFile, $contents);

        // Create other config files from stubs
        $destDir = dirname(dirname($fpmConfigFile)) . '/conf.d';
        $this->files->ensureDirExists($destDir, user());

        $this->files->putAsUser(
            $destDir . '/php-memory-limits.ini',
            $this->files->get(__DIR__ . '/../stubs/php-memory-limits.ini')
        );

        $contents = str_replace(
            ['VALET_USER', 'VALET_HOME_PATH'],
            [user(), VALET_HOME_PATH],
            $this->files->get(__DIR__ . '/../stubs/etc-phpfpm-error_log.ini')
        );
        $this->files->putAsUser($destDir . '/error_log.ini', $contents);

        // Create log directory and file
        $this->files->ensureDirExists(VALET_HOME_PATH . '/Log', user());
        $this->files->touch(VALET_HOME_PATH . '/Log/php-fpm.log', user());
    }

    /**
     * Get a list including the global PHP version and all PHP versions currently serving "isolated sites" (sites with
     * custom Nginx configs pointing them to a specific PHP version).
     *
     * @return array
     */
    public function utilizedPhpVersions()
    {
        $fpmSockFiles = $this->pm->supportedPhpVersions()->map(function ($version) {
            return self::fpmSockName($this->normalizePhpVersion($version));
        })->unique();

        return $this->nginx->configuredSites()->map(function ($file) use ($fpmSockFiles) {
            $content = $this->files->get(VALET_HOME_PATH . '/Nginx/' . $file);

            // Get the normalized PHP version for this config file, if it's defined
            foreach ($fpmSockFiles as $sock) {
                if (strpos($content, $sock) !== false) {
                    // Extract the PHP version number from a custom .sock path and normalize it to, e.g., "php@7.4"
                    return $this->normalizePhpVersion(str_replace(['valet', '.sock'], '', $sock));
                }
            }
        })->filter()->unique()->values()->toArray();
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    public function fpmConfigPath($phpVersion = null)
    {
        $phpVersion = $phpVersion ?: $this->getPhpVersion();

        $path = collect([
            '/etc/php/' . $phpVersion . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $phpVersion . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $phpVersion . '/php-fpm.d', // Manjaro
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
            '/etc/php7/fpm/php-fpm.d', // openSUSE PHP7
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
        ])->first(function ($path) {
            return is_dir($path);
        }, function () {
            throw new DomainException('Unable to determine PHP-FPM configuration folder.');
        });

        return $path;
    }

    /**
     * This method check package manager to skip the version for some distros,
     * and return the version or null depend on the distro.
     *
     * @return string|null
     */
    public function getPhpVersion(): string|null
    {
        if ($this->pm instanceof Dnf) {
            return null;
        }

        if ($this->pm instanceof Pacman) {
            return null;
        }

        if (!$this->version) {
            $this->version = $this->normalizePhpVersion(PHP_VERSION);
        }

        return $this->version;
    }
}
