<?php

namespace Neurony\Revisions\Traits;

use Closure;
use Exception;
use Illuminate\Support\Facades\DB;
use Neurony\Revisions\Models\Revision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Neurony\Revisions\Helpers\RelationHelper;
use Neurony\Revisions\Options\RevisionOptions;
use Neurony\Revisions\Contracts\RevisionModelContract;

trait HasRevisions
{
    use SaveRevisionJsonRepresentation;
    use RollbackRevisionJsonRepresentation;

    /**
     * The container for all the options necessary for this trait.
     * Options can be viewed in the Neurony\Revisions\Options\RevisionOptions file.
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
            if (! $this->shouldCreateRevision()) {
                return false;
            }

            if ($this->fireModelEvent('revisioning') === false) {
                return false;
            }

            $revision = $this->saveAsRevision();

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
        $this->initRevisionOptions();

        try {
            return DB::transaction(function () {
                $revision = $this->revisions()->create([
                    'user_id' => auth()->id() ?: null,
                    'metadata' => $this->buildRevisionData(),
                ]);

                $this->clearOldRevisions();

                return $revision;
            });
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

                if ($revision instanceof RevisionModelContract && isset($revision->metadata['relations'])) {
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

        if ($fields && is_array($fields) && ! empty($fields)) {
            return $this->isDirty($fields);
        }

        return true;
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
