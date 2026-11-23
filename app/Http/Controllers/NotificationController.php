<?php

namespace App\Http\Controllers;

use App\Enums\NotificationKind;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * The payload behind the header bell, shared on every page by
     * HandleInertiaRequests. Kept here rather than in the middleware so the
     * shape of a notification row is defined in one place.
     *
     * **The wording, the symbol and the colour are resolved here, from the
     * stored `kind`.** The row holds a slug; `NotificationKind` holds what it
     * looks like — the same division `DocumentAction` makes, and for the same
     * reason: a correction to an icon must not mean rewriting history.
     *
     * Rows written before `kind` existed simply have none. They keep whatever
     * `icon` they stored and fall back to a bell, rather than being guessed at
     * from the sentence they carry.
     *
     * @return array{items: list<array<string, mixed>>, unread: int}
     */
    public static function feedFor(User $user, int $limit = 8): array
    {
        $items = $user->notifications()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($notification): array {
                $data = $notification->data;
                $kind = NotificationKind::tryFrom($data['kind'] ?? '') ?? self::legacyKind($data);
                $actor = self::actor($data);

                return [
                    'id' => $notification->id,
                    'message' => $data['message'] ?? 'Notification',
                    'url' => $data['url'] ?? null,
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at->diffForHumans(),
                    'kind' => $kind?->value,
                    'kind_label' => $kind?->label(),
                    'icon' => $kind?->icon() ?? $data['icon'] ?? 'fa-solid fa-bell',
                    'tone' => $kind?->tone() ?? 'bg-slate-100 text-slate-500',
                    /*
                     * The face, exactly as the trail renders one: the two
                     * values `resources/js/avatar.js` needs, snapshotted onto
                     * the notification when it was sent. A row with no actor —
                     * every row written before this existed — renders the kind
                     * badge alone, which is honest about knowing nothing
                     * rather than showing initials derived from a sentence.
                     */
                    'actor' => $actor,
                    // The name beside the face, which is the thing a reader
                    // actually recognises: in a list of nine, "who" is the
                    // first question and the sentence buries it mid-clause.
                    'actor_name' => $actor['name'] ?? null,
                ];
            })
            ->all();

        return [
            'items' => $items,
            'unread' => $user->unreadNotifications()->count(),
        ];
    }

    /**
     * Who the notification is about, from whatever the row actually stored.
     *
     * `actor_name` is the snapshot taken when the notification was sent, and
     * is what every row written from now on carries. The fallback is not a
     * guess: `sender_name` is a **stored field** that `PrivateDocumentShared`
     * has always written, so a shared-document row from before the snapshot
     * still knows whose name it is about — it simply did not record their
     * face. `resources/js/avatar.js` derives a distinct, stable one from the
     * name alone, which is exactly the case it exists for.
     *
     * An older *access* notification recorded no name at all, so it has no
     * actor and says so. That is the honest answer: the face would have to be
     * invented, and the row's own sentence already names the person.
     *
     * Nothing is ever parsed out of `message`. A sentence is prose, and a face
     * built by reading one would be a fabrication rendered as a record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function actor(array $data): ?array
    {
        $name = $data['actor_name'] ?? $data['sender_name'] ?? null;

        return $name === null ? null : [
            'name' => $name,
            'profile_image' => $data['actor_profile_image'] ?? null,
            'avatar_seed' => $data['actor_avatar_seed'] ?? null,
        ];
    }

    /**
     * The kind of a row written before `kind` was stored.
     *
     * Read off the **structure** of the payload rather than off its sentence:
     * `sender_name` is only ever written by `PrivateDocumentShared`, and the
     * `icon` key was itself written by the notification class as a fixed
     * marker of which of the three access outcomes the row is — a stored fact,
     * not prose. Anything that matches none of them stays null and renders as
     * a plain bell, which is the honest answer.
     *
     * This is a reading of old rows, never a second place kinds are decided:
     * every notification written from now on stores its own.
     *
     * @param  array<string, mixed>  $data
     */
    private static function legacyKind(array $data): ?NotificationKind
    {
        if (isset($data['sender_name'], $data['document_id'])) {
            return NotificationKind::Shared;
        }

        return match ($data['icon'] ?? null) {
            'fa-solid fa-user-clock' => NotificationKind::AccessRequested,
            'fa-solid fa-lock-open' => NotificationKind::AccessGranted,
            'fa-solid fa-circle-xmark' => NotificationKind::AccessDeclined,
            default => null,
        };
    }

    /**
     * Mark a specific notification as read and redirect to its associated URL.
     */
    public function read(string $id): RedirectResponse
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return redirect(self::destinationFor($notification->data ?? []));
    }

    /**
     * Where a row takes its reader.
     *
     * The stored `url`, unless it is missing or is the bare dashboard — in
     * which case the **kind** answers, exactly as `legacyKind()` reads an
     * older row's kind out of its structure rather than its sentence. The
     * dashboard is not a destination: it names neither the document nor the
     * decision, so a reader who clicks "your request was declined" and lands
     * there has been told nothing and given nothing to do.
     *
     * Deriving it means every notification *already sent* starts working,
     * which is the whole point: a fix that only applies to rows written from
     * now on leaves a bell full of links that go nowhere.
     *
     * A kind nothing matches keeps the dashboard, because a wrong
     * destination is worse than a neutral one.
     *
     * @param  array<string, mixed>  $data
     */
    private static function destinationFor(array $data): string
    {
        $stored = $data['url'] ?? null;
        $dashboard = route('dashboard');

        if ($stored !== null && $stored !== $dashboard) {
            return $stored;
        }

        return match (NotificationKind::tryFrom($data['kind'] ?? '') ?? self::legacyKind($data)) {
            // Somebody is asking to be let in: the panel that can answer.
            NotificationKind::AccessRequested => route('documents.index').'?requests=incoming',
            // A refusal: their own list, where the answer and its note are.
            NotificationKind::AccessDeclined => route('documents.index').'?requests=outgoing',
            /*
             * `shared` and `access_granted` are deliberately absent.
             *
             * Both are about one document and both already store its URL, so
             * they never reach this fallback — and there is nothing here to
             * rebuild one from: the code is interpolated into the sentence,
             * never kept as a field, and reading an identifier back out of
             * prose is an invention rendered as a record.
             */
            default => $dashboard,
        };
    }

    /**
     * Mark all of the user's unread notifications as read.
     */
    public function markAllRead(): RedirectResponse
    {
        Auth::user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
