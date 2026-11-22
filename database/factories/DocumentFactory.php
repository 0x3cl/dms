<?php

namespace Database\Factories;

use App\Enums\DocumentVisibility;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
{
    /**
     * A document already carrying a tracking code, so a test does not have to
     * go through an upload to get one. The code is composed the same way
     * TrackingCodeService composes it; a test that cares about the *series*
     * should go through the service or the upload route instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = DocumentType::query()->inRandomOrder()->first() ?? DocumentType::factory()->create();
        $code = $type->code.'-'.now()->format('Ymd').'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);

        return [
            'code' => $code,
            'title' => ucfirst(fake()->words(3, true)),
            'document_type_id' => $type->id,
            'type' => $type->name,
            'file_path' => 'documents/'.fake()->uuid().'.pdf',
            'file_name' => fake()->slug(2).'.pdf',
            'qr_code_path' => "{$code}.png",
            'user_id' => User::factory(),
            'uploader_name' => fn (array $attributes) => User::find($attributes['user_id'])?->name ?? fake()->name(),
            'visibility' => DocumentVisibility::Public,
        ];
    }

    public function private(): static
    {
        return $this->state(fn (): array => ['visibility' => DocumentVisibility::Private]);
    }

    /** A document of a named type, by its code (e.g. `MEMO`). */
    public function ofType(string $code): static
    {
        return $this->state(function () use ($code): array {
            $type = DocumentType::query()->where('code', $code)->firstOrFail();
            $number = str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);
            $trackingCode = $type->code.'-'.now()->format('Ymd').'-'.$number;

            return [
                'document_type_id' => $type->id,
                'type' => $type->name,
                'code' => $trackingCode,
                'qr_code_path' => "{$trackingCode}.png",
            ];
        });
    }
}
