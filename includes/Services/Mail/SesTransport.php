<?php

declare(strict_types=1);

namespace TechzappCrm\Services\Mail;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\SesV2\SesV2Client;
use TechzappCrm\Core\Config;
use TechzappCrm\Core\Settings;
use TechzappCrm\Core\Logger;
use TechzappCrm\Support\EmailAddress;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Amazon SES v2 transport.
 *
 * Credentials come from the protected config file or the default AWS chain —
 * never from the database, the frontend bundle or a public file.
 */
final class SesTransport implements MailTransport
{
    private ?SesV2Client $client = null;

    /** @var array{status:array<string,mixed>|null, at:int}|null */
    private ?array $cachedAccount = null;


    public function name(): string
    {
        return 'ses';
    }

    private function client(): SesV2Client
    {
        if ($this->client instanceof SesV2Client) {
            return $this->client;
        }

        $args = [
            'version' => '2019-09-27',
            'region'  => Config::awsRegion(),
            'http'    => [
                'timeout'         => 15,
                'connect_timeout' => 5,
            ],
            // Retries are handled by the sending pipeline, which records each
            // attempt; the SDK retrying silently would hide that accounting.
            'retries' => 0,
        ];

        $key = Config::awsAccessKeyId();
        $secret = Config::awsSecretAccessKey();
        if ($key !== '' && $secret !== '') {
            $args['credentials'] = ['key' => $key, 'secret' => $secret];
        }

        $this->client = new SesV2Client($args);
        return $this->client;
    }

    public function send(Message $message): SendResult
    {
        if (!EmailAddress::isValid($message->toEmail)) {
            return SendResult::permanentFailure('InvalidRecipient', 'Recipient address is not valid.');
        }
        if (!EmailAddress::isSafeForHeader($message->fromEmail) || !EmailAddress::isSafeForHeader($message->replyTo)) {
            return SendResult::permanentFailure('InvalidSender', 'Sender address contains illegal characters.');
        }

        $content = [
            'Subject' => ['Data' => $message->safeSubject(), 'Charset' => 'UTF-8'],
            'Body'    => [],
        ];
        if ($message->textBody !== '') {
            $content['Body']['Text'] = ['Data' => $message->textBody, 'Charset' => 'UTF-8'];
        }
        if ($message->htmlBody !== '') {
            $content['Body']['Html'] = ['Data' => $message->htmlBody, 'Charset' => 'UTF-8'];
        }
        if ($content['Body'] === []) {
            return SendResult::permanentFailure('EmptyBody', 'Message has no content.');
        }

        $headers = [];
        foreach ($message->safeHeaders() as $name => $value) {
            $headers[] = ['Name' => $name, 'Value' => $value];
        }
        if ($headers !== []) {
            $content['Headers'] = $headers;
        }

        $params = [
            'FromEmailAddress' => $message->fromHeader(),
            'Destination'      => ['ToAddresses' => [$message->toEmail]],
            'Content'          => ['Simple' => $content],
        ];

        if ($message->replyTo !== '') {
            $params['ReplyToAddresses'] = [$message->replyTo];
        }

        $tags = [];
        foreach ($message->safeTags() as $name => $value) {
            $tags[] = ['Name' => $name, 'Value' => $value];
        }
        if ($tags !== []) {
            $params['EmailTags'] = $tags;
        }

        $configurationSet = $message->configurationSet !== ''
            ? $message->configurationSet
            : Settings::string('ses_configuration_set');
        if ($configurationSet !== '') {
            $params['ConfigurationSetName'] = $configurationSet;
        }

        try {
            $result = $this->client()->sendEmail($params);
            $messageId = (string) ($result['MessageId'] ?? '');

            return $messageId === ''
                ? SendResult::uncertain('NoMessageId', 'SES returned no message id.')
                : SendResult::accepted($messageId);
        } catch (CredentialsException $e) {
            return SendResult::accountProblem('CredentialsError', $this->trim($e->getMessage()));
        } catch (AwsException $e) {
            return $this->classifyAwsException($e);
        } catch (Throwable $e) {
            Logger::exception('ses.send_unexpected', $e);
            // Unknown client-side failure: we cannot prove the request did not
            // reach SES, so this is uncertain rather than retryable.
            return SendResult::uncertain('TransportError', $this->trim($e->getMessage()));
        }
    }

