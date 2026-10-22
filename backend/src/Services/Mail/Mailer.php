<?php

declare(strict_types=1);

namespace Techzapp\Crm\Services\Mail;

use Techzapp\Crm\Core\Config;

/** Builds (and, in tests, overrides) the active transport. */
final class Mailer
{
    private static ?MailTransport $override = null;

    public static function transport(): MailTransport
    {
        if (self::$override instanceof MailTransport) {
            return self::$override;
        }

        $config = Config::load();
        return match ($config->string('ses.transport', 'ses')) {
            'log'   => new LogTransport($config),
            default => new SesTransport($config),
        };
    }

    /** Test seam: inject a fake transport. */
    public static function setTransport(?MailTransport $transport): void
    {
        self::$override = $transport;
    }
}
