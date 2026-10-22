<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Services\ReportService;

final class ReportMathTest extends TestCase
{
    /** @param array<string,int> $overrides @return array<string,int> */
    private function metrics(array $overrides = []): array
    {
        return array_merge([
            'total_recipients' => 0, 'attempted' => 0, 'accepted' => 0, 'delivered' => 0,
            'pending' => 0, 'failed' => 0, 'suppressed' => 0, 'cancelled' => 0, 'unsubscribes' => 0,
            'hard_bounces' => 0, 'soft_bounces' => 0, 'rejects' => 0, 'complaints' => 0,
            'total_opens' => 0, 'total_clicks' => 0, 'unique_opens' => 0, 'unique_clicks' => 0,
        ], $overrides);
    }

    public function testDeliveryRateDividesByAcceptedNotAttempted(): void
    {
        $rates = ReportService::rates($this->metrics([
            'attempted' => 1000,
            'accepted'  => 800,
            'delivered' => 600,
        ]));

        // 600/800, not 600/1000.
        self::assertSame(75.0, $rates['delivery_rate']);
    }

    public function testBounceRateDividesByAttempted(): void
    {
        $rates = ReportService::rates($this->metrics([
            'attempted'    => 1000,
            'accepted'     => 900,
            'hard_bounces' => 30,
            'soft_bounces' => 20,
        ]));

        self::assertSame(5.0, $rates['bounce_rate']);
        self::assertSame(3.0, $rates['hard_bounce_rate']);
    }

    public function testComplaintRateDividesByAccepted(): void
    {
        $rates = ReportService::rates($this->metrics([
            'attempted'  => 1000,
            'accepted'   => 1000,
            'complaints' => 1,
        ]));

        self::assertSame(0.1, $rates['complaint_rate']);
    }

    public function testEveryRateIsNullWhenItsDenominatorIsZero(): void
    {
        $rates = ReportService::rates($this->metrics());

        foreach ($rates as $name => $value) {
            self::assertNull($value, "{$name} must be null, not 0%, when nothing can be divided.");
        }
    }

    public function testZeroNumeratorStillProducesZeroNotNull(): void
    {
        $rates = ReportService::rates($this->metrics(['attempted' => 100, 'accepted' => 100]));

        self::assertSame(0.0, $rates['delivery_rate'], 'Nothing delivered of 100 accepted is a real 0%.');
        self::assertSame(0.0, $rates['bounce_rate']);
    }

    public function testEveryRateHasADocumentedDenominator(): void
    {
        $rates = ReportService::rates($this->metrics());

        foreach (array_keys($rates) as $name) {
            self::assertArrayHasKey(
                $name,
                ReportService::DENOMINATORS,
                "{$name} is shown in the UI but its denominator is not documented.",
            );
        }
    }

    // ------------------------------------------------------- data quality

    public function testMissingDeliveryEventsAreReported(): void
    {
        $notes = ReportService::dataQualityNotes($this->metrics([
            'attempted' => 500,
            'accepted'  => 500,
            'delivered' => 0,
        ]));

        self::assertCount(1, $notes);
        self::assertStringContainsString('No delivery events', $notes[0]);
    }

    public function testBouncesExplainAbsentDeliveries(): void
    {
        // Accepted but nothing delivered *because everything bounced* is not a
        // monitoring gap, so it must not be reported as one.
        $notes = ReportService::dataQualityNotes($this->metrics([
            'attempted'    => 10,
            'accepted'     => 10,
            'delivered'    => 0,
            'hard_bounces' => 10,
        ]));

        self::assertSame([], $notes);
    }

    public function testOpenRateAboveOneHundredPercentIsExplained(): void
    {
        $metrics = $this->metrics([
            'attempted'    => 100,
            'accepted'     => 100,
            'delivered'    => 10,
            'unique_opens' => 40,
        ]);

        self::assertSame(400.0, ReportService::rates($metrics)['open_rate'], 'The arithmetic is not fudged.');

        $notes = ReportService::dataQualityNotes($metrics);
        self::assertCount(1, $notes);
        self::assertStringContainsString('above 100%', $notes[0]);
    }

    public function testHealthyDataProducesNoNotes(): void
    {
        $notes = ReportService::dataQualityNotes($this->metrics([
            'attempted'     => 1000,
            'accepted'      => 1000,
            'delivered'     => 980,
            'hard_bounces'  => 15,
            'unique_opens'  => 300,
            'unique_clicks' => 40,
        ]));

        self::assertSame([], $notes);
    }

    public function testDeliveriesExceedingAcceptedAreFlagged(): void
    {
        $notes = ReportService::dataQualityNotes($this->metrics([
            'attempted' => 10,
            'accepted'  => 10,
            'delivered' => 25,
        ]));

        self::assertCount(1, $notes);
        self::assertStringContainsString('exceed', $notes[0]);
    }
}
