<?php

namespace App\Http\Controllers;

use App\Enums\FileAbility;
use App\Enums\ShareAbility;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\ShareLink;
use App\Services\Access\AccessResolver;
use App\Services\Files\SharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Share links, and revoking the access an item already carries.
 *
 * Sharing something on requires `FileAbility::Share` on the item itself, not
 * merely the `files.share` module permission: the module permission says this
 * user may share *things they can share*, and the resolver says which.
 *
 * **The drawer grants links and nothing else.** Naming a person, a division, a
 * section or a role happens where somebody is actually thinking about who
 * something is for — when the folder is created, or when a document is uploaded
 * — and the tags are shown there as they are made. A second place to do it,
 * opened later from a panel, is how an item ends up shared with people nobody
 * remembers choosing. What stays here is the other half: seeing who already
 * holds access, and taking it away.
 *
 * `store()` is gone with that, and so is the directory this endpoint used to
 * ship with every open — every account, division, section and role, for a
 * picker that no longer exists.
 */
class ShareController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly SharingService $sharing,
    ) {}

    /**
     * Who an item is shared with, and who it could be shared with.
     *
     * A JSON endpoint loaded when the share drawer opens, rather than props on
     * every file-manager page load. The browse screen is the page people sit
     * on, and shipping the whole directory — every account, division, section
     * and role — with each folder navigation is a cost paid constantly for a
     * panel that is opened rarely.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['file', 'folder'])],
            'public_id' => ['required', 'string', 'size:26'],
        ]);

        $item = $this->item($validated['kind'], $validated['public_id']);
        $this->assertCanShare($request, $item);

        return response()->json([
            'shares' => $item->shares()->with(['user:id,name', 'division:id,name', 'section:id,name'])->get()
                ->map(fn (ItemShare $share) => [
                    'id' => $share->id,
                    'grantee_type' => $share->grantee_type->value,
                    'grantee_type_label' => $share->grantee_type->label(),
                    'grantee' => $share->granteeLabel(),
                    'ability' => $share->ability->value,
                    'ability_label' => $share->ability->label(),
                    'granted_by' => $share->granted_by_name,
                    'expires_at' => $share->expires_at,
                    'active' => $share->isActive(),
                ])->values(),
            'links' => $item->shareLinks()->whereNull('revoked_at')->get()
                ->map(fn (ShareLink $link) => [
                    'id' => $link->id,
                    'url' => $link->url(),
                    'ability' => $link->ability->value,
                    'ability_label' => $link->ability->label(),
                    'expires_at' => $link->expires_at,
                    'max_views' => $link->max_views,
                    'view_count' => $link->view_count,
                    'usable' => $link->isUsable(),
                ])->values(),
            // A confidential item can never be linked, so the panel must not
            // offer a control that would be refused.
            'linkable' => $this->access->effectiveVisibility($item)->linkable(),
            'abilities' => array_map(fn (ShareAbility $ability) => [
                'value' => $ability->value,
                'label' => $ability->label(),
                'description' => $ability->description(),
                'linkable' => in_array($ability, ShareAbility::linkable(), true),
            ], ShareAbility::cases()),
        ]);
    }

    public function destroy(Request $request, ItemShare $share): RedirectResponse
    {
        $this->assertCanShare($request, $share->shareable);

        $this->sharing->revoke($share, $request->user());

        return back()->with('success', 'Access revoked.');
    }

    public function storeLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['file', 'folder'])],
            'public_id' => ['required', 'string', 'size:26'],
            // Only the two a link may ever carry; `manage` is not offered,
            // and SharingService refuses it again.
            'ability' => ['required', Rule::in(['view', 'download'])],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $item = $this->item($validated['kind'], $validated['public_id']);
        $this->assertCanShare($request, $item);

        // A confidential item cannot be linked. SharingService throws, and this
        // turns that into the flash the shell already knows how to show rather
        // than a 500.
        if (! $this->access->effectiveVisibility($item)->linkable()) {
            return back()->with('error', 'A confidential item cannot be shared through a link.');
        }

        $link = $this->sharing->createLink(
            $item,
            ShareAbility::from($validated['ability']),
            $request->user(),
            $validated['expires_at'] ? new \DateTimeImmutable($validated['expires_at']) : null,
            $validated['max_views'] ?? null,
        );

        return back()->with('success', 'Share link created: '.$link->url());
    }

    public function destroyLink(Request $request, ShareLink $link): RedirectResponse
    {
        $this->assertCanShare($request, $link->shareable);

        $this->sharing->revokeLink($link, $request->user());

        return back()->with('success', 'Link revoked.');
    }

    private function assertCanShare(Request $request, File|Folder|null $item): void
    {
        if (! $item || ! $this->access->allows($request->user(), $item, FileAbility::Share)) {
            throw ValidationException::withMessages(['item' => 'You cannot share that.']);
        }
    }

    private function item(string $kind, string $publicId): File|Folder
    {
        return $kind === 'file'
            ? File::query()->where('public_id', $publicId)->firstOrFail()
            : Folder::query()->where('public_id', $publicId)->firstOrFail();
    }
}
