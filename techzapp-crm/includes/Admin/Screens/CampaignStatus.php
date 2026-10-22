<?php

declare(strict_types=1);

namespace TechzappCrm\Admin\Screens;

use TechzappCrm\Admin\View;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared labels and colours for campaign and recipient statuses. */
final class CampaignStatus
{
    /** @return array{0:string,1:string} label, tone */
    public static function describe(string $status): array
    {
        return match ($status) {
            'draft'     => [__('Draft', 'techzapp-crm'), 'neutral'],
            'scheduled' => [__('Scheduled', 'techzapp-crm'), 'brand'],
            'queued'    => [__('Queued', 'techzapp-crm'), 'brand'],
            'sending'   => [__('Sending', 'techzapp-crm'), 'brand'],
            'paused'    => [__('Paused', 'techzapp-crm'), 'warn'],
            'completed' => [__('Completed', 'techzapp-crm'), 'ok'],
            'failed'    => [__('Failed', 'techzapp-crm'), 'danger'],
            'cancelled' => [__('Cancelled', 'techzapp-crm'), 'outline'],
            default     => [$status, 'neutral'],
        };
    }

    public static function badge(string $status): string
    {
        [$label, $tone] = self::describe($status);
        return View::badge($label, $tone);
    }

    /** @return array{0:string,1:string} label, tone */
    public static function describeRecipient(string $status): array
    {
        return match ($status) {
            'pending'              => [__('Pending', 'techzapp-crm'), 'neutral'],
            'sending'              => [__('Sending', 'techzapp-crm'), 'brand'],
            'accepted'             => [__('Accepted by SES', 'techzapp-crm'), 'brand'],
            'delivered'            => [__('Delivered', 'techzapp-crm'), 'ok'],
            'bounced'              => [__('Bounced', 'techzapp-crm'), 'danger'],
            'complained'           => [__('Complained', 'techzapp-crm'), 'danger'],
            'failed'               => [__('Failed', 'techzapp-crm'), 'danger'],
            'suppressed'           => [__('Suppressed', 'techzapp-crm'), 'warn'],
            'cancelled'            => [__('Cancelled', 'techzapp-crm'), 'outline'],
            'needs_reconciliation' => [__('Needs reconciliation', 'techzapp-crm'), 'warn'],
            default                => [$status, 'neutral'],
        };
    }

    public static function recipientBadge(string $status): string
    {
        [$label, $tone] = self::describeRecipient($status);
        return View::badge($label, $tone);
    }

    /** @return array<string,string> status => label, for filter dropdowns */
    public static function options(): array
    {
        $options = [];
        foreach (['draft', 'scheduled', 'queued', 'sending', 'paused', 'completed', 'failed', 'cancelled'] as $status) {
            $options[$status] = self::describe($status)[0];
        }
        return $options;
    }

    /** @return array<string,string> */
    public static function recipientOptions(): array
    {
        $options = [];
        foreach (['pending', 'accepted', 'delivered', 'bounced', 'complained', 'failed', 'suppressed', 'cancelled', 'needs_reconciliation'] as $status) {
            $options[$status] = self::describeRecipient($status)[0];
        }
        return $options;
    }
}
