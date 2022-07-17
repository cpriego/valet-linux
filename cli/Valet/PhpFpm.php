<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\PackageManagers\Homebrew;
use Valet\Contracts\ServiceManager;

class PhpFpm
{
    public $pm;
    public $sm;
    public $cli;
    public $files;
    public $version;

    /**
     * Create a new PHP FPM class instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
        $this->version = $this->getVersion();
    }

    /**
     * Install and configure PHP FPM.
     *
     * @return void
     */
    public function install()
    {
        if (!$this->pm->installed("php{$this->version}-fpm")) {
            $this->pm->ensureInstalled("php{$this->version}-fpm");
            $this->sm->enable($this->fpmServiceName());
        }
        output('<info>PHP Logs');
        $this->files->ensureDirExists('/var/log', user());

        output('<info>Installing php config');
        $this->installConfiguration();

        output('<info>Restarting php-fpm');
        $this->restart();
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
     * Change the php-fpm version.
     *
     * @param string|float|int $version
     *
     * @return void
     */
    public function changeVersion($version = null)
    {
        $oldVersion = $this->version;
        $exception = null;

        $this->stop();
        info('Disabling php' . $this->version . '-fpm...');
        $this->sm->disable($this->fpmServiceName());

        if (!isset($version) || strtolower($version) === 'default') {
            $this->version = $this->getVersion(true);
        } else {
            $this->version = $version;
        }

        try {
            $this->install();
        } catch (DomainException $e) {
            $this->version = $oldVersion;
            $exception = $e;
        }

        if ($this->sm->disabled($this->fpmServiceName())) {
            info('Enabling php' . $this->version . '-fpm...');
            $this->sm->enable($this->fpmServiceName());
        }

        if ($this->version !== $this->getVersion(true)) {
            $this->files->putAsUser(VALET_HOME_PATH . '/use_php_version', $this->version);
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
        $path = $this->cli->run("which php{$this->version}");

        $this->cli->run("update-alternatives --set php $path");
    }

    /**
     * Update the PHP FPM configuration to use the current user.
     *
     * @return void
     */
    public function installConfiguration()
    {
        $contents = $this->files->get(__DIR__ . '/../stubs/fpm.conf');

        $this->files->putAsUser(
            $this->fpmConfigPath() . '/valet.conf',
            str_array_replace([
                'VALET_USER' => user(),
                'VALET_GROUP' => group(),
                'VALET_HOME_PATH' => VALET_HOME_PATH,
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
    public function restart()
    {
        if($this->pm instanceof Homebrew){
            return resolve(\Valet\ServiceManagers\Homebrew::class)->restart("php{$this->version}");
        }
        $this->sm->restart($this->fpmServiceName());
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
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    public function fpmConfigPath()
    {
        return collect([
            '/etc/php/' . $this->version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $this->version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $this->version . '/php-fpm.d', // Manjaro
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
            '/etc/php7/fpm/php-fpm.d', // openSUSE PHP7
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
        ])->first(function ($path) {
            return is_dir($path);
        }, function () {
            throw new DomainException('Unable to determine PHP-FPM configuration folder.');
        });
    }
}
