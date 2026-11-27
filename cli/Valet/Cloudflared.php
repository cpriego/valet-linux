<?php

namespace Valet;

use DomainException;
use Httpful\Request;

class Cloudflared
{
    public $cli;

    /**
     * Create a new Cloudflared instance.
     *
     * @param CommandLine $cli
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Get the current tunnel URL of a cloudflared process sharing the given domain.
     *
     * @param string $domain
     * @return string|false
     */
    public function currentTunnelUrl($domain)
    {
        // Every cloudflared process will start a "metrics" web server where the
        // Quick Tunnel URL will be mentioned under the /metrics endpoint; there
        // can potentially be more than one process that matches, but the lsof
        // command will show which one is actually functionally running
        foreach (array_filter(explode(PHP_EOL, $this->cli->run('pgrep -fl cloudflared'))) as $process) {
            preg_match('/(?<pid>\d+)\s.+--http-host-header\s(?<domain>[^\s]+).*/', $process, $pgrepMatches);

            if (!isset($pgrepMatches['domain'], $pgrepMatches['pid']) || $pgrepMatches['domain'] !== $domain) {
                continue;
            }

            // Get the localhost URL for the metrics server
            $lsof = $this->cli->run('lsof -iTCP -P -a -p ' . (int) $pgrepMatches['pid']);
            preg_match('/TCP\s(?<server>[^\s]+:\d+)\s\(LISTEN\)/', $lsof, $lsofMatches);

            if (!isset($lsofMatches['server'])) {
                continue;
            }

            try {
                $body = Request::get('http://' . $lsofMatches['server'] . '/metrics')->send()->raw_body;
                preg_match('/userHostname="(?<url>.+)"/', (string) $body, $userHostnameMatches);
            } catch (\Exception $e) {
                return false;
            }

            if (isset($userHostnameMatches['url'])) {
                return $userHostnameMatches['url'];
            }
        }

        return false;
    }

    /**
     * Return whether cloudflared is installed.
     *
     * @return bool
     */
    public function installed()
    {
        return trim($this->cli->run('command -v cloudflared')) !== '';
    }

    /**
     * Make sure cloudflared is installed.
     *
     * @return void
     */
    public function ensureInstalled()
    {
        if (!$this->installed()) {
            throw new DomainException(
                'cloudflared is not installed. Please install it from https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/'
            );
        }
    }
}
