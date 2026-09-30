<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image, audio clip, or video shown with a question. Created and removed
 * only through App\Services\QuestionBank\QuestionMediaService. The storage
 * path is never sent to the browser; files are served by id through
 * authorized routes.
 */
#[Hidden(['path'])]
class QuestionMedia extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_AUDIO = 'audio';

    public const KIND_VIDEO = 'video';

    protected $table = 'question_media';

    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'question_choice_id' => 'integer',
            'position' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * Set when this is the image of an answer choice.
     *
     * @return BelongsTo<QuestionChoice, $this>
     */
    public function choice(): BelongsTo
    {
        return $this->belongsTo(QuestionChoice::class, 'question_choice_id');
    }
}
