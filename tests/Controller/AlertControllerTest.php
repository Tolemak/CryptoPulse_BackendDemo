<?php

namespace App\Tests\Controller;

use App\Tests\Support\IsolatedRedis;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class AlertControllerTest extends WebTestCase
{
    use IsolatedRedis;

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'pair' => 'BTC_USD',
            'condition' => 'above',
            'threshold' => 60000.0,
            'webhookUrl' => 'https://example.test/hook',
        ], $overrides);
    }

    public function testCreateAlertReturns201WithAlertView(): void
    {
        $client = static::createClient();
        $this->post($client, $this->validPayload());

        self::assertResponseStatusCodeSame(201);
        $data = self::jsonBody($client);
        self::assertNotEmpty($data['id']);
        self::assertSame('BTC_USD', $data['pair']);
        self::assertSame('above', $data['condition']);
        self::assertEquals(60000.0, $data['threshold']);
        self::assertFalse($data['fired']);
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), new \DateTimeImmutable($data['expiresAt']));
    }

    public function testCreateAlertReturns422ForInvalidPayload(): void
    {
        $client = static::createClient();
        $this->post($client, $this->validPayload(['threshold' => -5]));

        self::assertResponseStatusCodeSame(422);
        $data = self::jsonBody($client);
        self::assertArrayHasKey('violations', $data);
        self::assertArrayHasKey('threshold', $data['violations']);
    }

    public function testCreateAlertReturns422ForInvalidWebhookUrl(): void
    {
        $client = static::createClient();
        $this->post($client, $this->validPayload(['webhookUrl' => 'not-a-url']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateAlertReturns422ForAnOverlongWebhookUrl(): void
    {
        $client = static::createClient();
        $this->post($client, $this->validPayload(['webhookUrl' => 'https://example.test/'.str_repeat('a', 2100)]));

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('webhookUrl', self::jsonBody($client)['violations']);
    }

    public function testCreateAlertIsRateLimitedPerClient(): void
    {
        $client = static::createClient();
        $limiter = static::getContainer()->get('limiter.alert_create');
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        $limiter->create('8.8.4.40')->consume(10);

        $this->post($client, $this->validPayload(), ['REMOTE_ADDR' => '8.8.4.40']);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));

        $this->post($client, $this->validPayload(), ['REMOTE_ADDR' => '8.8.4.41']);
        self::assertResponseStatusCodeSame(201);
    }

    public function testGetAndDeleteRoundTrip(): void
    {
        $client = static::createClient();
        $this->post($client, $this->validPayload());
        $id = self::jsonBody($client)['id'];

        $client->request('GET', "/api/alerts/{$id}");
        self::assertResponseIsSuccessful();

        $client->request('DELETE', "/api/alerts/{$id}");
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', "/api/alerts/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testGetUnknownAlertReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/alerts/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonUuidIdIsRejectedAtTheRoute(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/alerts/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertArrayHasKey('error', self::jsonBody($client));
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $server
     */
    private function post(KernelBrowser $client, array $payload, array $server = []): void
    {
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'] + $server, content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private static function jsonBody(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
