<?php

namespace App\Tests\Controller;

use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Requests reach the app as Cloudflare -> host reverse proxy -> container, so
 * REMOTE_ADDR is always a private address and the client IP has to come from
 * X-Forwarded-For.
 */
final class ProxyAndHeadersTest extends WebTestCase
{
    private const string DOCKER_GATEWAY = '172.18.0.1';
    private const string CLOUDFLARE_EDGE = '162.158.10.20';

    protected function setUp(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }

    public function testInboundLimitIsKeyedByTheRealClientBehindCloudflare(): void
    {
        $client = static::createClient();
        $this->exhaustInboundLimitFor('84.10.20.30');

        $client->request('GET', '/api/prices', server: $this->viaCloudflare('84.10.20.30'));
        self::assertResponseStatusCodeSame(429);

        $client->request('GET', '/api/prices', server: $this->viaCloudflare('84.10.20.31'));
        self::assertResponseIsSuccessful();
    }

    public function testASpoofedForwardedForEntryCannotDodgeTheLimit(): void
    {
        $client = static::createClient();
        $this->exhaustInboundLimitFor('84.10.20.30');

        $client->request('GET', '/api/prices', server: [
            'REMOTE_ADDR' => self::DOCKER_GATEWAY,
            'HTTP_X_FORWARDED_FOR' => '91.200.1.2, 84.10.20.30, '.self::CLOUDFLARE_EDGE,
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testForwardedHeadersFromAnUntrustedPeerAreIgnored(): void
    {
        $client = static::createClient();
        $this->exhaustInboundLimitFor('84.10.20.30');

        $client->request('GET', '/api/prices', server: [
            'REMOTE_ADDR' => '84.10.20.30',
            'HTTP_X_FORWARDED_FOR' => '91.200.1.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertResponseStatusCodeSame(429);
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));
    }

    public function testApiResponsesCarrySecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices', server: $this->viaCloudflare('84.10.20.30'));

        $headers = $client->getResponse()->headers;
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame('max-age=31536000', $headers->get('Strict-Transport-Security'));
    }

    public function testErrorResponsesCarrySecurityHeadersToo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices/NOT_A_PAIR');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testNoHstsOverPlainHttp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices');

        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));
    }

    public function testNoSessionCookieIsIssued(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices');

        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testBrowsersMayReadRetryAfterCrossOrigin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/prices', server: ['HTTP_ORIGIN' => 'http://localhost:5173']);

        self::assertSame('http://localhost:5173', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('retry-after', strtolower((string) $client->getResponse()->headers->get('Access-Control-Expose-Headers')));
    }

    /**
     * @return array<string, string>
     */
    private function viaCloudflare(string $clientIp): array
    {
        return [
            'REMOTE_ADDR' => self::DOCKER_GATEWAY,
            'HTTP_X_FORWARDED_FOR' => $clientIp.', '.self::CLOUDFLARE_EDGE,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
    }

    private function exhaustInboundLimitFor(string $clientIp): void
    {
        $limiter = static::getContainer()->get('limiter.api_inbound');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        $limiter->create($clientIp)->consume(60);
    }
}
