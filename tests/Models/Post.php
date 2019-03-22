<?php

namespace Neurony\Revisions\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Neurony\Revisions\Traits\HasRevisions;

class Post extends Model
{
    use HasRevisions;

    /**
     * The database table.
     *
     * @var string
     */
    protected $table = 'posts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'author_id',
        'name',
        'slug',
        'content',
        'votes',
        'views',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function author()
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function reply()
    {
        return $this->hasOne(Reply::class, 'post_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    /**
     * A post has and belongs to many tags.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }

    /**
     * @return \Neurony\Revisions\Options\RevisionOptions
     */
    public function getRevisionOptions()
    {
        return \Neurony\Revisions\Options\RevisionOptions::instance();
    }
}
