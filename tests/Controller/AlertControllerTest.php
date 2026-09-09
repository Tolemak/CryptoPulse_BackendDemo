<?php

namespace App\Tests\Controller;

use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AlertControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        (new Client($_ENV['REDIS_URL']))->flushdb();
    }

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
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->validPayload()));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['id']);
        self::assertSame('BTC_USD', $data['pair']);
        self::assertSame('above', $data['condition']);
        self::assertEquals(60000.0, $data['threshold']);
        self::assertFalse($data['fired']);
    }

    public function testCreateAlertReturns422ForInvalidPayload(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->validPayload(['threshold' => -5])));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('violations', $data);
        self::assertArrayHasKey('threshold', $data['violations']);
    }

    public function testCreateAlertReturns422ForInvalidWebhookUrl(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->validPayload(['webhookUrl' => 'not-a-url'])));

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetAndDeleteRoundTrip(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/alerts', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->validPayload()));
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

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
}