    private function classifyAwsException(AwsException $e): SendResult
    {
        $code = (string) ($e->getAwsErrorCode() ?? 'AwsError');
        $message = $this->trim((string) ($e->getAwsErrorMessage() ?? $e->getMessage()));
        $status = $e->getStatusCode();

        // Account-level stops: continuing would make things worse.
        if (in_array($code, ['AccountSuspendedException', 'SendingPausedException'], true)) {
            return SendResult::accountProblem($code, $message);
        }
        if (in_array($code, ['NotFoundException', 'MailFromDomainNotVerifiedException', 'BadRequestException'], true)) {
            return SendResult::accountProblem($code, $message);
        }

        // Throttling and 5xx are safe to retry: SES did not accept the message.
        if (in_array($code, ['TooManyRequestsException', 'Throttling', 'ThrottlingException', 'SendingQuotaExceededException'], true)) {
            return SendResult::transientFailure($code, $message);
        }
        if ($status !== null && $status >= 500) {
            return SendResult::transientFailure($code, $message);
        }

        // Per-message rejections: retrying the same payload cannot succeed.
        if (in_array($code, ['MessageRejected', 'InvalidParameterValue', 'ValidationException', 'LimitExceededException'], true)) {
            return SendResult::permanentFailure($code, $message);
        }

        if ($e->getPrevious() instanceof \GuzzleHttp\Exception\ConnectException) {
            return SendResult::uncertain('ConnectionFailed', $message);
        }
        if ($status !== null && $status >= 400 && $status < 500) {
            return SendResult::permanentFailure($code, $message);
        }

        return SendResult::uncertain($code, $message);
    }

    public function accountStatus(): ?array
    {
        // GetAccount is itself rate-limited; one call per minute is plenty.
        if ($this->cachedAccount !== null && (time() - $this->cachedAccount['at']) < 60) {
            return $this->cachedAccount['status'];
        }

        try {
            $result = $this->client()->getAccount();
            $quota = $result['SendQuota'] ?? [];

            $status = [
                'sending_enabled'    => (bool) ($result['SendingEnabled'] ?? false),
                'production_access'  => (bool) ($result['ProductionAccessEnabled'] ?? false),
                'enforcement_status' => (string) ($result['EnforcementStatus'] ?? 'UNKNOWN'),
                'max_24_hour'        => (float) ($quota['Max24HourSend'] ?? 0),
                'sent_last_24_hours' => (float) ($quota['SentLast24Hours'] ?? 0),
                'max_send_rate'      => (float) ($quota['MaxSendRate'] ?? 0),
            ];
        } catch (Throwable $e) {
            Logger::warning('ses.account_status_failed', ['error' => $this->trim($e->getMessage())]);
            $status = null;
        }

        $this->cachedAccount = ['status' => $status, 'at' => time()];
        return $status;
    }

    /**
     * Checks the From address against the configured allow-list of verified
     * identities (address or parent domain). The list is authoritative; a
     * typo'd sender is rejected before any SES call is made.
     */
    public function isIdentityUsable(string $email): bool
    {
        $identities = array_map('strtolower', array_map('strval', Settings::array('ses_verified_identities')));

        // No list configured means no sender is approved. Failing closed is
        // deliberate: an empty allow-list is an unfinished setup, not
        // permission to mail from anything.
        if ($identities === []) {
            return false;
        }

        $normalized = EmailAddress::normalize($email);
        if (in_array($normalized, $identities, true)) {
            return true;
        }

        $domain = EmailAddress::domain($normalized);
        foreach ($identities as $identity) {
            if ($identity === $domain || str_ends_with($domain, '.' . $identity)) {
                return true;
            }
        }

        return false;
    }

    private function trim(string $message): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 480);
    }
}
