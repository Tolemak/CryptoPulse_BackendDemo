<?php

namespace App\Tests\Service\Alert;

use App\Dto\AlertView;
use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Service\Alert\WebhookNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    public function testNotifyReportsFailureWhenDeliveryIsBlocked(): void
    {
        self::bootKernel();
        $notifier = self::getContainer()->get(WebhookNotifier::class);
        self::assertInstanceOf(WebhookNotifier::class, $notifier);

        $alert = new AlertView(
            'test-alert',
            Pair::BTC_USD,
            AlertCondition::Above,
            50_000.0,
            'http://169.254.169.254/latest/meta-data/',
            new \DateTimeImmutable(),
            false,
        );

        self::assertFalse($notifier->notify($alert, 100.0));
    }
}
