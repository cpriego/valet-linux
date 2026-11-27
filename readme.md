<p align="center"><img src="https://cdn.jsdelivr.net/gh/cpriego/valet-linux-docs@master/assets/valet-logo.png"></p>

<p align="center">
<a href="https://travis-ci.org/cpriego/valet-linux"><img src="https://travis-ci.org/cpriego/valet-linux.svg?branch=master" alt="Build Status"></a>
<a href="https://packagist.org/packages/cpriego/valet-linux"><img src="https://poser.pugx.org/cpriego/valet-linux/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/cpriego/valet-linux"><img src="https://poser.pugx.org/cpriego/valet-linux/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/cpriego/valet-linux"><img src="https://poser.pugx.org/cpriego/valet-linux/v/unstable.svg" alt="Latest Unstable Version"></a>
<a href="https://packagist.org/packages/cpriego/valet-linux"><img src="https://poser.pugx.org/cpriego/valet-linux/license.svg" alt="License"></a>
</p>

## Introduction

Valet *Linux* is a Laravel development environment for Linux minimalists. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux* configures your system to always run Nginx in the background when your machine starts. Then, using [DnsMasq](https://en.wikipedia.org/wiki/Dnsmasq), Valet proxies all requests on the `*.test` domain to point to sites installed on your local machine.

In other words, a blazing fast Laravel development environment that uses roughly 7MB of RAM. Valet *Linux* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## Upgrading to 3.0

Version 3.0 ports many features from upstream [Laravel Valet](https://github.com/laravel/valet) (v4.x) to Valet Linux.

### New

- Commands: `parked` (alias `unpark` for `forget`), `renew`, `log`, `directory-listing`, `diagnose`, `on-latest-version`, `which-php`, `php`, `composer`, `share-tool`, `set-ngrok-token`.
- Options: `link --isolate`, `secure --expireIn`, `unsecure --all`, `secured --expiring --days --ca`.
- `start`, `stop` and `restart` accept a service (`dnsmasq`, `nginx`, `php`; `restart` also accepts a PHP version like `php8.2`).
- Share tools: ngrok (default), cloudflared and expose.
- PHP version detection from `.valetrc` (`PHP=8.1`), `.valetphprc` or `composer.json`.
- New drivers: Nette, Radicle, BasicWithPublic. Updated drivers: Contao, Kirby, Craft.
- Sites can be reached over a local network by IP: `http://192.168.1.5/site.test/`.
- New dependency: `composer/semver`. Run `composer global update` after upgrading.

### Possible backwards compatibility problems

- **Statamic drivers renamed.** The old `StatamicValetDriver` (Statamic 2, `statamic/` directory) is now `StatamicV2ValetDriver`. A new `StatamicValetDriver` serves Statamic 3+ (`please` file, Laravel based). Custom drivers extending or referencing the old class must be updated.
- **Driver order changed.** `BasicWithPublicValetDriver` now runs before `BasicValetDriver`, so sites with a `public/` directory are served from it. Contao now looks for `web/` or `public/` with `index.php`; the old `app.php`/`app_dev.php` layout is no longer detected.
- **Certificates.** New certificates are valid 396 days (was 365) and use stricter extensions (`basicConstraints`, key identifiers). The CA is only created once; run `valet renew --ca` to regenerate the CA and all site certificates.
- **`valet secure` / `unsecure`** now append the domain only if it is missing (`valet secure foo.test` no longer becomes `foo.test.test`).
- **`valet unlink`** now also unsecures a secured site, and without a name uses the link of the current directory (fails if the directory is not linked or has several links).
- **`valet secured`** without a site now prints a table with expiration dates. `valet secured <site>` behaves as before.
- **sudo.** `valet link --secure|--isolate`, `unlink`, `renew` and `diagnose` now run through `sudo`. Commands are matched exactly, not by substring.
- **Share.** `valet fetch-share-url` takes an optional domain and uses the configured share tool. `valet share` uses ngrok unless `share-tool` is changed.
- **Nginx / PHP stubs.** Upload limit is 512M (was 128M), `fastcgi_read_timeout` is 1800s, and gzip no longer disables msie6. Run `valet install` (or `valet restart`) to regenerate the config. Previously missing `php-memory-limits.ini` and `error_log.ini` stubs are now installed when isolating a site.
- **`server.php`.** Rejects URIs containing `..` segments and unsets its internal variables before loading the application.
- **`valet.php` argument.** `valet php` and `valet composer` must be run through the `valet` script, not `cli/valet.php`.

## Official Documentation

Documentation for Valet can be found on the [Valet Linux website](https://cpriego.github.io/valet-linux/).

## License

Laravel Valet is an open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
