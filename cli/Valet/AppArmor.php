<?php

namespace Valet;

use Valet\Contracts\ServiceManager;

class AppArmor
{
    public $sm;
    public $cli;
    public $files;
    public $usr_sbin_dnsmasq;

    /**
     * Create a new Nginx instance.
     *
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @return void
     */
    public function __construct(ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->sm = $sm;
        $this->files = $files;
        $this->usr_sbin_dnsmasq = '/etc/apparmor.d/local/usr.sbin.dnsmasq';
    }

    /**
     * Install the configuration files for AppArmor.
     *
     * @return void
     */
    public function install()
    {
        if ((strpos($this->cli->run("aa-status"), 'is loaded') == 0)) {
            $this->files->ensureDirExists('/etc/apparmor.d/local/');
            $this->stop();
            $this->installConfiguration();
            $this->start();
        }
    }

    /**
     * Install the AppArmor configuration file.
     *
     * @return void
     */
    public function installConfiguration()
    {
        $usr_sbin_dnsmasq = $this->usr_sbin_dnsmasq;
        $this->files->backup($usr_sbin_dnsmasq);
        $contents = $this->files->get(__DIR__ . '/../stubs/usr.sbin.dnsmasq');
        $this->files->put($usr_sbin_dnsmasq, $contents);
    }

    /**
     * start the AppArmor service.
     *
     * @return void
     */
    public function start()
    {
        $this->sm->start('apparmor');
    }

    /**
     * Stop the AppArmor service.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop('apparmor');
    }

    /**
     * AppArmor service status.
     *
     * @return void
     */
    public function status()
    {
        $this->sm->printStatus('apparmor');
    }

    /**
     * Prepare AppArmor for uninstallation.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->stop();
        $this->files->restore($this->usr_sbin_dnsmasq);
    }
}
