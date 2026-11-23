<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office structure — divisions and sections — plus the account page.
 *
 * The people who sit in those sections are accounts now, and
 * Tests\Feature\UserManagementTest covers them. What stays here is the
 * structure's own rules: a section name is unique only within its division, a
 * division holding anything is not deleted, and deleting a section does not
 * take the people in it.
 */
class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deleting a section used to delete every person recorded in it: the
     * foreign key cascaded. It nulls now.
     */
    public function test_deleting_a_section_does_not_delete_the_people_in_it(): void
    {
        $section = Section::factory()->create();
        $user = User::factory()->inSection($section)->create();

        // Forced: `nullOnDelete` fires on a real delete, not a soft one.
        $section->forceDelete();

        $this->assertModelExists($user);
        $this->assertNull($user->fresh()->section_id);
    }

    /**
     * `sections` had no unique constraint at all, so one division could hold
     * the same section twice and quietly split that office's staff and audit
     * rows between two rows.
     */
    public function test_a_division_cannot_hold_two_sections_of_the_same_name(): void
    {
        $division = Division::factory()->create();
        Section::factory()->for($division)->create(['code' => 'RECORDS', 'name' => 'Records Section']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/sections', [
                'division_id' => $division->id,
                'code' => 'RECORDS',
                'name' => 'Records Section',
            ])
            ->assertSessionHasErrors(['code', 'name']);
    }

    /**
     * But two different divisions may each run one.
     */
    public function test_two_divisions_may_each_have_a_records_section(): void
    {
        Section::factory()->create(['code' => 'RECORDS', 'name' => 'Records Section']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->post('/sections', [
                'division_id' => Division::factory()->create()->id,
                'code' => 'RECORDS',
                'name' => 'Records Section',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Section::query()->where('code', 'RECORDS')->count());
    }

    public function test_the_account_page_renders(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')->assertOk();
    }

    public function test_a_division_with_sections_is_not_deleted(): void
    {
        $division = Division::factory()->create(['name' => 'Research Division']);
        Section::factory()->for($division)->create(['name' => 'Textiles Section']);

        $this->actingAs(User::factory()->superadmin()->create())
            ->delete("/divisions/{$division->id}")
            ->assertSessionHas('error');

        $this->assertModelExists($division);
    }

    /**
     * The JSON narrowing endpoint, and the account it now needs.
     *
     * It used to sit outside the `auth` group, which was right while there
     * was a registration form — a guest choosing their own office had to be
     * able to narrow the list. That form was removed and this stayed: a
     * public endpoint anybody could walk division by division to map the
     * office's structure, behind no grant and called by nothing, since the
     * forms that narrow a section picker do it client-side from props they
     * already hold. Both halves are asserted, because a gate added without
     * checking who still needs to pass it is an outage.
     */
    public function test_sections_can_be_listed_for_a_division_as_json(): void
    {
        $division = Division::factory()->create();
        Section::factory()->for($division)->create(['name' => 'Records Section']);

        $this->get("/sections/by-division/{$division->id}")->assertRedirect('/login');

        // `sections.read` — which Staff holds, so the picker still works for
        // an ordinary account.
        $this->actingAs(User::factory()->create())
            ->get("/sections/by-division/{$division->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Records Section']);
    }
}
