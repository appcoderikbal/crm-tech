<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Core\Database;
use Techzapp\Crm\Core\Settings;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Validator;
use Techzapp\Crm\Services\CampaignRenderer;
use Techzapp\Crm\Services\CampaignService;
use Techzapp\Crm\Services\Mail\Mailer;
use Techzapp\Crm\Services\ReportService;
use Techzapp\Crm\Support\EmailAddress;

final class CampaignController
{
    public function index(Request $request): Response
    {
        Auth::requireUser();

        $status = $request->query('status');
        $result = CampaignService::paginate([
            'status'   => is_string($status) && $status !== '' ? explode(',', $status) : null,
            'list_id'  => $request->query('list_id'),
            'search'   => (string) $request->query('search', ''),
            'from'     => $request->query('from'),
            'to'       => $request->query('to'),
            'page'     => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 25),
        ]);

        return Response::json($result['items'], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        Auth::requireUser();

        $campaign = CampaignService::findOrFail((int) $params['id']);

        return Response::json([
            'campaign'  => $campaign,
            'readiness' => CampaignService::readiness($campaign),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $data = $this->validatePayload($request, true);
        $id = CampaignService::create($data, (int) $user['id']);

        Audit::log('campaign.created', 'campaign', $id, ['name' => $data['name']], (int) $user['id']);

        return Response::created(CampaignService::findOrFail($id));
    }

    public function update(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        $data = $this->validatePayload($request, false);
        CampaignService::update($id, $data);

        Audit::log('campaign.updated', 'campaign', $id, array_keys($data), (int) $user['id']);

        $campaign = CampaignService::findOrFail($id);
        return Response::json(['campaign' => $campaign, 'readiness' => CampaignService::readiness($campaign)]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        CampaignService::delete($id);
        Audit::log('campaign.deleted', 'campaign', $id, [], (int) $user['id']);

        return Response::json(['deleted' => true]);
    }

    public function duplicate(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $newId = CampaignService::duplicate((int) $params['id'], (int) $user['id']);

        return Response::created(CampaignService::findOrFail($newId));
    }

    /** Desktop/mobile preview with sample or real contact data. */
    public function preview(Request $request, array $params): Response
    {
        Auth::requireUser();

        $campaign = CampaignService::findOrFail((int) $params['id']);

        $contact = null;
        $contactId = $request->query('contact_id');
        if ($contactId !== null && ctype_digit((string) $contactId)) {
            $contact = Database::first(
                'SELECT email, first_name, last_name, company_name, website, country, custom_fields_json
                   FROM contacts WHERE id = :id',
                ['id' => (int) $contactId]
            );
        }

        $preview = CampaignRenderer::renderPreview($campaign, $contact);

        return Response::json([
            'subject'           => $preview['subject'],
            'html'              => $preview['html'],
            'text'              => $preview['text'],
            'missing_variables' => $preview['missing'],
            'sample_contact'    => $contact === null ? 'sample data' : $contact['email'],
            'readiness'         => CampaignService::readiness($campaign),
        ]);
    }

    /** Sends a test message to an authorised address. */
    public function test(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $campaign = CampaignService::findOrFail((int) $params['id']);

        $validator = Validator::make($request->body());
        $to = $validator->email('email', true);
        $validator->validate();

        $this->assertTestRecipientAllowed((string) $to, (string) $user['email']);

        if (!Settings::sendingAllowed()) {
            throw ApiException::conflict('Sending is disabled by the global kill switch.');
        }

        $transport = Mailer::transport();
        if (!$transport->isIdentityUsable((string) $campaign['from_email'])) {
            throw ApiException::unprocessable('The From address is not a verified SES identity.');
        }

        // A test send uses a synthetic recipient row so tracking links resolve,
        // but it is never written to campaign_recipients and never counted.
        $template = CampaignRenderer::buildTemplate($campaign, false);
        $preview = CampaignRenderer::renderPreview($campaign, null);

        $message = new \Techzapp\Crm\Services\Mail\Message(
            toEmail: (string) $to,
            fromEmail: (string) $campaign['from_email'],
            fromName: (string) $campaign['from_name'],
            replyTo: (string) ($campaign['reply_to'] ?? ''),
            subject: '[TEST] ' . $preview['subject'],
            htmlBody: $preview['html'],
            textBody: $preview['text'],
            headers: ['X-Techzapp-Test' => 'true'],
            tags: ['campaign_id' => (string) $campaign['id'], 'test' => 'true'],
        );

        $result = $transport->send($message);

        Audit::log('campaign.test_sent', 'campaign', (int) $campaign['id'], [
            'to'       => $to,
            'accepted' => $result->accepted,
            'error'    => $result->errorCode,
        ], (int) $user['id']);

        if (!$result->accepted) {
            throw ApiException::unprocessable('The test message was not accepted: ' . $result->errorMessage, [
                'code' => $result->errorCode,
            ]);
        }

        return Response::json([
            'sent'              => true,
            'to'                => $to,
            'message_id'        => $result->messageId,
            'missing_variables' => $preview['missing'],
            'tracked_links'     => count($template['links']),
        ]);
    }

    public function schedule(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];
        $campaign = CampaignService::findOrFail($id);

        $validator = Validator::make($request->body());
        $timezone = $validator->timezone('timezone', false, (string) ($campaign['timezone'] ?? 'UTC'));
        $sendRate = $validator->int('send_rate_per_minute', false, 1, 10000);
        $confirm = $validator->bool('confirm', false);
        $scheduledAtUtc = $validator->dateTimeToUtc('scheduled_at', $timezone, true);
        $validator->validate();

        if (!$confirm) {
            throw ApiException::conflict('Scheduling must be explicitly confirmed.', [
                'requires_confirmation' => true,
                'readiness'             => CampaignService::readiness($campaign),
            ]);
        }

        $updated = CampaignService::schedule($id, (string) $scheduledAtUtc, $timezone, $sendRate, (int) $user['id']);

        return Response::json(['campaign' => $updated, 'readiness' => CampaignService::readiness($updated)]);
    }

    public function sendNow(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        if (!filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOL)) {
            throw ApiException::conflict('Starting a campaign must be explicitly confirmed.', [
                'requires_confirmation' => true,
                'readiness'             => CampaignService::readiness(CampaignService::findOrFail($id)),
            ]);
        }

        $campaign = CampaignService::sendNow($id, (int) $user['id']);

        return Response::json([
            'campaign' => $campaign,
            'note'     => 'The campaign is queued. The cron worker will start sending on its next run.',
        ]);
    }

    public function cancel(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        return Response::json(['campaign' => CampaignService::cancel((int) $params['id'], (int) $user['id'])]);
    }

    public function pause(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        return Response::json(['campaign' => CampaignService::pause((int) $params['id'], (int) $user['id'])]);
    }

    public function resume(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        return Response::json(['campaign' => CampaignService::resume((int) $params['id'], (int) $user['id'])]);
    }

    public function report(Request $request, array $params): Response
    {
        Auth::requireUser();
        return Response::json(ReportService::campaignReport((int) $params['id']));
    }

    public function recipients(Request $request, array $params): Response
    {
        Auth::requireUser();

        $result = ReportService::campaignRecipients((int) $params['id'], [
            'status'     => $request->query('status'),
            'search'     => (string) $request->query('search', ''),
            'engagement' => (string) $request->query('engagement', ''),
            'page'       => (int) $request->query('page', 1),
            'per_page'   => (int) $request->query('per_page', 50),
        ]);

        return Response::json($result['items'], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
        ]);
    }

