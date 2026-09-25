<?php

namespace App\Tests\Service\Alert;

use App\Dto\AggregatedPrice;
use App\Dto\AlertTrigger;
use App\Dto\AlertView;
use App\Enum\AlertCondition;
use App\Enum\Pair;
use App\Service\Alert\AlertEvaluatorService;
use App\Service\Alert\AlertRepository;
use App\Service\Alert\WebhookNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AlertEvaluatorServiceTest extends TestCase
{
    private function priceAt(float $median, Pair $pair = Pair::BTC_USD): AggregatedPrice
    {
        return new AggregatedPrice($pair, $median, [], new \DateTimeImmutable());
    }

    private function alert(AlertCondition $condition, float $threshold, bool $fired, string $id = 'a1'): AlertView
    {
        return new AlertView($id, Pair::BTC_USD, $condition, $threshold, 'https://example.test/hook', new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'), $fired);
    }

    public function testClaimsAndNotifiesWhenConditionFirstCrosses(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: false);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::once())->method('claimFiring')->with('a1')->willReturn(true);
        $repo->expects(self::never())->method('rearm');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::once())->method('notifyAll')
            ->with([new AlertTrigger($alert, 65000.0)])
            ->willReturn(['a1' => true]);

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(65000.0)]);
    }

    public function testRearmsWhenDeliveryFailsSoTheNextPollRetries(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: false);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->method('claimFiring')->willReturn(true);
        $repo->expects(self::once())->method('rearm')->with('a1');

        $notifier = $this->createStub(WebhookNotifier::class);
        $notifier->method('notifyAll')->willReturn(['a1' => false]);

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(65000.0)]);
    }

    public function testDoesNotNotifyWhenAnotherRunAlreadyClaimedTheAlert(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: false);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->method('claimFiring')->willReturn(false);
        $repo->expects(self::never())->method('rearm');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notifyAll');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(65000.0)]);
    }

    public function testDoesNotFireWhenConditionNotCrossed(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: false);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::never())->method('claimFiring');
        $repo->expects(self::never())->method('rearm');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notifyAll');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(55000.0)]);
    }

    public function testDoesNotReFireWhileStillCrossed(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: true);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::never())->method('claimFiring');
        $repo->expects(self::never())->method('rearm');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notifyAll');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(70000.0)]);
    }

    public function testRearmsWithoutNotifyingWhenPriceCrossesBack(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: true);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::once())->method('rearm')->with('a1');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notifyAll');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(50000.0)]);
    }

    public function testSendsEveryCrossedAlertOfThePollInOneBatch(): void
    {
        $btc = $this->alert(AlertCondition::Above, 60000.0, fired: false, id: 'btc');
        $eth = new AlertView('eth', Pair::ETH_USD, AlertCondition::Below, 3000.0, 'https://example.test/hook', new \DateTimeImmutable(), new \DateTimeImmutable('+1 day'), false);

        $repo = $this->createStub(AlertRepository::class);
        $repo->method('findByPair')->willReturnCallback(fn (Pair $pair) => Pair::BTC_USD === $pair ? [$btc] : [$eth]);
        $repo->method('claimFiring')->willReturn(true);

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::once())->method('notifyAll')
            ->with([new AlertTrigger($btc, 65000.0), new AlertTrigger($eth, 2500.0)])
            ->willReturn(['btc' => true, 'eth' => true]);

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate([$this->priceAt(65000.0), $this->priceAt(2500.0, Pair::ETH_USD)]);
    }
}
