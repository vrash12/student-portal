<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['position','points'])] class ExaminationQuestion extends Model { public function examination():BelongsTo{return $this->belongsTo(Examination::class);} public function question():BelongsTo{return $this->belongsTo(Question::class);} protected function casts():array{return ['position'=>'integer','points'=>'decimal:2'];} }
