<?php

namespace App\Models;

use App\Enums\MedicalFieldType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A field of the candidate medical record, defined by administrators. Its
 * type is fixed once values are recorded; a field in use is deactivated,
 * never deleted. Change through MedicalRecordService.
 */
class MedicalField extends Model
{
    protected $fillable = [
        'name',
        'section',
        'field_type',
        'options',
        'unit',
        'help_text',
        'sort_order',
        'visible_to_candidate',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_type' => MedicalFieldType::class,
            'options' => 'array',
            'sort_order' => 'integer',
            'visible_to_candidate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CandidateMedicalValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CandidateMedicalValue::class);
    }

    /**
     * @param  Builder<MedicalField>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<MedicalField>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return list<string>
     */
    public function choiceOptions(): array
    {
        return array_values(array_map('strval', $this->options ?? []));
    }
}
