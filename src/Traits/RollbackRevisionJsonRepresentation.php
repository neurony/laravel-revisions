<?php

namespace Neurony\Revisions\Traits;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\SoftDeletes;
use Neurony\Revisions\Contracts\RevisionModelContract;

trait RollbackRevisionJsonRepresentation
{
    /**
     * Only rollback the model instance to the given revision.
     *
     * Loop through the revision's data.
     * If the revision's field name matches one from the model's attributes.
     * Replace the value from the model's attribute with the one from the revision.
     *
     * @param RevisionModelContract $revision
     * @return void
     */
    protected function rollbackModelToRevision(RevisionModelContract $revision): void
    {
        foreach ($revision->metadata as $field => $value) {
            if (array_key_exists($field, $this->getAttributes())) {
                $this->attributes[$field] = $value;
            }
        }

        $this->save();
    }

    /**
     * Only rollback the model's direct relations to the given revision.
     *
     * Loop through the stored revision's relation items.
     * If the relation exists, then update it with the data from the revision.
     * If the relation does not exist, then create a new one with the data from the revision.
     *
     * Please note that when creating a new relation, the primary key (id) will be the old one from the revision's data.
     * This way, the correspondence between the model and it's relation is kept.
     *
     * @param string $relation
     * @param array $attributes
     * @return void
     */
    protected function rollbackDirectRelationToRevision(string $relation, array $attributes): void
    {
        foreach ($attributes['records']['items'] as $item) {
            $related = $this->{$relation}();

            if (array_key_exists(SoftDeletes::class, class_uses($this->{$relation}))) {
                $related = $related->withTrashed();
            }

            $rel = $related->findOrNew($item[$attributes['records']['primary_key']] ?? null);

            foreach ($item as $field => $value) {
                $rel->attributes[$field] = $value;
            }

            if (array_key_exists(SoftDeletes::class, class_uses($rel))) {
                $rel->{$rel->getDeletedAtColumn()} = null;
            }

            $rel->save();
        }
    }

    /**
     * Rollback a model's pivoted relations to the given revision.
     *
     * Loop through the stored revision's relation items.
     * If the relation's related model exists, then leave it as is (maybe modified) because other records or entities might be using it.
     * If the relation's related model does not exist, then create a new one with the data from the revision.
     *
     * Please note that when creating a new relation related instance, the primary key (id) will be the old one from the revision's data.
     * This way, the correspondence between the model and it's relation is kept.
     *
     * Loop through the stored revision's relation pivots.
     * Sync the model's pivot values with the ones from the revision.
     *
     * @param string $relation
     * @param array $attributes
     * @return void
     */
    protected function rollbackPivotedRelationToRevision(string $relation, array $attributes): void
    {
        foreach ($attributes['records']['items'] as $item) {
            $related = $this->{$relation}()->getRelated();

            if (array_key_exists(SoftDeletes::class, class_uses($related))) {
                $related = $related->withTrashed();
            }

            $rel = $related->findOrNew($item[$attributes['records']['primary_key']] ?? null);

            if ($rel->exists === false) {
                foreach ($item as $field => $value) {
                    $rel->attributes[$field] = $value;
                }

                $rel->save();
            }
            if (array_key_exists(SoftDeletes::class, class_uses($rel))) {
                $rel->{$rel->getDeletedAtColumn()} = null;
                $rel->save();
            }
        }

        $this->{$relation}()->detach();

        foreach ($attributes['pivots']['items'] as $item) {
            $this->{$relation}()->attach(
                $item[$attributes['pivots']['related_key']],
                Arr::except((array) $item, [
                    $attributes['pivots']['primary_key'],
                    $attributes['pivots']['foreign_key'],
                    $attributes['pivots']['related_key'],
                ])
            );
        }
    }
}
