<?php

namespace Zbiller\Revisions\Traits;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Zbiller\Revisions\Contracts\RevisionModelContract;
use Zbiller\Revisions\Helpers\RelationHelper;
use Zbiller\Revisions\Models\Revision;
use Zbiller\Revisions\Options\RevisionOptions;

trait HasRevisions
{
    /**
     * The container for all the options necessary for this trait.
     * Options can be viewed in the Zbiller\Revisions\Options\RevisionOptions file.
     *
     * @var RevisionOptions
     */
    protected $revisionOptions;

    /**
     * Set the options for the HasRevisions trait.
     *
     * @return RevisionOptions
     */
    abstract public function getRevisionOptions(): RevisionOptions;

    /**
     * Boot the trait.
     * Remove blocks on save and delete if one or many locations from model's instance have been changed/removed.
     *
     * @return void
     */
    public static function bootHasRevisions()
    {
        static::created(function (Model $model) {
            $model->createNewRevision();
        });

        static::updated(function (Model $model) {
            $model->createNewRevision();
        });

        static::deleted(function (Model $model) {
            if ($model->forceDeleting !== false) {
                $model->deleteAllRevisions();
            }
        });
    }

    /**
     * Register a revisioning model event with the dispatcher.
     *
     * @param Closure|string $callback
     * @return void
     */
    public static function revisioning($callback): void
    {
        static::registerModelEvent('revisioning', $callback);
    }

    /**
     * Register a revisioned model event with the dispatcher.
     *
     * @param Closure|string $callback
     * @return void
     */
    public static function revisioned($callback): void
    {
        static::registerModelEvent('revisioned', $callback);
    }

