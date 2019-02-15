<?php

namespace Zbiller\Revisions\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface RevisionModelContract
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user();

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function revisionable();

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param \Illuminate\Contracts\Auth\Authenticatable $user
     */
    public function scopeWhereUser($query, Authenticatable $user);

    /**
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $id
     * @param string $type
     */
    public function scopeWhereRevisionable($query, int $id, string $type);
}
