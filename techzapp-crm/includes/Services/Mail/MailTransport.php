<?php

declare(strict_types=1);

namespace TechzappCrm\Services\Mail;

if (!defined('ABSPATH')) {
    exit;
}

interface MailTransport
{
    public function send(Message $message): SendResult;

    /**
     * Account status and quota, or null when the provider cannot be reached.
     *
     * @return array{
     *     sending_enabled:bool,
     *     production_access:bool,
     *     enforcement_status:string,
     *     max_24_hour:float,
     *     sent_last_24_hours:float,
     *     max_send_rate:float
     * }|null
     */
    public function accountStatus(): ?array;

    /** True when the identity (address or domain) can be used as a From address. */
    public function isIdentityUsable(string $email): bool;

    public function name(): string;
}
