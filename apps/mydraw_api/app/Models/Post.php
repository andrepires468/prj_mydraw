<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $table = 'posts';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'sort_order',
        'draft_uuid',
        'ai_tags',
        'content_rating',
        'ai_categorized_at',
    ];

    public static function prependPublishedSortOrder(int $postId): void
    {
        self::query()
            ->where('status', self::STATUS_PUBLISHED)
            ->where('id', '!=', $postId)
            ->increment('sort_order');
    }

    protected $casts = [
        'ai_tags'           => 'array',
        'ai_categorized_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'Id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(Gallery::class, 'post_id')->orderBy('position');
    }
}
