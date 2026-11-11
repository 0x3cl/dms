<?php

namespace App\Support;

/**
 * The two roles the application ships with, by name.
 *
 * These live beside App\Support\Modules rather than on the seeder that creates
 * them: a Gate hook, a factory and a middleware all need to name SUPERADMIN,
 * and none of them should have to reach into database/seeders to do it.
 *
 * Everything else is an administrator's business. These two are the
 * application's, because code depends on them:
 *
 * - SUPERADMIN is short-circuited to allowed by the `Gate::before` hook in
 *   AppServiceProvider, and the roles screen refuses to rename or delete it.
 * - STAFF is assigned to every self-registered account, so it is the floor a
 *   new user lands on.
 */
class Roles
{
    public const SUPERADMIN = 'SUPERADMIN';

    public const STAFF = 'Staff';

    /**
     * The least-privilege default every new and self-registered account gets.
     *
     * Deliberately enough to do the job the office hired them for — upload a
     * document, track it, revise and withdraw their own, see the office
     * structure. Deliberately not the download register, which carries the IP
     * every copy went to, and not any directory write.
     *
     * `users.read` is deliberately absent, and it is the one grant that
     * narrowed when the staff directory became the Users module. The old
     * `employees.read` opened a people lookup — a list of names and sections,
     * and nothing else. The screen that replaced it is where roles are handed
     * out and other people's passwords are set, so reaching it is reaching
     * the access model itself. Those are not the same grant and the first does
     * not imply the second. Staff keep `divisions.read` and `sections.read`,
     * which is the office structure without the people.
     *
     * `documents.write` and `documents.delete` are here on purpose, and they
     * are not a licence over other people's uploads. A module permission says
     * the capability exists for this user; DocumentController::owns() still
     * decides which record it applies to. Withholding them would not protect
     * anything — it would stop a user editing or withdrawing the document they
     * uploaded themselves.
     *
     * @var list<string>
     */
    public const STAFF_PERMISSIONS = [
        'documents.read',
        'documents.create',
        'documents.write',
        'documents.delete',
        'documents.download',
        'documents.share',
        'documents-all.read',

        // Their own storage — which now includes what has been shared with
        // them, since Shared with Me was folded into My Files. `files.manage`
        // is deliberately absent: it is the reach across everyone else's
        // storage, and it is the gate on the Browse Storage screen.
        'files.read',
        'files.create',
        'files.edit',
        'files.delete',
        'files.share',
        'files.download',

        'divisions.read',
        'sections.read',
    ];
}