    /**
     * Get all the revisions for a given model instance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function revisions()
    {
        $revision = config('revisions.revision_model', Revision::class);

        return $this->morphMany($revision, 'revisionable');
    }

    /**
     * Create a new revision record for the model instance.
     *
     * @return Revision|bool
     * @throws Exception
     */
    public function createNewRevision()
    {
        $this->initRevisionOptions();

        if ($this->wasRecentlyCreated && $this->revisionOptions->revisionOnCreate !== true) {
            return;
        }

        try {
            if (!$this->shouldCreateRevision()) {
                return false;
            }

            if ($this->fireModelEvent('revisioning') === false) {
                return false;
            }

            $revision = DB::transaction(function () {
                $revision = $this->revisions()->create([
                    'user_id' => auth()->id() ?: null,
                    'metadata' => $this->buildRevisionData(),
                ]);

                $this->clearOldRevisions();

                return $revision;
            });

            $this->fireModelEvent('revisioned', false);

            return $revision;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Manually save a new revision for a model instance.
     * This method should be called manually only where and if needed.
     *
     * @return Revision
     * @throws Exception
     */
    public function saveAsRevision(): Revision
    {
        try {
            $revision = DB::transaction(function () {
                $revision = $this->revisions()->create([
                    'user_id' => auth()->id() ?: null,
                    'metadata' => $this->buildRevisionData(),
                ]);

                $this->clearOldRevisions();

                return $revision;
            });

            return $revision;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Rollback the model instance to the given revision instance.
     *
     * @param RevisionModelContract $revision
     * @return bool
     * @throws Exception
     */
    public function rollbackToRevision(RevisionModelContract $revision): bool
    {
        $this->initRevisionOptions();

        try {
            static::revisioning(function () {
                return false;
            });

            DB::transaction(function () use ($revision) {
                if ($this->revisionOptions->createRevisionWhenRollingBack === true) {
                    $this->saveAsRevision();
                }

                $this->rollbackModelToRevision($revision);

                if (isset($revision->metadata['relations'])) {
                    foreach ($revision->metadata['relations'] as $relation => $attributes) {
                        if (RelationHelper::isDirect($attributes['type'])) {
                            $this->rollbackDirectRelationToRevision($relation, $attributes);
                        }

                        if (RelationHelper::isPivoted($attributes['type'])) {
                            $this->rollbackPivotedRelationToRevision($relation, $attributes);
                        }
                    }
                }
            });

            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Remove all existing revisions from the database, belonging to a model instance.
     *
     * @return void
     * @throws Exception
     */
    public function deleteAllRevisions(): void
    {
        try {
            $this->revisions()->delete();
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * If a revision record limit is set on the model and that limit is exceeded.
     * Remove the oldest revisions until the limit is met.
     *
     * @return void
     */
    public function clearOldRevisions(): void
    {
        $this->initRevisionOptions();

        $limit = $this->revisionOptions->revisionLimit;
        $count = $this->revisions()->count();

        if (is_numeric($limit) && $count > $limit) {
            $this->revisions()->oldest()->take($count - $limit)->delete();
        }
    }

    /**
     * Determine if a revision should be stored for a given model instance.
     *
     * Check the revisionable fields set on the model.
     * If any of those fields have changed, then a new revisions should be stored.
     * If no fields are specifically set on the model, this will return true.
     *
     * @return bool
     */
    protected function shouldCreateRevision(): bool
    {
        $this->initRevisionOptions();

        $fields = $this->revisionOptions->revisionFields;

        if (
            array_key_exists(SoftDeletes::class, class_uses($this)) &&
            array_key_exists($this->getDeletedAtColumn(), $this->getDirty())
        ) {
            return false;
        }

        if ($fields && is_array($fields) && !empty($fields)) {
            return $this->isDirty($fields);
        }

        return true;
    }

    /**
     * Build the entire data array for further json insert into the revisions table.
     *
     * Extract the actual model's data.
     * Extract all of the model's direct relations data.
     * Extract all of the model's pivoted relations data.
     *
     * @return array
     * @throws \ReflectionException
     */
    protected function buildRevisionData(): array
    {
        $data = $this->buildRevisionDataFromModel();

        foreach ($this->getRelationsForRevision() as $relation => $attributes) {
            if (RelationHelper::isDirect($attributes['type'])) {
                $data['relations'][$relation] = $this->buildRevisionDataFromDirectRelation($relation, $attributes);
            }

            if (RelationHelper::isPivoted($attributes['type'])) {
                $data['relations'][$relation] = $this->buildRevisionDataFromPivotedRelation($relation, $attributes);
            }
        }

        return $data;
    }

    /**
     * Get all the fields that should be revisioned from the model instance.
     * Automatically unset primary and timestamp keys.
     * Also count for revision fields if any are set on the model.
     *
     * @return array
     */
    protected function buildRevisionDataFromModel(): array
    {
        $this->initRevisionOptions();

        $data = $this->wasRecentlyCreated === true ? $this->getAttributes() : $this->getOriginal();
        $fields = $this->revisionOptions->revisionFields;

        unset($data[$this->getKeyName()]);

        if ($this->usesTimestamps()) {
            unset($data[$this->getCreatedAtColumn()]);
            unset($data[$this->getUpdatedAtColumn()]);
        }

        if ($fields && is_array($fields) && !empty($fields)) {
            foreach ($data as $field => $value) {
                if (!in_array($field, $fields)) {
                    unset($data[$field]);
                }
            }
        }

        return $data;
    }

    /**
     * Extract revisionable data from a model's relation.
     * Extract the type, class and related records.
     * Store the extracted data into an array to be json inserted into the revisions table.
     *
     * @param string $relation
     * @param array $attributes
     * @return array
     */
    protected function buildRevisionDataFromDirectRelation(string $relation, array $attributes = []): array
    {
        $data = [
            'type' => $attributes['type'],
            'class' => get_class($attributes['model']),
            'records' => [
                'primary_key' => null,
                'foreign_key' => null,
                'items' => [],
            ],
        ];

        foreach ($this->{$relation}()->get() as $index => $model) {
            if (!$data['records']['primary_key'] || !$data['records']['foreign_key']) {
                $data['records']['primary_key'] = $model->getKeyName();
                $data['records']['foreign_key'] = $this->getForeignKey();
            }

            foreach ($model->getOriginal() as $field => $value) {
                if (array_key_exists($field, $model->getAttributes())) {
                    $data['records']['items'][$index][$field] = $value;
                }
            }
        }

        return $data;
    }

    /**
     * Extract revisionable data from a model's relation pivot table.
     * Extract the type, class, related records and pivot values.
     * Store the extracted data into an array to be json inserted into the revisions table.
     *
     * @param string $relation
     * @param array $attributes
     * @return array
     */
    protected function buildRevisionDataFromPivotedRelation(string $relation, array $attributes = []): array
    {
        $data = [
            'type' => $attributes['type'],
            'class' => get_class($attributes['model']),
            'records' => [
                'primary_key' => null,
                'foreign_key' => null,
                'items' => [],
            ],
            'pivots' => [
                'primary_key' => null,
                'foreign_key' => null,
                'related_key' => null,
                'items' => [],
            ],
        ];

        foreach ($this->{$relation}()->get() as $index => $model) {
            $pivot = $model->pivot;

            foreach ($model->getOriginal() as $field => $value) {
                if (!$data['records']['primary_key'] || !$data['records']['foreign_key']) {
                    $data['records']['primary_key'] = $model->getKeyName();
                    $data['records']['foreign_key'] = $this->getForeignKey();
                }

                if (array_key_exists($field, $model->getAttributes())) {
                    $data['records']['items'][$index][$field] = $value;
                }
            }

            foreach ($pivot->getOriginal() as $field => $value) {
                if (!$data['pivots']['primary_key'] || !$data['pivots']['foreign_key'] || !$data['pivots']['related_key']) {
                    $data['pivots']['primary_key'] = $pivot->getKeyName();
                    $data['pivots']['foreign_key'] = $pivot->getForeignKey();
                    $data['pivots']['related_key'] = $pivot->getRelatedKey();
                }

                if (array_key_exists($field, $pivot->getAttributes())) {
                    $data['pivots']['items'][$index][$field] = $value;
                }
            }
        }

        return $data;
    }

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
            } if (array_key_exists(SoftDeletes::class, class_uses($rel))) {
                $rel->{$rel->getDeletedAtColumn()} = null;
                $rel->save();
            }
        }

        $this->{$relation}()->detach();

        foreach ($attributes['pivots']['items'] as $item) {
            $this->{$relation}()->attach(
                $item[$attributes['pivots']['related_key']],
                array_except((array)$item, [
                    $attributes['pivots']['primary_key'],
                    $attributes['pivots']['foreign_key'],
                    $attributes['pivots']['related_key'],
                ])
            );
        }
    }

    /**
     * Get the relations that should be revisionable alongside the original model.
     *
     * @return array
     * @throws \ReflectionException
     */
    protected function getRelationsForRevision(): array
    {
        $this->initRevisionOptions();

        $relations = [];

        foreach (RelationHelper::getModelRelations($this) as $relation => $attributes) {
            if (in_array($relation, $this->revisionOptions->revisionRelations)) {
                $relations[$relation] = $attributes;
            }
        }

        return $relations;
    }

    /**
     * Both instantiate the revision options as well as validate their contents.
     *
     * @return void
     */
    protected function initRevisionOptions(): void
    {
        if ($this->revisionOptions === null) {
            $this->revisionOptions = $this->getRevisionOptions();
        }
    }
}
