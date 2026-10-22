<?php

declare(strict_types=1);

namespace Techzapp\Crm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Techzapp\Crm\Services\CampaignService;

final class CampaignStateMachineTest extends TestCase
{
    #[DataProvider('legalTransitions')]
    public function testLegalTransitionsAreAllowed(string $from, string $to): void
    {
        self::assertTrue(CampaignService::canTransition($from, $to));
    }

    public static function legalTransitions(): array
    {
        return [
            ['draft', 'scheduled'],
            ['draft', 'queued'],
            ['scheduled', 'queued'],
            ['scheduled', 'draft'],
            ['scheduled', 'cancelled'],
            ['queued', 'sending'],
            ['sending', 'paused'],
            ['sending', 'completed'],
            ['paused', 'queued'],
            ['failed', 'draft'],
        ];
    }

    #[DataProvider('illegalTransitions')]
    public function testIllegalTransitionsAreRefused(string $from, string $to): void
    {
        self::assertFalse(CampaignService::canTransition($from, $to));
    }

    public static function illegalTransitions(): array
    {
        return [
            // The important one: a completed campaign can never start again,
            // so a cron run that fires twice cannot re-send it.
            'completed cannot restart'  => ['completed', 'sending'],
            'completed cannot queue'    => ['completed', 'queued'],
            'completed cannot schedule' => ['completed', 'scheduled'],
            'completed cannot pause'    => ['completed', 'paused'],
            'cancelled cannot restart'  => ['cancelled', 'queued'],
            'cancelled cannot send'     => ['cancelled', 'sending'],
            'draft cannot send'         => ['draft', 'sending'],
            'draft cannot complete'     => ['draft', 'completed'],
            'paused cannot complete'    => ['paused', 'completed'],
            'unknown source'            => ['nonsense', 'queued'],
        ];
    }

    public function testEveryTerminalStatusHasNoOutgoingSendTransitions(): void
    {
        foreach (['completed', 'cancelled'] as $terminal) {
            self::assertSame(
                [],
                CampaignService::TRANSITIONS[$terminal],
                "{$terminal} must be terminal."
            );
        }
    }

    public function testTransitionTableOnlyReferencesKnownStatuses(): void
    {
        foreach (CampaignService::TRANSITIONS as $from => $targets) {
            self::assertContains($from, CampaignService::STATUSES);
            foreach ($targets as $to) {
                self::assertContains($to, CampaignService::STATUSES);
            }
        }
    }

    public function testNoTransitionIsItsOwnSource(): void
    {
        foreach (CampaignService::TRANSITIONS as $from => $targets) {
            self::assertNotContains($from, $targets, "{$from} should not transition to itself.");
        }
    }
}
