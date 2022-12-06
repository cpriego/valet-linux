<?php

namespace Valet;

use Valet\Contracts\ServiceManager;

class AppArmor
{
    public $sm;
    public $cli;
    public $files;
    public $usrSbinDnsmasq;
    public $phpFpm;
    public $usrSbinAvahi;

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
        $this->usrSbinDnsmasq = '/etc/apparmor.d/local/usr.sbin.dnsmasq';
        $this->phpFpm = '/etc/apparmor.d/local/php-fpm';
        $this->usrSbinAvahi = '/etc/apparmor.d/local/usr.sbin.avahi-daemon';
    }

    /**
     * Install the configuration files for AppArmor.
     *
     * @return void
     */
    public function install()
    {
        if (!$this->check()) {
            return;
        }
        $this->files->ensureDirExists('/etc/apparmor.d/local/');
        $this->stop();
        $this->installConfiguration();
        $this->start();
    }

    /**
     * Install the AppArmor configuration file.
     *
     * @return void
     */
    public function installConfiguration()
    {
        $usrSbinDnsmasq = $this->usrSbinDnsmasq;
        $this->files->backup($usrSbinDnsmasq);
        $contents = $this->files->get(__DIR__ . '/../stubs/apparmor/usr.sbin.dnsmasq');
        $this->files->put($usrSbinDnsmasq, $contents);

        $phpFpm = $this->phpFpm;
        $this->files->backup($phpFpm);
        $contents = $this->files->get(__DIR__ . '/../stubs/apparmor/php-fpm');
        $this->files->put($phpFpm, $contents);

        $usrSbinAvahi = $this->usrSbinAvahi;
        $this->files->backup($usrSbinAvahi);
        $contents = $this->files->get(__DIR__ . '/../stubs/apparmor/usr.sbin.avahi-daemon');
        $this->files->put($usrSbinAvahi, $contents);
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
     * check if the AppArmor module is loaded
     *
     * @return bool
     */
    public function check()
    {
        return (bool) strpos($this->cli->run("aa-status"), 'is loaded');
    }

    /**
     * Prepare AppArmor for uninstallation.
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->files->exists($this->usrSbinDnsmasq)) {
            $this->files->restore($this->usrSbinDnsmasq);
        }
        if ($this->files->exists($this->phpFpm)) {
            $this->files->restore($this->phpFpm);
        }
        if ($this->files->exists($this->usrSbinAvahi)) {
            $this->files->restore($this->usrSbinAvahi);
        }
        if ($this->check()) {
            $this->stop();
        }
    }
}