    /** Test sends are restricted so the app cannot be used to mail arbitrary people. */
    private function assertTestRecipientAllowed(string $to, string $userEmail): void
    {
        if (EmailAddress::normalize($to) === EmailAddress::normalize($userEmail)) {
            return;
        }

        $allowedDomains = array_map('strtolower', Settings::json('test_send_allowed_domains', []));
        $domain = EmailAddress::domain($to);

        $userExists = Database::scalar(
            'SELECT 1 FROM users WHERE normalized_email = :email AND is_active = 1',
            ['email' => EmailAddress::normalize($to)]
        );
        if ($userExists !== null) {
            return;
        }

        foreach ($allowedDomains as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return;
            }
        }

        throw ApiException::forbidden(
            'Test emails may only be sent to your own address, another CRM user, or a domain listed in '
            . 'Settings → test send allowed domains.'
        );
    }

    /** @return array<string,mixed> */
    private function validatePayload(Request $request, bool $creating): array
    {
        $validator = Validator::make($request->body());
        $data = [];

        $name = $validator->string('name', $creating, 190, 1);
        if ($name !== null) {
            $data['name'] = $name;
        }

        if ($validator->has('list_id')) {
            $listId = $validator->int('list_id', false, 1);
            if ($listId !== null && Database::scalar('SELECT id FROM lists WHERE id = :id', ['id' => $listId]) === null) {
                $validator->addError('list_id', 'The selected list no longer exists.');
            }
            $data['list_id'] = $listId;
        }

        foreach (['from_name' => 120, 'subject' => 500, 'preview_text' => 500] as $field => $max) {
            if ($validator->has($field)) {
                $data[$field] = $validator->string($field, false, $max, 0, '');
            }
        }
        foreach (['from_email', 'reply_to'] as $field) {
            if ($validator->has($field) && trim((string) $request->input($field, '')) !== '') {
                $data[$field] = $validator->email($field, false);
            } elseif ($validator->has($field)) {
                $data[$field] = '';
            }
        }
        foreach (['html_body', 'text_body'] as $field) {
            if ($validator->has($field)) {
                $data[$field] = $validator->text($field, false, 2_000_000);
            }
        }
        if ($validator->has('timezone')) {
            $data['timezone'] = $validator->timezone('timezone');
        }
        if ($validator->has('send_rate_per_minute')) {
            $data['send_rate_per_minute'] = $validator->int('send_rate_per_minute', false, 1, 10000);
        }
        foreach (['track_opens', 'track_clicks'] as $field) {
            if ($validator->has($field)) {
                $data[$field] = $validator->bool($field, true);
            }
        }

        if ($creating) {
            $data['track_opens'] = $data['track_opens'] ?? true;
            $data['track_clicks'] = $data['track_clicks'] ?? true;
        }

        $validator->validate();
        return $data;
    }
}
