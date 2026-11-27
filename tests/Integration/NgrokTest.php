<?php

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Valet\Ngrok;

class NgrokTest extends TestCase
{
    private function tunnels()
    {
        return [
            (object) ['proto' => 'http', 'public_url' => 'http://a.ngrok.io', 'config' => (object) ['addr' => 'http://a.test:80']],
            (object) ['proto' => 'https', 'public_url' => 'https://b.ngrok.io', 'config' => (object) ['addr' => 'http://b.test:88']],
        ];
    }

    protected function setUp(): void
    {
        Container::setInstance(new Container);
    }

    public function test_finds_the_tunnel_of_the_given_domain()
    {
        $ngrok = resolve(Ngrok::class);

        $this->assertSame('http://a.ngrok.io', $ngrok->findHttpTunnelUrl($this->tunnels(), 'a.test'));
    }

    public function test_falls_back_to_the_https_tunnel()
    {
        $this->assertSame('https://b.ngrok.io', resolve(Ngrok::class)->findHttpTunnelUrl($this->tunnels(), 'b.test'));
    }

    public function test_returns_null_for_unknown_domains()
    {
        $this->assertNull(resolve(Ngrok::class)->findHttpTunnelUrl($this->tunnels(), 'c.test'));
    }

    public function test_without_a_domain_the_first_http_tunnel_is_used()
    {
        $this->assertSame('http://a.ngrok.io', resolve(Ngrok::class)->findHttpTunnelUrl($this->tunnels()));
    }
}
