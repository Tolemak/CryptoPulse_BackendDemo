<?php

namespace App\Tests\Controller;

use Predis\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AlertControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }

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
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: self::encode($this->validPayload()));

        self::assertResponseStatusCodeSame(201);
        $data = self::jsonBody($client);
        self::assertNotEmpty($data['id']);
        self::assertSame('BTC_USD', $data['pair']);
        self::assertSame('above', $data['condition']);
        self::assertEquals(60000.0, $data['threshold']);
        self::assertFalse($data['fired']);
    }

    public function testCreateAlertReturns422ForInvalidPayload(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: self::encode($this->validPayload(['threshold' => -5])));

        self::assertResponseStatusCodeSame(422);
        $data = self::jsonBody($client);
        self::assertArrayHasKey('violations', $data);
        self::assertArrayHasKey('threshold', $data['violations']);
    }

    public function testCreateAlertReturns422ForInvalidWebhookUrl(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: self::encode($this->validPayload(['webhookUrl' => 'not-a-url'])));

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetAndDeleteRoundTrip(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: self::encode($this->validPayload()));
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
        $client->request('GET', '/api/alerts/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }
    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
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
