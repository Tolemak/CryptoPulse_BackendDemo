<?php

namespace App\Tests\Service\Alert;

use App\Dto\AlertTrigger;
use App\Dto\AlertView;
use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Service\Alert\WebhookNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookNotifierTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function privateWebhookUrlProvider(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:9999/hook', '127.0.0.1'];
        yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data/', '169.254.169.254'];
        yield 'private range' => ['http://192.168.1.20/hook', '192.168.1.20'];
    }

    /**
     * Asserts the address is refused as blocked rather than merely unreachable -
     * a plain client would also fail here, just for the wrong reason.
     */
    #[DataProvider('privateWebhookUrlProvider')]
    public function testWebhookClientRefusesPrivateAddresses(string $webhookUrl, string $blockedIp): void
    {
        self::bootKernel();
        $client = self::getContainer()->get('app.http_client.webhook');
        self::assertInstanceOf(HttpClientInterface::class, $client);

        try {
            $client->request('POST', $webhookUrl)->getStatusCode();
            self::fail('Expected the request to be blocked.');
        } catch (TransportExceptionInterface $e) {
            self::assertStringContainsString($blockedIp, $e->getMessage());
            self::assertStringContainsString('blocked', $e->getMessage());
        }
    }

    public function testReportsFailureWhenDeliveryIsBlocked(): void
    {
        self::bootKernel();
        $notifier = self::getContainer()->get(WebhookNotifier::class);
        self::assertInstanceOf(WebhookNotifier::class, $notifier);

        $trigger = self::trigger('test-alert', 'http://169.254.169.254/latest/meta-data/');

        self::assertSame(['test-alert' => false], $notifier->notifyAll([$trigger]));
    }

    public function testPostsTheAlertPayload(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertSame('POST', $method);
            self::assertSame('https://example.test/hook', $url);
            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['alertId' => 'a1', 'pair' => 'BTC_USD', 'condition' => 'above', 'threshold' => 60000.0, 'price' => 65000.5], array_diff_key($body, ['firedAt' => true]));

            return new MockResponse('', ['http_code' => 204]);
        });

        self::assertSame(['a1' => true], (new WebhookNotifier($httpClient, new NullLogger()))->notifyAll([self::trigger('a1')]));
    }

    public function testRetriesUntilTheReceiverAnswers2xx(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 200]),
        ]);

        self::assertSame(['a1' => true], (new WebhookNotifier($httpClient, new NullLogger()))->notifyAll([self::trigger('a1')]));
        self::assertSame(2, $httpClient->getRequestsCount());
    }

    public function testGivesUpAfterThreeAttemptsAndCountsRedirectsAsFailures(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://127.0.0.1/']]));

        self::assertSame(['a1' => false], (new WebhookNotifier($httpClient, new NullLogger()))->notifyAll([self::trigger('a1')]));
        self::assertSame(3, $httpClient->getRequestsCount());
    }

    public function testOnlyFailedDeliveriesAreRetried(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url) => str_contains($url, 'down')
            ? new MockResponse('', ['error' => 'connection refused'])
            : new MockResponse('', ['http_code' => 200]));

        $result = (new WebhookNotifier($httpClient, new NullLogger()))->notifyAll([
            self::trigger('ok', 'https://up.example.test/hook'),
            self::trigger('ko', 'https://down.example.test/hook'),
        ]);

        self::assertSame(['ok' => true, 'ko' => false], $result);
        self::assertSame(4, $httpClient->getRequestsCount());
    }

    public function testARequestThatCannotBeSentIsAFailureNotACrash(): void
    {
        $httpClient = new MockHttpClient(fn () => throw new TransportException('Unsupported scheme.'));

        $result = (new WebhookNotifier($httpClient, new NullLogger()))->notifyAll([self::trigger('a1', 'gopher://example.test/hook')]);

        self::assertSame(['a1' => false], $result);
    }

    private static function trigger(string $id, string $webhookUrl = 'https://example.test/hook'): AlertTrigger
    {
        $alert = new AlertView($id, Pair::BTC_USD, AlertCondition::Above, 60000.0, $webhookUrl, new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'), false);

        return new AlertTrigger($alert, 65000.5);
    }
}
