<?php

namespace Valet;

use Illuminate\Support\Collection;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

class Diagnose
{
    public $commands = [
        'uname -a',
        'cat /etc/os-release',
        'valet --version',
        'cat ~/.valet/config.json',
        'composer global diagnose',
        'composer global outdated',
        'ls -al /etc/sudoers.d/',
        'php -v',
        'which -a php',
        'php --ini',
        'ls -al /usr/bin/php',
        'nginx -v',
        'curl --version',
        'php --ri curl',
        'openssl version -a',
        'certutil --help 2>&1 | head -n 1',
        'which -a php-fpm',
        'ls -al /usr/sbin | grep php',
        'ps aux | grep -E "(nginx|php-fpm|dnsmasq)" | grep -v grep',
        'systemctl --no-pager status nginx dnsmasq',
        'systemctl --no-pager list-units "php*fpm*"',
        'nginx -t',
        'ls -aln /etc/resolv.conf',
        'cat /etc/resolv.conf',
        'ls -al /etc/dnsmasq.d/',
        'cat /etc/dnsmasq.d/valet',
        'ls -al ~/.valet',
        'ls -al ~/.valet/Sites',
        'ls -al ~/.valet/Nginx',
        'ls -al ~/.valet/Certificates',
        'tail -n 50 ~/.valet/Log/nginx-error.log',
        'tail -n 50 ~/.valet/Log/php-fpm.log',
    ];

    public $print = false;

    public $progressBar = null;

    public $cli;
    public $files;

    /**
     * Create a new Diagnose instance.
     *
     * @param CommandLine $cli
     * @param Filesystem  $files
     */
    public function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Run diagnostics.
     *
     * @param bool $print     Print the output of every command while running
     * @param bool $plainText Format the result as plain text instead of GitHub markdown
     * @return string|null The path of the fallback file when the clipboard is not available
     */
    public function run($print, $plainText)
    {
        $this->print = $print;

        $this->beforeRun();

        $results = collect($this->commands)->map(function ($command) {
            $this->beforeCommand($command);

            $output = $this->runCommand($command);

            $this->afterCommand($command, $output);

            return compact('command', 'output');
        })->values();

        $output = $this->format($results, $plainText);

        $this->afterRun();

        if ($this->copyToClipboard($output)) {
            return null;
        }

        $path = getcwd() . '/valet_diagnostics.txt';
        $this->files->putAsUser($path, $output);

        return $path;
    }

    /**
     * Try to copy the given text to the clipboard of the current user.
     *
     * @param string $text
     * @return bool
     */
    public function copyToClipboard($text)
    {
        $file = tempnam(sys_get_temp_dir(), 'valet');
        $this->files->put($file, $text);
        chmod($file, 0644);

        $env = 'DISPLAY=' . escapeshellarg(getenv('DISPLAY') ?: ':0');

        $tools = [
            'xsel' => 'xsel -b < ' . escapeshellarg($file),
            'xclip' => 'xclip -selection clipboard < ' . escapeshellarg($file),
            'wl-copy' => 'wl-copy < ' . escapeshellarg($file),
        ];

        $copied = false;

        foreach ($tools as $tool => $command) {
            if (trim($this->cli->run('command -v ' . $tool)) === '') {
                continue;
            }

            $failed = false;
            $this->cli->runAsUser('env ' . $env . ' sh -c ' . escapeshellarg($command), function () use (&$failed) {
                $failed = true;
            });

            if (!$failed) {
                $copied = true;
                break;
            }
        }

        $this->files->unlink($file);

        return $copied;
    }

    public function beforeRun()
    {
        if ($this->print) {
            return;
        }

        $this->progressBar = new ProgressBar(new ConsoleOutput, count($this->commands));

        $this->progressBar->start();
    }

    public function afterRun()
    {
        if ($this->progressBar) {
            $this->progressBar->finish();
        }

        output('');
    }

    /**
     * Run a command, as the regular user unless it needs root.
     *
     * @param string $command
     * @return string
     */
    public function runCommand($command)
    {
        $command = $command . ' 2>&1';

        return in_array(explode(' ', $command)[0], ['nginx', 'systemctl', 'ps'], true)
            ? $this->cli->run($command)
            : $this->cli->runAsUser('sh -c ' . escapeshellarg($command));
    }

    public function beforeCommand($command)
    {
        if ($this->print) {
            info(PHP_EOL . "$ $command");
        }
    }

    public function afterCommand($command, $output)
    {
        if ($this->print) {
            output(trim($output));
        } else {
            $this->progressBar->advance();
        }
    }

    public function format(Collection $results, $plainText)
    {
        return $results->map(function ($result) use ($plainText) {
            $command = $result['command'];
            $output = trim($result['output']);

            if ($plainText) {
                return implode(PHP_EOL, ["$ {$command}", $output]);
            }

            return sprintf(
                '<details>%s<summary>%s</summary>%s<pre>%s</pre>%s</details>',
                PHP_EOL,
                htmlspecialchars($command, ENT_NOQUOTES),
                PHP_EOL,
                htmlspecialchars($output, ENT_NOQUOTES),
                PHP_EOL
            );
        })->implode($plainText ? PHP_EOL . str_repeat('-', 20) . PHP_EOL : PHP_EOL);
    }
}
