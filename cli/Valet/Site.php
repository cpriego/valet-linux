<?php

namespace Valet;

class Site
{
    public $config;
    public $cli;
    public $files;

    /**
     * Create a new Site instance.
     *
     * @param Configuration $config
     * @param CommandLine   $cli
     * @param Filesystem    $files
     */
    public function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Get the real hostname for the given path, checking links.
     *
     * @param string $path
     * @return string|null
     */
    public function host($path)
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if (realpath($this->sitesPath() . '/' . $link) === $path) {
                return $link;
            }
        }

        return basename($path);
    }

    /**
     * Link the current working directory with the given name.
     *
     * @param string $target
     * @param string $link
     * @return string
     */
    public function link($target, $link)
    {
        $this->files->ensureDirExists(
            $linkPath = $this->sitesPath(),
            user()
        );

        $this->config->prependPath($linkPath);

        $this->files->symlinkAsUser($target, $linkPath . '/' . $link);

        return $linkPath . '/' . $link;
    }

    /**
     * Pretty print out all links in Valet.
     *
     * @return \Illuminate\Support\Collection
     */
    public function links()
    {
        $certsPath = VALET_HOME_PATH . '/Certificates';

        $this->files->ensureDirExists($certsPath, user());

        $certs = $this->getCertificates($certsPath);

        return $this->getLinks(VALET_HOME_PATH . '/Sites', $certs);
    }

    /**
     * Get all certificates from config folder.
     *
     * @param string $path
     * @return \Illuminate\Support\Collection
     */
    public function getCertificates($path)
    {
        return collect($this->files->scanDir($path))->filter(function ($value, $key) {
            return ends_with($value, '.crt');
        })->map(function ($cert) {
            return substr($cert, 0, -9);
        })->flip();
    }

    /**
     * Get list of links and present them formatted.
     *
     * @param string                         $path
     * @param \Illuminate\Support\Collection $certs
     * @return \Illuminate\Support\Collection
     */
    public function getLinks($path, $certs)
    {
        $config = $this->config->read();

        $httpPort = $this->httpSuffix();
        $httpsPort = $this->httpsSuffix();

        return collect($this->files->scanDir($path))->mapWithKeys(function ($site) use ($path) {
            return [$site => $this->files->readLink($path . '/' . $site)];
        })->map(function ($path, $site) use ($certs, $config, $httpPort, $httpsPort) {
            $secured = $certs->has($site);

            $url = ($secured ? 'https' : 'http') . '://' . $site . '.' . $config['domain'] . ($secured ? $httpsPort : $httpPort);

            return [$site, $secured ? ' X' : '', $url, $path];
        });
    }

    /**
     * Return http port suffix
     *
     * @return string
     */
    public function httpSuffix()
    {
        $port = $this->config->get('port', 80);

        return ($port == 80) ? '' : ':' . $port;
    }

    /**
     * Return https port suffix
     *
     * @return string
     */
    public function httpsSuffix()
    {
        $port = $this->config->get('https_port', 443);

        return ($port == 443) ? '' : ':' . $port;
    }

    /**
     * Unlink the given symbolic link.
     *
     * @param string $name
     * @return void
     */
    public function unlink($name)
    {
        if ($this->files->exists($path = $this->sitesPath() . '/' . $name)) {
            $this->files->unlink($path);
        }
    }

    /**
     * Remove all broken symbolic links.
     *
     * @return void
     */
    public function pruneLinks()
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Resecure all currently secured sites with a fresh domain.
     *
     * @param string $oldDomain
     * @param string $domain
     * @return void
     */
    public function resecureForNewDomain($oldDomain, $domain)
    {
        if (!$this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $url) {
            $this->unsecure($url);
        }

        foreach ($secured as $url) {
            $this->secure(str_replace('.' . $oldDomain, '.' . $domain, $url));
        }
    }


    /**
     * Parse Nginx site config file contents to swap old loopback address to new.
     *
     * @param  string  $siteConf  Nginx site config content
     * @param  string  $old  Old loopback address
     * @param  string  $new  New loopback address
     * @return string
     */
    public function replaceOldLoopbackWithNew($siteConf, $old, $new)
    {
        $shouldComment = $new === VALET_LOOPBACK;

        $lookups = [];
        $lookups[] = '~#?listen .*:80; # valet loopback~';
        $lookups[] = '~#?listen .*:443 ssl http2; # valet loopback~';
        $lookups[] = '~#?listen .*:60; # valet loopback~';

        foreach ($lookups as $lookup) {
            preg_match($lookup, $siteConf, $matches);
            foreach ($matches as $match) {
                $replaced = str_replace($old, $new, $match);

                if ($shouldComment && strpos($replaced, '#') !== 0) {
                    $replaced = '#'.$replaced;
                }

                if (! $shouldComment) {
                    $replaced = ltrim($replaced, '#');
                }

                $siteConf = str_replace($match, $replaced, $siteConf);
            }
        }

        return $siteConf;
    }

    public function valetHomePath()
    {
        return VALET_HOME_PATH;
    }

    public function valetLoopback()
    {
        return $this->config->read()['loopback'];
    }

    /**
     * Get all of the URLs that are currently secured.
     *
     * @return \Illuminate\Support\Collection
     */
    public function secured()
    {
        return collect($this->files->scandir($this->certificatesPath()))
            ->map(function ($file) {
                return str_replace(['.key', '.csr', '.crt', '.conf'], '', $file);
            })->unique()->values();
    }

    /**
     * Secure the given host with TLS.
     *
     * @param string $url
     * @return void
     */
    public function secure($url)
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->caPath(), user());

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->createCa();

        $this->createCertificate($url);

        $this->createSecureNginxServer($url);
    }

    /**
     * If CA and root certificates are nonexistent, create them and trust the root cert.
     *
     * @return void
     */
    public function createCa()
    {
        $caPemPath = $this->caPath() . '/LaravelValetCASelfSigned.pem';
        $caKeyPath = $this->caPath() . '/LaravelValetCASelfSigned.key';
        if ($this->files->exists($caKeyPath) && $this->files->exists($caPemPath)) {
            return;
        }
        $oName = 'Laravel Valet CA Self Signed Organization';
        $cName = 'Laravel Valet CA Self Signed CN';
        if ($this->files->exists($caKeyPath)) {
            $this->files->unlink($caKeyPath);
        }
        if ($this->files->exists($caPemPath)) {
            $this->files->unlink($caPemPath);
        }
        $this->cli->runAsUser(sprintf(
            'openssl req -new -newkey rsa:2048 -days 730 -nodes -x509 -subj "/O=%s/commonName=%s/organizationalUnitName=Developers/emailAddress=%s/" -keyout "%s" -out "%s"',
            $oName,
            $cName,
            'rootcertificate@laravel.valet',
            $caKeyPath,
            $caPemPath
        ));
    }

    /**
     * Create and trust a certificate for the given URL.
     *
     * @param string $url
     * @return void
     */
    public function createCertificate($url)
    {
        $caPemPath = $this->caPath() . '/LaravelValetCASelfSigned.pem';
        $caKeyPath = $this->caPath() . '/LaravelValetCASelfSigned.key';
        $caSrlPath = $this->caPath() . '/LaravelValetCASelfSigned.srl';
        $keyPath = $this->certificatesPath() . '/' . $url . '.key';
        $csrPath = $this->certificatesPath() . '/' . $url . '.csr';
        $crtPath = $this->certificatesPath() . '/' . $url . '.crt';
        $confPath = $this->certificatesPath() . '/' . $url . '.conf';

        $this->buildCertificateConf($confPath, $url);
        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath, $confPath);

        $caSrlParam = ' -CAcreateserial';
        if ($this->files->exists($caSrlPath)) {
            $caSrlParam = ' -CAserial ' . $caSrlPath;
        }
        $this->cli->runAsUser(sprintf(
            'openssl x509 -req -sha256 -days 365 -CA "%s" -CAkey "%s"%s -in "%s" -out "%s" -extensions v3_req -extfile "%s"',
            $caPemPath,
            $caKeyPath,
            $caSrlParam,
            $csrPath,
            $crtPath,
            $confPath
        ));

        $this->trustCertificate($crtPath, $url);
    }

    /**
     * Create the private key for the TLS certificate.
     *
     * @param string $keyPath
     * @return void
     */
    public function createPrivateKey($keyPath)
    {
        $this->cli->runAsUser(sprintf('openssl genrsa -out %s 2048', $keyPath));
    }

    /**
     * Create the signing request for the TLS certificate.
     *
     * @param string $keyPath
     * @return void
     */
    public function createSigningRequest($url, $keyPath, $csrPath, $confPath)
    {
        $this->cli->runAsUser(sprintf(
            'openssl req -new -key %s -out %s -subj "/C=US/ST=MN/O=Valet/localityName=Valet/commonName=%s/organizationalUnitName=Valet/emailAddress=valet/" -config %s -passin pass:',
            $keyPath,
            $csrPath,
            $url,
            $confPath
        ));
    }

    /**
     * Build the SSL config for the given URL.
     *
     * @param string $url
     * @return string
     */
    public function buildCertificateConf($path, $url)
    {
        $config = str_replace('VALET_DOMAIN', $url, $this->files->get(__DIR__ . '/../stubs/openssl.conf'));
        $this->files->putAsUser($path, $config);
    }

    /**
     * Trust the given certificate file in the Mac Keychain.
     *
     * @param string $crtPath
     * @return void
     */
    public function trustCertificate($crtPath, $url)
    {
        $this->cli->run(sprintf(
            'certutil -d sql:$HOME/.pki/nssdb -A -t TC -n "%s" -i "%s"',
            $url,
            $crtPath
        ));

        $this->cli->run(sprintf(
            'certutil -d $HOME/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
            $url,
            $crtPath
        ));
    }

    /**
     * @param $url
     */
    public function createSecureNginxServer($url)
    {
        $this->files->putAsUser(
            VALET_HOME_PATH . '/Nginx/' . $url,
            $this->buildSecureNginxServer($url)
        );
    }

     /**
     * Build the Nginx proxy config for the specified domain.
     *
     * @param  string  $url  The domain name to serve
     * @param  string  $host  The URL to proxy to, eg: http://127.0.0.1:8080
     * @param  bool  $secure
     * @return string
     */
    public function proxyCreate($url, $host, $secure = false)
    {
        if (! preg_match('~^https?://.*$~', $host)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid URL', $host));
        }

        $tld = $this->config->read()['domain'];
        if (! ends_with($url, '.'.$tld)) {
            $url .= '.'.$tld;
        }

        $siteConf = $this->replaceOldLoopbackWithNew(
            $this->files->get(
                $secure ? __DIR__.'/../stubs/secure.proxy.valet.conf' : __DIR__.'/../stubs/proxy.valet.conf'
            ),
            'VALET_LOOPBACK',
            $this->valetLoopback()
        );

        $siteConf = str_replace(
            ['VALET_HOME_PATH', 'VALET_SERVER_PATH', 'VALET_STATIC_PREFIX', 'VALET_SITE', 'VALET_PROXY_HOST'],
            [$this->valetHomePath(), VALET_SERVER_PATH, VALET_STATIC_PREFIX, $url, $host],
            $siteConf
        );

        if ($secure) {
            $this->secure($url, $siteConf);
        } else {
            $this->put($url, $siteConf);
        }

        $protocol = $secure ? 'https' : 'http';

        info('Valet will now proxy ['.$protocol.'://'.$url.'] traffic to ['.$host.'].');
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param  string  $url
     * @return void
     */
    public function proxyDelete($url)
    {
        $tld = $this->config->read()['domain'];
        if (! ends_with($url, '.'.$tld)) {
            $url .= '.'.$tld;
        }

        $this->unsecure($url);
        $this->files->unlink($this->nginxPath($url));

        info('Valet will no longer proxy [https://'.$url.'].');
    }

     
    /**
     * Create the given nginx host.
     *
     * @param  string  $url
     * @param  string  $siteConf  pregenerated Nginx config file contents
     * @return void
     */
    public function put($url, $siteConf)
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->nginxPath(), user());

        $this->files->putAsUser(
            $this->nginxPath($url), $siteConf
        );
    }

    /**
     * Get all sites which are proxies (not Links, and contain proxy_pass directive).
     *
     * @return \Illuminate\Support\Collection
     */
    public function proxies()
    {
        $dir = $this->nginxPath();
        $tld = $this->config->read()['domain'];
        $links = $this->links();
        $certsPath = VALET_HOME_PATH . '/Certificates';

        $this->files->ensureDirExists($certsPath, user());

        $certs = $this->getCertificates($certsPath);

        if (! $this->files->exists($dir)) {
            return collect();
        }

        $proxies = collect($this->files->scandir($dir))
        ->filter(function ($site, $key) use ($tld) {
            // keep sites that match our TLD
            return ends_with($site, '.'.$tld);
        })->map(function ($site, $key) use ($tld) {
            // remove the TLD suffix for consistency
            return str_replace('.'.$tld, '', $site);
        })->reject(function ($site, $key) use ($links) {
            return $links->has($site);
        })->mapWithKeys(function ($site) {
            $host = $this->getProxyHostForSite($site) ?: '(other)';

            return [$site => $host];
        })->reject(function ($host, $site) {
            // If proxy host is null, it may be just a normal SSL stub, or something else; either way we exclude it from the list
            return $host === '(other)';
        })->map(function ($host, $site) use ($certs, $tld) {
            $secured = $certs->has($site);
            $url = ($secured ? 'https' : 'http').'://'.$site.'.'.$tld;

            return [
                'site' => $site,
                'secured' => $secured ? ' X' : '',
                'url' => $url,
                'path' => $host,
            ];
        });

        return $proxies;
    }

    /**
     * Identify whether a site is for a proxy by reading the host name from its config file.
     *
     * @param  string  $site  Site name without TLD
     * @param  string  $configContents  Config file contents
     * @return string|null
     */
    public function getProxyHostForSite($site, $configContents = null)
    {
        $siteConf = $configContents ?: $this->getSiteConfigFileContents($site);

        if (empty($siteConf)) {
            return null;
        }

        $host = null;
        if (preg_match('~proxy_pass\s+(?<host>https?://.*)\s*;~', $siteConf, $patterns)) {
            $host = trim($patterns['host']);
        }

        return $host;
    }

    public function getSiteConfigFileContents($site, $suffix = null)
    {
        $config = $this->config->read();
        $suffix = $suffix ?: '.'.$config['tld'];
        $file = str_replace($suffix, '', $site).$suffix;

        return $this->files->exists($this->nginxPath($file)) ? $this->files->get($this->nginxPath($file)) : null;
    }

    /**
     * Build the TLS secured Nginx server for the given URL.
     *
     * @param string $url
     * @return string
     */
    public function buildSecureNginxServer($url)
    {
        $path = $this->certificatesPath();

        return str_array_replace(
            [
                'VALET_HOME_PATH' => VALET_HOME_PATH,
                'VALET_SERVER_PATH' => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE' => $url,
                'VALET_CERT' => $path . '/' . $url . '.crt',
                'VALET_KEY' => $path . '/' . $url . '.key',
                'VALET_HTTP_PORT' => $this->config->get('port', 80),
                'VALET_HTTPS_PORT' => $this->config->get('https_port', 443),
                'VALET_REDIRECT_PORT' => $this->httpsSuffix(),
            ],
            $this->files->get(__DIR__ . '/../stubs/secure.valet.conf')
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param string $url
     * @return void
     */
    public function unsecure($url)
    {
        if ($this->files->exists($this->certificatesPath() . '/' . $url . '.crt')) {
            $this->files->unlink(VALET_HOME_PATH . '/Nginx/' . $url);

            $this->files->unlink($this->certificatesPath() . '/' . $url . '.conf');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.key');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.csr');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.crt');

            $this->cli->run(sprintf('certutil -d sql:$HOME/.pki/nssdb -D -n "%s"', $url));
            $this->cli->run(sprintf('certutil -d $HOME/.mozilla/firefox/*.default -D -n "%s"', $url));
        }
    }

    /**
     * Regenerate all secured file configurations
     *
     * @return void
     */
    public function regenerateSecuredSitesConfig()
    {
        $this->secured()->each(function ($url) {
            $this->createSecureNginxServer($url);
        });
    }

    /**
     * Get the path to the linked Valet sites.
     *
     * @return string
     */
    public function sitesPath()
    {
        return VALET_HOME_PATH . '/Sites';
    }

    /**
     * Get the path to the Valet CA certificates.
     *
     * @return string
     */
    public function caPath()
    {
        return VALET_HOME_PATH . '/CA';
    }

    /**
     * Get the path to the Valet TLS certificates.
     *
     * @return string
     */
    public function certificatesPath()
    {
        return VALET_HOME_PATH . '/Certificates';
    }

    /**
     * Get the path to Nginx site configuration files.
     */
    public function nginxPath($additionalPath = null)
    {
        return $this->valetHomePath().'/Nginx'.($additionalPath ? '/'.$additionalPath : '');
    }

}
