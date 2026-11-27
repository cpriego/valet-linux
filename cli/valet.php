#!/usr/bin/env php
<?php

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../../../autoload.php';
}

use Illuminate\Container\Container;
use Silly\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Create the application.
 */
Container::setInstance(new Container());

$version = 'v3.0.0';

$app = new Application('Valet', $version);

$shareTools = [
    'ngrok',
    'cloudflared',
    'expose',
];

/**
 * Detect environment
 */
Valet::environmentSetup();

/**
 * Allow Valet to be run more conveniently by allowing the Node proxy to run password-less sudo.
 */
$app->command('install [--ignore-selinux]', function ($ignoreSELinux) {
    passthru(dirname(__FILE__) . '/scripts/update.sh'); // Clean up cruft

    Requirements::setIgnoreSELinux($ignoreSELinux)->check();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::read()['domain']);
    Configuration::install();
    Nginx::restart();
    Valet::symlinkToUsersBin();

    output(PHP_EOL . '<info>Valet installed successfully!</info>');

    return 0;
})->descriptions('Install the Valet services', [
    '--ignore-selinux' => 'Skip SELinux checks',
]);

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Prune missing directories and symbolic links on every command.
     */
    Configuration::prune();
    Site::pruneLinks();

    /**
     * Get or set the domain currently being used by Valet.
     */
    $app->command('domain [domain]', function ($domain = null) {
        if ($domain === null) {
            info(Configuration::read()['domain']);

            return 0;
        }

        DnsMasq::updateDomain(
            $oldDomain = Configuration::read()['domain'],
            $domain = trim($domain, '.')
        );

        Configuration::updateKey('domain', $domain);
        Site::resecureForNewDomain($oldDomain, $domain);
        PhpFpm::restart();
        Nginx::restart();

        info('Your Valet domain has been updated to [' . $domain . '].');

        return 0;
    })->descriptions('Get or set the domain used for Valet sites');

    /**
     * Get or set the port number currently being used by Valet.
     */
    $app->command('port [port] [--https]', function ($port = null, $https = null) {
        if ($port === null) {
            info('Current Nginx port (HTTP): ' . Configuration::get('port', 80));
            info('Current Nginx port (HTTPS): ' . Configuration::get('https_port', 443));

            return 0;
        }

        $port = trim($port);

        if ($https) {
            Configuration::updateKey('https_port', $port);
        } else {
            Nginx::updatePort($port);
            Configuration::updateKey('port', $port);
        }

        Site::regenerateSecuredSitesConfig();

        Nginx::restart();
        PhpFpm::restart();

        $protocol = $https ? 'HTTPS' : 'HTTP';
        info("Your Nginx {$protocol} port has been updated to [{$port}].");

        return 0;
    })->descriptions('Get or set the port number used for Valet sites');

    /**
     * Determine if the site is secured or not
     */
    $app->command('secured [site] [--expiring] [--days=] [--ca]', function ($site = null, $expiring = null, $days = 60, $ca = null) {
        if ($site !== null) {
            if (Site::secured()->contains($site)) {
                info("{$site} is secured.");

                return 1;
            }

            info("{$site} is not secured.");

            return 0;
        }

        $limit = (new DateTime)->add(new DateInterval('P' . (int) $days . 'D'));

        $sites = collect(Site::securedWithDates($ca))
            ->when($expiring, function ($collection) use ($limit) {
                return $collection->filter(function ($row) use ($limit) {
                    return $row['exp'] < $limit;
                })->sortBy('exp');
            })
            ->map(function ($row) {
                return [
                    'Site' => $row['site'],
                    'Valid Until' => $row['exp']->format('Y-m-d H:i:s T'),
                ];
            });

        table(['Site', 'Valid Until'], $sites->values()->all());

        return 0;
    })->descriptions('Determine if the site is secured or not, or list all secured sites with their expiration dates', [
        'site' => 'The site (including the domain) to check',
        '--expiring' => 'Limit the results to only sites expiring within the next 60 days.',
        '--days' => 'To be used with --expiring. Limit the results to only sites expiring within the next X days. Default is 60.',
        '--ca' => 'Include the Certificate Authority certificate in the list.',
    ]);

    /**
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been added to Valet's paths.");

        return 0;
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Get all the current sites within paths parked with 'park {path}'.
     */
    $app->command('parked', function () {
        table(['Site', 'SSL', 'URL', 'Path', 'PHP'], Site::parked()->all());

        return 0;
    })->descriptions('Display all the current sites within parked paths');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been removed from Valet's paths.");

        return 0;
    }, ['unpark'])->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Remove the current working directory to the paths configuration.
     */
    $app->command('status', function () {
        PhpFpm::status();
        Nginx::status();
        DnsMasq::status();

        return 0;
    })->descriptions('View Valet service status');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name] [--secure] [--isolate]', function ($name, $secure, $isolate) {
        $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        info('A ['.$name.'] symbolic link has been created in ['.$linkPath.'].');

        if ($secure) {
            $this->runCommand('secure '.$name);
        }

        if ($isolate) {
            if (Site::phpRcVersion($name, getcwd()) || Site::phpComposerVersion($name, getcwd())) {
                $this->runCommand('isolate --site='.$name);
            } else {
                warning('Valet could not determine which PHP version to use for this site.');
            }
        }

        return 0;
    })->descriptions('Link the current working directory to Valet', [
        '--secure' => 'Link the site with a trusted TLS certificate.',
        '--isolate' => 'Isolate the site to the PHP version specified in the current working directory\'s .valetrc, .valetphprc or composer.json file.',
    ]);

    /**
     * Display all of the registered symbolic links.
     */
    $app->command('links', function () {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path', 'PHP'], $links->all());

        return 0;
    })->descriptions('Display all of the registered Valet links');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function ($name = null) {
        $name = Site::unlink($name);

        info('The [' . $name . '] symbolic link has been removed.');

        if (Site::isSecured($name)) {
            info('Unsecuring ' . $name . '...');

            Site::unsecure(Site::domain($name));

            PhpFpm::restart();
            Nginx::restart();
        }

        return 0;
    })->descriptions('Remove the specified Valet link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain] [--expireIn=]', function ($domain = null, $expireIn = 396) {
        $url = Site::domain($domain);

        Site::secure($url, null, (int) $expireIn);
        PhpFpm::restart();
        Nginx::restart();

        info('The [' . $url . '] site has been secured with a fresh TLS certificate.');

        return 0;
    })->descriptions('Secure the given domain with a trusted TLS certificate', [
        '--expireIn' => 'The amount of days the self signed certificate is valid for. Default is 396',
    ]);

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain] [--all]', function ($domain = null, $all = null) {
        if ($all) {
            Site::unsecureAll();
            PhpFpm::restart();
            Nginx::restart();

            info('All Valet sites will now serve traffic over HTTP.');

            return 0;
        }

        $url = Site::domain($domain);

        Site::unsecure($url);
        PhpFpm::restart();
        Nginx::restart();

        info('The [' . $url . '] site will now serve traffic over HTTP.');

        return 0;
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate', [
        '--all' => 'Unsecure all secured sites.',
    ]);

    /**
     * Renew all domains with a trusted TLS certificate.
     */
    $app->command('renew [--expireIn=] [--ca]', function ($expireIn = 368, $ca = null) {
        Site::renew((int) $expireIn, $ca);
        PhpFpm::restart();
        Nginx::restart();

        return 0;
    })->descriptions('Renew all domains with a trusted TLS certificate.', [
        '--expireIn' => 'The amount of days the self signed certificate is valid for. Default is 368',
        '--ca' => 'Renew the Certificate Authority certificate before renewing the site certificates.',
    ]);

    /**
     * Create an Nginx proxy config for the specified domain.
     */
    $app->command('proxy domain host [--secure]', function ($domain, $host, $secure) {
        Site::proxyCreate($domain, $host, $secure);
        Nginx::restart();

        return 0;
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, mailhog etc.', [
        '--secure' => 'Create a proxy with a trusted TLS certificate',
    ]);

    /**
     * Delete an Nginx proxy config.
     */
    $app->command('unproxy domain', function ($domain) {
        Site::proxyDelete($domain);
        Nginx::restart();

        return 0;
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all of the sites that are proxies.
     */
    $app->command('proxies', function () {
        $proxies = Site::proxies();

        table(['Site', 'SSL', 'URL', 'Host'], $proxies->all());

        return 0;
    })->descriptions('Display all of the proxy sites');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function () {
        require __DIR__ . '/drivers/require.php';

        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by [' . get_class($driver) . '].');
        } else {
            warning('Valet could not determine which driver to use for this site.');
        }

        return 0;
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Display all of the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            info(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            warning('No paths have been registered.');
        }

        return 0;
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Open the current directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = 'http://' . ($domain ?: Site::host(getcwd())) . '.' . Configuration::read()['domain'] . '/';

        passthru('xdg-open ' . escapeshellarg($url));

        return 0;
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        warning("It looks like you are running `cli/valet.php` directly, please use the `valet` script in the project root instead.");

        return 0;
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url [domain]', function ($domain = null) use ($shareTools) {
        $tool = Configuration::read()['share-tool'] ?? 'ngrok';

        if (!in_array($tool, $shareTools)) {
            info('Please set your share tool with `valet share-tool`.');

            return 1;
        }

        $class = ucfirst($tool);

        try {
            $url = $class::currentTunnelUrl(Site::domain($domain));
        } catch (Throwable $e) {
            warning($e->getMessage());

            return 1;
        }

        if (!$url) {
            warning('No share tunnel could be found.');

            return 1;
        }

        output($url);

        return 0;
    })->descriptions('Get the URL to the current share tunnel');

    /**
     * Get or set the share tool used by the share command.
     */
    $app->command('share-tool [tool]', function (InputInterface $input, OutputInterface $output, $tool = null) use ($shareTools) {
        if ($tool === null) {
            output(Configuration::read()['share-tool'] ?? 'ngrok');

            return 0;
        }

        $tool = strtolower($tool);

        if (!in_array($tool, $shareTools)) {
            warning("[$tool] is not a valid share tool. Please use `" . implode('`, `', $shareTools) . '`.');

            return 1;
        }

        $class = ucfirst($tool);

        if (!$class::installed()) {
            $helper = $this->getHelperSet()->get('question');
            $question = new ConfirmationQuestion('Would you like to install ' . $class . ' now? [y/N] ', false);

            if ($helper->ask($input, $output, $question) === false) {
                info('Proceeding without installing ' . $class . '.');
            } else {
                try {
                    $class::ensureInstalled();
                } catch (Throwable $e) {
                    warning($e->getMessage());

                    return 1;
                }
            }
        }

        Configuration::updateKey('share-tool', $tool);
        info("Share tool set to $tool.");

        return 0;
    })->descriptions('Get or set the share tool used by Valet (ngrok, cloudflared or expose)');

    /**
     * Set the Ngrok auth token.
     */
    $app->command('set-ngrok-token [token]', function ($token = null) {
        if (!$token) {
            warning('Please provide your Ngrok auth token: valet set-ngrok-token <token>');

            return 1;
        }

        output(Ngrok::setToken($token));

        return 0;
    })->descriptions('Set the Ngrok auth token');

    /**
     * Start the daemon services.
     */
    $app->command('start [service]', function ($service = null) {
        switch ($service) {
            case null:
            case '':
                PhpFpm::restart();
                Nginx::restart();
                DnsMasq::restart();

                info('Valet services have been started.');

                return 0;
            case 'dnsmasq':
                DnsMasq::restart();

                info('dnsmasq has been started.');

                return 0;
            case 'nginx':
                Nginx::restart();

                info('Nginx has been started.');

                return 0;
            case 'php':
                PhpFpm::restart();

                info('PHP has been started.');

                return 0;
        }

        warning(sprintf('Invalid valet service name [%s]', $service));

        return 1;
    })->descriptions('Start the Valet services', [
        'service' => 'One of: dnsmasq, nginx, php. Leave empty to start all services.',
    ]);

    /**
     * Restart the daemon services.
     */
    $app->command('restart [service]', function ($service = null) {
        switch ($service) {
            case null:
            case '':
                PhpFpm::restart();
                Nginx::restart();
                DnsMasq::restart();

                info('Valet services have been restarted.');

                return 0;
            case 'dnsmasq':
                DnsMasq::restart();

                info('dnsmasq has been restarted.');

                return 0;
            case 'nginx':
                Nginx::restart();

                info('Nginx has been restarted.');

                return 0;
            case 'php':
                PhpFpm::restart();

                info('PHP has been restarted.');

                return 0;
        }

        // Handle restarting a specific PHP version (e.g. `valet restart php8.2`)
        if (preg_match('/^php@?\d/', $service)) {
            PhpFpm::restart($normalized = PhpFpm::normalizePhpVersion($service));

            info('PHP ' . $normalized . ' has been restarted.');

            return 0;
        }

        warning(sprintf('Invalid valet service name [%s]', $service));

        return 1;
    })->descriptions('Restart the Valet services', [
        'service' => 'One of: dnsmasq, nginx, php, or a PHP version (e.g. php8.2). Leave empty to restart all services.',
    ]);

    /**
     * Stop the daemon services.
     */
    $app->command('stop [service]', function ($service = null) {
        switch ($service) {
            case null:
            case '':
            case 'all':
                PhpFpm::stop();
                Nginx::stop();
                DnsMasq::stop();

                info('Valet services have been stopped.');

                return 0;
            case 'dnsmasq':
                DnsMasq::stop();

                info('dnsmasq has been stopped.');

                return 0;
            case 'nginx':
                Nginx::stop();

                info('Nginx has been stopped.');

                return 0;
            case 'php':
                PhpFpm::stop();

                info('PHP has been stopped.');

                return 0;
        }

        warning(sprintf('Invalid valet service name [%s]', $service));

        return 1;
    })->descriptions('Stop the Valet services', [
        'service' => 'One of: dnsmasq, nginx, php, all. Leave empty to stop all services.',
    ]);

    /**
     * Uninstall Valet entirely.
     */
    $app->command('uninstall', function () {
        if (is_dir(VALET_HOME_PATH . '/Certificates') && Site::secured()->count() > 0) {
            info('Removing certificates for all secured sites...');
            Site::unsecureAll();
        }

        Site::removeCa();
        Nginx::uninstall();
        PhpFpm::uninstall();
        DnsMasq::uninstall();
        Configuration::uninstall();
        Valet::uninstall();

        info('Valet has been uninstalled.');

        return 0;
    })->descriptions('Uninstall the Valet services');

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('update', function () use ($version) {
        $script = dirname(__FILE__) . '/scripts/update.sh';

        if (Valet::onLatestVersion($version)) {
            info('You have the latest version of Valet Linux');
            passthru($script);
        } else {
            warning('There is a new release of Valet Linux');
            warning('Updating now...');
            passthru($script . ' update');
        }

        return 0;
    })->descriptions('Update Valet Linux and clean up cruft');

    /**
     * Change the PHP version to the desired one.
     */
    $app->command('use [preferedversion]', function ($preferedversion = null) {
        info('Changing php-fpm version...');
        info('This does not affect php -v.');
        PhpFpm::changeVersion($preferedversion);
        info('php-fpm version successfully changed! 🎉');

        return 0;
    })->descriptions('Set the PHP-fpm version to use, enter "default" or leave empty to use version: ' . PhpFpm::getVersion(true));


    /**
     * Allow the user to change the version of PHP Valet uses to serve the current site.
     */
    $app->command('isolate [phpVersion] [--site=]', function ($phpVersion, $site = null) {
        // Only look into the current directory when the site was not given explicitly
        $cwd = $site ? null : getcwd();

        if (! $site) {
            $site = basename(getcwd());
        }

        if (is_null($phpVersion)) {
            if ($phpVersion = Site::phpRcVersion($site, $cwd)) {
                info("Found '{$site}/.valetrc' or '{$site}/.valetphprc' specifying version: {$phpVersion}");
            } elseif ($phpVersion = Site::phpComposerVersion($site, $cwd)) {
                info("Found php version in '{$site}/composer.json' specifying version: {$phpVersion}");
            } else {
                info(PHP_EOL . 'Please provide a version number. E.g.:');
                info('valet isolate php@8.2');

                return 1;
            }
        }

        PhpFpm::isolateDirectory($site, $phpVersion);

        return 0;
    })->descriptions('Change the version of PHP used by Valet to serve the current working directory', [
        'phpVersion' => 'The PHP version you want to use; e.g php@8.1',
        '--site' => 'Specify the site to isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Allow the user to un-do specifying the version of PHP Valet uses to serve the current site.
     */
    $app->command('unisolate [--site=]', function ($site = null) {
        if (! $site) {
            $site = basename(getcwd());
        }

        PhpFpm::unIsolateDirectory($site);

        return 0;
    })->descriptions('Stop customizing the version of PHP used by Valet to serve the current working directory', [
        '--site' => 'Specify the site to un-isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * List isolated sites.
     */
    $app->command('isolated', function () {
        $sites = PhpFpm::isolatedDirectories();

        table(['Path', 'PHP Version'], $sites->all());

        return 0;
    })->descriptions('List all sites using isolated versions of PHP.');

    /**
     * Get the PHP executable path for a site.
     */
    $app->command('which-php [site]', function ($site = null) {
        $siteName = $site ? Site::host($site) : basename(getcwd());
        $cwd = $site ? null : getcwd();

        $phpVersion = Site::customPhpVersion($siteName . '.' . Configuration::read()['domain']);

        if (! $phpVersion) {
            $phpVersion = Site::phpRcVersion($siteName, $cwd) ?: Site::phpComposerVersion($siteName, $cwd);
        }

        output(PhpFpm::getPhpExecutablePath($phpVersion));

        return 0;
    })->descriptions('Get the PHP executable path for a given site', [
        'site' => 'The site to get the PHP executable path for',
    ]);

    /**
     * Proxy commands through to an isolated site's version of PHP.
     */
    $app->command('php [--site=] [args]', function () {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');

        return 0;
    })->descriptions("Proxy PHP commands with isolated site's PHP executable", [
        'args' => "Command to run with isolated site's PHP executable",
        '--site' => 'Specify the site to use to get the PHP version (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Proxy commands through to an isolated site's version of Composer.
     */
    $app->command('composer [--site=] [args]', function () {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');

        return 0;
    })->descriptions("Proxy Composer commands with isolated site's PHP executable", [
        'args' => "Composer command to run with isolated site's PHP executable",
        '--site' => 'Specify the site to use to get the PHP version (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Tail log file.
     */
    $app->command('log [-f|--follow] [-l|--lines=] [key]', function ($follow, $lines, $key = null) {
        $defaultLogs = [
            'php-fpm' => VALET_HOME_PATH . '/Log/php-fpm.log',
            'nginx' => VALET_HOME_PATH . '/Log/nginx-error.log',
        ];

        $configLogs = Configuration::get('logs');
        if (! is_array($configLogs)) {
            $configLogs = [];
        }

        $logs = array_merge($defaultLogs, $configLogs);
        ksort($logs);

        if (! $key) {
            info(implode(PHP_EOL, [
                'In order to tail a log, pass the relevant log key (e.g. "nginx")',
                'along with any optional tail parameters (e.g. "-f" for follow).',
                null,
                'For example: "valet log nginx -f --lines=3"',
                null,
                'Here are the logs you might be interested in.',
                null,
            ]));

            table(
                ['Keys', 'Files'],
                collect($logs)->map(function ($file, $key) {
                    return [$key, $file];
                })->values()->all()
            );

            info(implode(PHP_EOL, [
                null,
                'Tip: Set custom logs by adding a "logs" key/file object',
                'to your "' . Configuration::path() . '" file.',
            ]));

            return 0;
        }

        if (! isset($logs[$key])) {
            warning('No logs found for [' . $key . '].');

            return 1;
        }

        $file = $logs[$key];
        if (! file_exists($file)) {
            warning('Log path [' . $file . '] does not (yet) exist.');

            return 1;
        }

        $options = [];
        if ($follow) {
            $options[] = '-f';
        }
        if ((int) $lines) {
            $options[] = '-n ' . (int) $lines;
        }

        passthru(implode(' ', array_merge(['tail'], $options, [escapeshellarg($file)])));

        return 0;
    })->descriptions('Tail log file');

    /**
     * Configure or display the directory-listing setting.
     */
    $app->command('directory-listing [status]', function ($status = null) {
        $key = 'directory-listing';

        if (in_array($status, ['on', 'off'])) {
            Configuration::updateKey($key, $status);

            output('Directory listing setting is now: ' . $status);

            return 0;
        }

        output('Directory listing is ' . Configuration::get($key, 'off'));

        return 0;
    })->descriptions('Determine directory-listing behavior. Default is off, which means a 404 will display.', [
        'status' => 'on or off. (default=off) will show a 404 page; [on] will display a listing if project folder exists but requested URI not found',
    ]);

    /**
     * Output diagnostics to aid in debugging Valet.
     */
    $app->command('diagnose [-p|--print] [--plain]', function ($print, $plain) {
        info('Running diagnostics... (this may take a while)');

        $file = Diagnose::run($print, $plain);

        if ($file) {
            info('The clipboard is not available. Diagnostics output has been saved to [' . $file . '].');
        } else {
            info('Diagnostics output has been copied to your clipboard.');
        }

        return 0;
    })->descriptions('Output diagnostics to aid in debugging Valet.', [
        '--print' => 'print diagnostics output while running',
        '--plain' => 'format clipboard output as plain text',
    ]);

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('on-latest-version', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            output('Yes');

            return 0;
        }

        output(sprintf('Your version of Valet Linux (%s) is not the latest version available.', $version));
        output('Run `valet update` to upgrade.');

        return 1;
    }, ['latest'])->descriptions('Determine if this is the latest version of Valet Linux');
}



/**
 * Load all of the Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

/**
 * Run the application.
 */
$app->run();
