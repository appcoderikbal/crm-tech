<?php

declare(strict_types=1);

namespace TechzappCrm\Services\Mail;

use TechzappCrm\Core\Config;

if (!defined('ABSPATH')) {
    exit;
}

/** Builds (and, in tests, overrides) the active transport. */
final class Mailer
{
    private static ?MailTransport $override = null;

    public static function transport(): MailTransport
    {
        if (self::$override instanceof MailTransport) {
            return self::$override;
        }

        return match (Config::mailTransport()) {
            'log'   => new LogTransport(),
            default => new SesTransport(),
        };
    }

    /** Test seam: inject a fake transport. */
    public static function setTransport(?MailTransport $transport): void
    {
        self::$override = $transport;
    }
}
