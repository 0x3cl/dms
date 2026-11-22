<?php

namespace Tests\Feature;

use App\Enums\FileAbility;
use App\Models\Document;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Who may listen.
 *
 * `/broadcasting/auth` carries `web` and **no** `permission:` middleware, so
 * the callbacks in `routes/channels.php` are the entire gate — anything they
 * forget is open. A document's travel history is exactly as sensitive as the
 * document (a log listing who received a confidential case file is a
 * disclosure of its own), so the last test here is differential: it resolves a
 * matrix of readers through `AccessResolver` and asserts the channel agrees
 * with it, in the same spirit as `FileListingTest`.
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real broadcaster, because the null one answers 200 to everything.
     *
     * `phpunit.xml` sets `BROADCAST_CONNECTION=null` so CI needs no websocket
     * server — but the null driver's `auth()` does nothing, so
     * `/broadcasting/auth` returns an empty 200 and the callbacks in
     * `routes/channels.php` never run. A test asserting refusals against that
     * would pass whatever those callbacks said, which is the worst kind of
     * green. No server is contacted: authorizing a subscription is a signature
     * computed locally from the app key.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        /*
         * Channels are registered on the *resolved* driver, so swapping the
         * default afterwards leaves a fresh driver with none of them — and a
         * channel nobody registered is refused, which would make every
         * "allowed" assertion below fail for a reason that has nothing to do
         * with the callbacks. Drop the resolved driver and register again.
         */
        Broadcast::forgetDrivers();
        require base_path('routes/channels.php');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::STAFF);

        return $user;
    }

    private function subscribe(?User $user, string $channel)
    {
        $request = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']);

        if ($user) {
            $request = $request->actingAs($user);
        }

        return $request->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    private function allowed($user, string $channel): bool
    {
        return $this->subscribe($user, $channel)->getStatusCode() < 400;
    }

    // ------------------------------------------------------ the document

    public function test_the_uploader_and_a_recipient_may_listen(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'private']);
        $document->recipients()->attach($recipient->id);

        $this->assertTrue($this->allowed($owner, "private-documents.{$document->id}"));
        $this->assertTrue($this->allowed($recipient, "private-documents.{$document->id}"));
    }

    public function test_a_stranger_may_not_listen_to_a_private_document(): void
    {
        $document = Document::factory()->create(['user_id' => $this->staff()->id, 'visibility' => 'private']);

        $this->assertFalse($this->allowed($this->staff(), "private-documents.{$document->id}"));
    }

    public function test_a_stranger_may_not_listen_to_a_confidential_document(): void
    {
        $document = Document::factory()->create(['user_id' => $this->staff()->id, 'visibility' => 'confidential']);

        $this->assertFalse($this->allowed($this->staff(), "private-documents.{$document->id}"));
    }

    /** A public document is readable by anyone signed in, so is its trail. */
    public function test_anyone_may_listen_to_a_public_documents_trail(): void
    {
        $document = Document::factory()->create(['user_id' => $this->staff()->id, 'visibility' => 'public']);

        $this->assertTrue($this->allowed($this->staff(), "private-documents.{$document->id}"));
    }

    public function test_a_guest_may_not_listen(): void
    {
        $document = Document::factory()->create(['user_id' => $this->staff()->id, 'visibility' => 'public']);

        $this->assertFalse($this->allowed(null, "private-documents.{$document->id}"));
    }

    /**
     * A missing document is refused, not a 500.
     *
     * `find()` rather than `findOrFail()`: an exception thrown inside an
     * authorization callback is a server error, which is both a worse answer
     * and a noisier one.
     */
    public function test_an_unknown_document_is_refused(): void
    {
        $this->assertFalse($this->allowed($this->staff(), 'private-documents.999999'));
    }

    // ----------------------------------------------------- the other two

    public function test_the_activity_channel_needs_its_permission(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Roles::SUPERADMIN);

        $this->assertTrue($this->allowed($admin, 'private-activity-trails'));
        $this->assertFalse($this->allowed($this->staff(), 'private-activity-trails'));
    }

    public function test_a_user_channel_is_their_own(): void
    {
        $user = $this->staff();
        $other = $this->staff();

        $this->assertTrue($this->allowed($user, "private-users.{$user->id}"));
        $this->assertFalse($this->allowed($user, "private-users.{$other->id}"));
    }

    // ------------------------------------------------------ differential

    /**
     * The channel answers exactly what the resolver answers.
     *
     * Asserts no hand-written expectation, so it cannot rot: whatever
     * `AccessResolver` decides for a given reader and document, the channel
     * must decide the same. If the two ever drift, this fails without anybody
     * having to notice.
     */
    public function test_the_document_channel_agrees_with_the_resolver(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff();
        $stranger = $this->staff();

        $documents = collect(['public', 'private', 'confidential'])->map(
            fn (string $level) => Document::factory()->create([
                'user_id' => $owner->id,
                'visibility' => $level,
            ])
        );
        $documents->each(fn (Document $document) => $document->recipients()->attach($recipient->id));

        $resolver = app(AccessResolver::class);

        foreach ([$owner, $recipient, $stranger] as $reader) {
            foreach ($documents as $document) {
                $expected = $resolver->allows($reader, $document, FileAbility::View);

                $this->assertSame(
                    $expected,
                    $this->allowed($reader, "private-documents.{$document->id}"),
                    "The channel disagreed with the resolver for {$document->visibility->value}.",
                );
            }
        }
    }
}
