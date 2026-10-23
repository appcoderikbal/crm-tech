<?php

declare(strict_types=1);

/**
 * Proves a stripped vendor/ can still send.
 *
 *     php bin/verify-vendor.php <plugin-dir>
 *
 * The previous check called class_exists() on four classes and passed happily
 * on a bundle that could not send at all: Aws\Identity\AwsCredentialIdentity
 * had been stripped, and nothing references it until a request is actually
 * signed. The build shipped, the plugin installed, and the first real send
 * failed with "Class not found".
 *
 * So this does not ask whether classes exist. It drives the same calls the
 * plugin makes, with deliberately invalid credentials pointed at an
 * unroutable endpoint, and inspects how they fail. An authentication or
 * connection failure means every class along that path was loadable, which is
 * the entire question. A PHP Error - a missing class, a missing method - is a
 * broken bundle and fails the build.
 */

$pluginDir = $argv[1] ?? dirname(__DIR__);
$autoload = $pluginDir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "    MISSING: {$autoload}\n");
    exit(1);
}

require $autoload;

$failures = [];

/**
 * Runs one probe. A PHP Error means the bundle is incomplete; anything the
 * library throws deliberately means the code path loaded.
 */
function probe(string $what, callable $fn, array &$failures): void
{
    try {
        $fn();
        printf("    %-34s ok\n", $what);
    } catch (\Error $e) {
        // Class not found, method not found, type error in library code.
        $failures[] = $what . ': ' . $e->getMessage();
        printf("    %-34s BROKEN BUNDLE: %s\n", $what, $e->getMessage());
    } catch (\Throwable $e) {
        // An exception means the library ran and objected to our input,
        // which is exactly what we want from fake credentials.
        printf("    %-34s ok (%s)\n", $what, (new ReflectionClass($e))->getShortName());
    }
}

echo "==> Exercising the send path\n";

probe('SesV2 client construction', static function (): void {
    new Aws\SesV2\SesV2Client([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'AKIAEXAMPLE', 'secret' => 'secret'],
    ]);
}, $failures);

// The real test: build, sign and dispatch a request. Signing is where the
// credential-identity classes are actually touched.
probe('SesV2 sendEmail (signed request)', static function (): void {
    $client = new Aws\SesV2\SesV2Client([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'AKIAEXAMPLE', 'secret' => 'secret'],
        'endpoint'    => 'https://localhost:1',   // refuse fast, send nothing
        'retries'     => 0,
        'http'        => ['connect_timeout' => 1, 'timeout' => 2],
    ]);

    $client->sendEmail([
        'FromEmailAddress' => 'from@example.com',
        'Destination'      => ['ToAddresses' => ['to@example.com']],
        'Content'          => [
            'Simple' => [
                'Subject' => ['Data' => 'probe'],
                'Body'    => ['Text' => ['Data' => 'probe']],
            ],
        ],
    ]);
}, $failures);

probe('SesV2 getAccount (signed request)', static function (): void {
    $client = new Aws\SesV2\SesV2Client([
        'version'     => 'latest',
        'region'      => 'us-east-1',
        'credentials' => ['key' => 'AKIAEXAMPLE', 'secret' => 'secret'],
        'endpoint'    => 'https://localhost:1',
        'retries'     => 0,
        'http'        => ['connect_timeout' => 1, 'timeout' => 2],
    ]);

    $client->getAccount();
}, $failures);

probe('SNS message validator', static function (): void {
    $validator = new Aws\Sns\MessageValidator();
    $message = new Aws\Sns\Message([
        'Message'          => 'x',
        'MessageId'        => 'x',
        'Timestamp'        => 'x',
        'TopicArn'         => 'arn:aws:sns:us-east-1:1:x',
        'Type'             => 'Notification',
        'Signature'        => 'x',
        'SignatureVersion' => '1',
        'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/x.pem',
    ]);
    $validator->validate($message);
}, $failures);

probe('HTMLPurifier sanitising', static function (): void {
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null);
    $purifier = new HTMLPurifier($config);

    $clean = $purifier->purify('<p onclick="x()">hello<script>bad()</script></p>');

    if (str_contains($clean, 'script') || str_contains($clean, 'onclick')) {
        throw new \Error('HTMLPurifier did not strip dangerous markup');
    }
}, $failures);

echo "\n";

if ($failures !== []) {
    fwrite(STDERR, "    " . count($failures) . " probe(s) failed - the bundle is incomplete:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "      - {$failure}\n");
    }
    exit(1);
}

echo "    send path is intact\n";
