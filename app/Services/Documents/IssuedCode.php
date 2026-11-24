<?php

namespace App\Services\Documents;

use App\Models\DocumentType;

/**
 * A tracking code that has actually been issued, with the type it came from.
 *
 * It exists so that a code can travel from where it is issued to where it is
 * recorded **without passing through a request as a string**. Stamping a file
 * in the browser means the code has to be known before the bytes are uploaded,
 * so the client necessarily holds one and posts it back — and a method that
 * takes `string $code` cannot tell the difference between a code this
 * application issued and one somebody typed.
 *
 * Only two things construct one: `TrackingCodeService`, which advances the
 * series, and `CodeReservationService::claim()`, which verifies that a code
 * coming back off a request is one this person was given. Everything
 * downstream takes the object, so there is no overload that accepts a bare
 * string and no path by which a client chooses its own code.
 *
 * Readonly, because a code is a fact. The series has already moved.
 */
final readonly class IssuedCode
{
    public function __construct(
        public string $code,
        public DocumentType $type,
    ) {}
}
