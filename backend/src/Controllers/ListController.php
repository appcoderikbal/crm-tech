<?php

declare(strict_types=1);

namespace Techzapp\Crm\Controllers;

use Techzapp\Crm\Core\Audit;
use Techzapp\Crm\Core\Auth;
use Techzapp\Crm\Http\ApiException;
use Techzapp\Crm\Http\Request;
use Techzapp\Crm\Http\Response;
use Techzapp\Crm\Http\Validator;
use Techzapp\Crm\Services\ContactService;
use Techzapp\Crm\Services\ListService;

final class ListController
{
    public function index(Request $request): Response
    {
        Auth::requireUser();

        $lists = ListService::all((string) $request->query('search', ''));

        return Response::json($lists, 200, [
            'total'      => count($lists),
            'unassigned' => ContactService::counts()['unassigned'],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        Auth::requireUser();

        $list = ListService::find((int) $params['id']);
        if ($list === null) {
            throw ApiException::notFound('List not found.');
        }

        return Response::json([
            'list'             => $list,
            'campaign_history' => ListService::campaignHistory((int) $params['id']),
        ]);
    }

    public function members(Request $request, array $params): Response
    {
        Auth::requireUser();

        $listId = (int) $params['id'];
        if (ListService::find($listId) === null) {
            throw ApiException::notFound('List not found.');
        }

        $result = ContactService::paginate([
            'assignment' => 'assigned',
            'list_id'    => $listId,
            'search'     => (string) $request->query('search', ''),
            'suppressed' => $request->query('suppressed'),
            'sort'       => (string) $request->query('sort', 'created_at'),
            'direction'  => (string) $request->query('direction', 'desc'),
            'page'       => (int) $request->query('page', 1),
            'per_page'   => (int) $request->query('per_page', 25),
        ]);

        return Response::json($result['items'], 200, [
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
            'pages'    => $result['pages'],
            'list'     => ListService::find($listId),
        ]);
    }

    public function store(Request $request): Response
    {
        $user = Auth::requireWriteAccess();

        $validator = Validator::make($request->body());
        $name = $validator->string('name', true, 190, 1);
        $description = $validator->string('description', false, 1000, 0, '');
        $validator->validate();

        $id = ListService::create((string) $name, (string) $description, (int) $user['id']);
        Audit::log('list.created', 'list', $id, ['name' => $name], (int) $user['id']);

        return Response::created(ListService::find($id));
    }

    public function update(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        $validator = Validator::make($request->body());
        $name = $validator->string('name', true, 190, 1);
        $description = $validator->has('description') ? $validator->string('description', false, 1000, 0, '') : null;
        $validator->validate();

        ListService::rename($id, (string) $name, $description);
        Audit::log('list.renamed', 'list', $id, ['name' => $name], (int) $user['id']);

        return Response::json(ListService::find($id));
    }

    /**
     * Deleting a list releases its contacts; it never deletes them. The client
     * must pass confirm=true, which is what the UI's confirmation dialog sends.
     */
    public function destroy(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $id = (int) $params['id'];

        $confirmed = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOL);
        $result = ListService::delete($id, $confirmed);

        Audit::log('list.deleted', 'list', $id, $result, (int) $user['id']);

        return Response::json([
            'deleted'            => true,
            'contacts_released'  => $result['released'],
            'contacts_deleted'   => 0,
        ]);
    }

    public function assign(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $listId = (int) $params['id'];

        $validator = Validator::make($request->body());
        $ids = $validator->intList('contact_ids', false, 10000);
        $allMatching = $validator->bool('all_matching_filter', false);
        $validator->validate();

        if ($allMatching) {
            $filters = is_array($request->input('filters')) ? $request->input('filters') : [];
            $result = ListService::assignByFilter($listId, $filters, (int) $user['id']);
        } else {
            if ($ids === null || $ids === []) {
                throw ApiException::validation(['contact_ids' => 'Select at least one contact.']);
            }
            $result = ListService::assign($listId, $ids, (int) $user['id']);
        }

        Audit::log('list.contacts_assigned', 'list', $listId, $result, (int) $user['id']);

        return Response::json($result + ['list' => ListService::find($listId)]);
    }

    public function remove(Request $request, array $params): Response
    {
        $user = Auth::requireWriteAccess();
        $listId = (int) $params['id'];

        $validator = Validator::make($request->body());
        $ids = $validator->intList('contact_ids', false, 10000);
        $removeAll = $validator->bool('all', false);
        $validator->validate();

        if (!$removeAll && ($ids === null || $ids === [])) {
            throw ApiException::validation(['contact_ids' => 'Select at least one contact.']);
        }

        $removed = ListService::removeContacts($listId, $removeAll ? [] : ($ids ?? []));
        Audit::log('list.contacts_removed', 'list', $listId, ['removed' => $removed], (int) $user['id']);

        return Response::json([
            'removed' => $removed,
            'list'    => ListService::find($listId),
            'note'    => 'Removed contacts are back in the unassigned Contacts list.',
        ]);
    }
}
