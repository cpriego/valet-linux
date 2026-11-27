<?php

namespace Valet;

use DomainException;
use Httpful\Request;

class Expose
{
    public $cli;

    /**
     * Create a new Expose instance.
     *
     * @param CommandLine $cli
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Get the current tunnel URL from the Expose API.
     *
     * @param string $domain
     * @return string
     */
    public function currentTunnelUrl($domain)
    {
        $endpoint = 'http://127.0.0.1:4040/api/tunnels';

        // Httpful logs every failed connection attempt; keep that out of the terminal
        $errorLog = ini_set('error_log', '/dev/null');

        try {
            $response = retry(20, function () use ($endpoint, $domain) {
                $body = Request::get($endpoint)->send()->body;

                if (isset($body->tunnels) && count($body->tunnels) > 0) {
                    if ($tunnelUrl = $this->findHttpTunnelUrl((array) $body->tunnels, $domain)) {
                        return $tunnelUrl;
                    }
                }

                throw new DomainException('Tunnel not established.');
            }, 250);
        } catch (\Exception $e) {
            throw new DomainException(
                "The project $domain cannot be found as an Expose share.\nEither Expose is not running, it is not currently shared, or you may be on a free plan."
            );
        } finally {
            ini_set('error_log', $errorLog === false ? '' : $errorLog);
        }

        return $response;
    }

    /**
     * Find the HTTP tunnel URL from the list of tunnels.
     *
     * @param array  $tunnels
     * @param string $domain
     * @return string|null
     */
    public function findHttpTunnelUrl(array $tunnels, $domain)
    {
        foreach ($tunnels as $tunnel) {
            if (is_string($tunnel) && strpos($tunnel, strtolower($domain)) !== false) {
                return $tunnel;
            }
        }

        return null;
    }

    /**
     * Return whether Expose is installed.
     *
     * @return bool
     */
    public function installed()
    {
        return trim($this->cli->runAsUser('command -v expose')) !== '';
    }

    /**
     * Make sure Expose is installed.
     *
     * @return void
     */
    public function ensureInstalled()
    {
        if (!$this->installed()) {
            output('<info>Installing Expose with Composer...</info>');
            $this->cli->runAsUser('composer global require beyondcode/expose');
        }

        if (!$this->installed()) {
            throw new DomainException(
                'Expose could not be installed. Please make sure the Composer global bin directory is in your PATH.'
            );
        }
    }
}
