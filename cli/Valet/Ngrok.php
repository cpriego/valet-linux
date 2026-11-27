<?php

namespace Valet;

use DomainException;
use Exception;
use Httpful\Request;

class Ngrok
{
    public $tunnelsEndpoints = [
        'http://127.0.0.1:4040/api/tunnels',
        'http://127.0.0.1:4041/api/tunnels',
    ];

    public $cli;

    /**
     * Create a new Ngrok instance.
     *
     * @param CommandLine $cli
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Get the current tunnel URL from the Ngrok API.
     *
     * @param string|null $domain Only look for tunnels forwarding to this domain
     * @return string
     */
    public function currentTunnelUrl($domain = null)
    {
        // Wait a second for ngrok to start before attempting to find available tunnels
        sleep(1);

        // Httpful logs every failed connection attempt; keep that out of the terminal
        $errorLog = ini_set('error_log', '/dev/null');

        try {
            foreach ($this->tunnelsEndpoints as $endpoint) {
                try {
                    $response = retry(20, function () use ($endpoint, $domain) {
                        $body = Request::get($endpoint)->send()->body;

                        if (isset($body->tunnels) && count($body->tunnels) > 0) {
                            if ($tunnelUrl = $this->findHttpTunnelUrl($body->tunnels, $domain)) {
                                return $tunnelUrl;
                            }
                        }

                        throw new DomainException('Failed to retrieve tunnel URL.');
                    }, 250);

                    if (!empty($response)) {
                        return $response;
                    }
                } catch (Exception $e) {
                    // Suppress the exception to check the other port
                }
            }
        } finally {
            ini_set('error_log', $errorLog === false ? '' : $errorLog);
        }

        throw new DomainException('There is no Ngrok tunnel established' . ($domain ? ' for ' . $domain : '') . '.');
    }

    /**
     * Find the HTTP/HTTPS tunnel URL from the list of tunnels.
     *
     * @param array       $tunnels
     * @param string|null $domain
     * @return string|null
     */
    public function findHttpTunnelUrl(array $tunnels, $domain = null)
    {
        $httpTunnel = null;
        $httpsTunnel = null;

        // If there are active tunnels on the Ngrok instance we will spin through them and
        // find the one responding on HTTP. Each tunnel has an HTTP and a HTTPS address
        // if no HTTP tunnel is found we will return the HTTPS tunnel as a fallback.
        foreach ($tunnels as $tunnel) {
            if ($domain !== null && stripos($tunnel->config->addr, $domain) === false) {
                continue;
            }

            if ($tunnel->proto === 'http') {
                $httpTunnel = $tunnel->public_url;
            } elseif ($tunnel->proto === 'https') {
                $httpsTunnel = $tunnel->public_url;
            }
        }

        return $httpTunnel ?: $httpsTunnel;
    }

    /**
     * Set the Ngrok auth token.
     *
     * @param string $token
     * @return string
     */
    public function setToken($token)
    {
        return $this->cli->runAsUser(escapeshellarg(dirname(__DIR__, 2) . '/bin/ngrok') . ' authtoken ' . escapeshellarg($token));
    }

    /**
     * Return whether ngrok is installed.
     *
     * @return bool
     */
    public function installed()
    {
        return is_executable(dirname(__DIR__, 2) . '/bin/ngrok');
    }

    /**
     * Make sure ngrok is installed.
     *
     * @return void
     */
    public function ensureInstalled()
    {
        if (!$this->installed()) {
            throw new DomainException('The bundled ngrok binary could not be found in [' . dirname(__DIR__, 2) . '/bin/ngrok].');
        }
    }
}
