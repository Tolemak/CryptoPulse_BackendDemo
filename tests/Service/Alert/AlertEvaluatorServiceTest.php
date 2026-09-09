<?php

namespace App\Tests\Service\Alert;

use App\Dto\AggregatedPrice;
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
    private function priceAt(float $median): AggregatedPrice
    {
        return new AggregatedPrice(Pair::BTC_USD, $median, [], new \DateTimeImmutable());
    }

    private function alert(AlertCondition $condition, float $threshold, bool $fired): AlertView
    {
        return new AlertView('a1', Pair::BTC_USD, $condition, $threshold, 'https://example.test/hook', new \DateTimeImmutable(), $fired);
    }

    public function testFiresWebhookWhenConditionFirstCrosses(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: false);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::once())->method('markFired')->with('a1', true);

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::once())->method('notify')->with($alert, 65000.0)->willReturn(true);

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate($this->priceAt(65000.0));
    }

    public function testDoesNotFireWhenConditionNotCrossed(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: false);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::never())->method('markFired');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notify');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate($this->priceAt(55000.0));
    }

    public function testDoesNotReFireWhileStillCrossed(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: true);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::never())->method('markFired');

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notify');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate($this->priceAt(70000.0));
    }

    public function testResetsFiredStateWithoutNotifyingWhenPriceCrossesBack(): void
    {
        $alert = $this->alert(AlertCondition::Above, 60000.0, fired: true);

        $repo = $this->createMock(AlertRepository::class);
        $repo->method('findByPair')->willReturn([$alert]);
        $repo->expects(self::once())->method('markFired')->with('a1', false);

        $notifier = $this->createMock(WebhookNotifier::class);
        $notifier->expects(self::never())->method('notify');

        (new AlertEvaluatorService($repo, $notifier, new NullLogger()))->evaluate($this->priceAt(50000.0));
    }
}
