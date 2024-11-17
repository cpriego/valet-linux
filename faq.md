[Main page](index)

# F.A.Q.
- [Why can't I run `valet install`?](#why-cant-i-run-valet-install)
- [Where can I find the log files?](#where-can-i-find-the-log-files)
- [Valet home directory is inside /root](#valet-home-directory-is-inside-root)
- [`valet install` PHP Fatal error: Uncaught ReflectionException](#valet-install-php-fatal-error-uncaught-reflectionexception)
- [What about the Database?](#what-about-the-database)
- [Why do I get certificate errors after securing a site?](#why-do-i-get-certificate-errors-after-securing-a-site)
- [What files does Valet _Linux_ change?](#what-files-does-valet-linux-change)
- [Why is my network connection dropped after installing or changing the TLD](#why-is-my-network-connection-dropped-after-installing-or-changing-the-tld)
- [Issue `valet secure` doesnt work?](#issue-valet-secure-doesnt-work)
- [Any other tips?](#any-other-tips)

## Why can't I run `valet install`?

Check that you've added the `.composer/vendor/bin` or `.config/composer/vendor/bin` directory to your `PATH` in either `~/.bashrc` or `~/.zshrc`.

If you don't want the composer global tools added to your path simply run `valet install` directly with the next command:
```
test -d ~/.composer && bash ~/.composer/vendor/bin/valet install || bash ~/.config/composer/vendor/bin/valet install
```


## Valet home directory is inside /root

You should not run Valet as `root` user. It must be run with a `sudo` user with the `$HOME` environment set.

Please refer to this [wiki page](https://github.com/cpriego/valet-linux/wiki/Sudo-and-the-HOME-Environment-Variable) to fix it.


## Where can I find the log files?

Valet places its log files inside the `$HOME/.valet/Log` folder.

You will also find all of the Valet configuration files in the `$HOME/.valet` directory.


## `valet install` PHP Fatal error: Uncaught ReflectionException

This error occurs when you have old versions of packages installed. Please do a `composer global update` to see if that fixes your issue.

Another possible cause is a conflicting package. In that case, with the help of the command `composer global show` check that there are no versions of `cpriego/valet-ubuntu` or `jmarcher/valet-linux` installed.


## What about the Database?

Well, your choice! You could use the superlight SQLite **`sqlite3`**, the extremely versatile MariaDB/MySQL **`mariadb-server or mysql-server`** or even the powerful PostgreSQL **`postgresql`**. Just don't forget to install the corresponding php package for it.


## Why do I get certificate errors after securing a site?

Because of the way Firefox and Chrome/Chromium/Opera/Any.Other.Blink.Based.Browser manages certificates in Linux the experience when **securing** a site might not be as smooth as it is in OSX.

Whenever you secure a site you'll need to restart your testing browser so that it can trust the new certificate and you'll have to do the same when you unsecure it.

If you have **secured** a domain you will not be able to share it through Ngrok.


## What files does Valet _Linux_ change?

Valet 2.0 will overwrite the Nginx, PhpFPM config files. If you've previously configured Nginx please backup your files before upgrading.


## Why is my network connection dropped after installing or changing the TLD?

**NetworkManager** loves being involved in everything network-related including DNS. We configure **DnsMasq** through **NetworkManager** so your network connection _**might**_ drop whenever you **install** Valet or change the domain. To solve this simply reconnect to your network.

## Issue `valet secure` doesnt work?
Solutions:

Chrome : Search for Manage Certificates > Authorities > Import LaravelValetCASelfSigned

FireFox : Search Certificates > View Certificates > Authorities > Import LaravelValetCASelfSigned

Path: LaravelValetCASelfSigned
```bash
cd ~/.valet/CA
```

[Relate Issue](https://github.com/cpriego/valet-linux/issues/233)


## Any other tips?

Oh yeah!, for those looking for a beautiful looking Database management tool like Sequel Pro but for Linux* try out Valentina Studio, it's free, multiplatform and supports all of the databases mentioned above.

[You can check it here](https://www.valentina-db.com/en/valentina-studio-overview)

[And download it here](https://www.valentina-db.com/en/studio/download)

_* I know it is GNU/Linux but is too long and it confuses people even more_
