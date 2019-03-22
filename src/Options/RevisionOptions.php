<?php

namespace Neurony\Revisions\Options;

use Exception;

class RevisionOptions
{
    /**
     * Flag whether to make a revision on model creation.
     *
     * @var bool
     */
    private $revisionOnCreate = false;

    /**
     * The limit of revisions to be created for a model instance.
     * If the limit is reached, oldest revisions will start getting deleted to make room for new ones.
     *
     * @var int
     */
    private $revisionLimit;

    /**
     * The fields that should be revisionable.
     * By default (null) all fields are revisionable.
     *
     * @var array
     */
    private $revisionFields = [];

    /**
     * The model's relations that should be revisionable.
     * By default (null) none of the model's relations are revisionable.
     *
     * @var array
     */
    private $revisionRelations = [];

    /**
     * Flag indicating whether to create a revision for the model, when rolling back another revision of that model.
     * If set to "true", before rolling back a revision, the original model instance's data will be stored to a new revision.
     * If set to "false", after rolling back a revision, the original model instance's data will NOT be stored to a new revision.
     *
     * @var bool
     */
    private $createRevisionWhenRollingBack = true;

    /**
     * Get the value of a property of this class.
     *
     * @param $name
     * @return mixed
     * @throws Exception
     */
    public function __get($name)
    {
        if (property_exists(static::class, $name)) {
            return $this->{$name};
        }

        throw new Exception(
            'The property "'.$name.'" does not exist in class "'.static::class.'"'
        );
    }

    /**
     * Get a fresh instance of this class.
     *
     * @return RevisionOptions
     */
    public static function instance(): self
    {
        return new static();
    }

    /**
     * Set the $revisionOnCreate to work with in the Neurony\Revisions\Traits\HasRevisions trait.
     *
     * @return RevisionOptions
     */
    public function enableRevisionOnCreate(): self
    {
        $this->revisionOnCreate = true;

        return $this;
    }

    /**
     * Set the $revisionLimit to work with in the Neurony\Revisions\Traits\HasRevisions trait.
     *
     * @param int $limit
     * @return RevisionOptions
     */
    public function limitRevisionsTo(int $limit): self
    {
        $this->revisionLimit = (int) $limit;

        return $this;
    }

    /**
     * Set the $revisionFields to work with in the Neurony\Revisions\Traits\HasRevisions trait.
     *
     * @param $fields
     * @return RevisionOptions
     */
    public function fieldsToRevision(...$fields): self
    {
        $this->revisionFields = array_flatten($fields);

        return $this;
    }

    /**
     * Set the $revisionRelations to work with in the Neurony\Revisions\Traits\HasRevisions trait.
     *
     * @param $relations
     * @return RevisionOptions
     */
    public function relationsToRevision(...$relations): self
    {
        $this->revisionRelations = array_flatten($relations);

        return $this;
    }

    /**
     * Set the $createRevisionWhenRollingBack to work with in the Neurony\Revisions\Traits\HasRevisions trait.
     *
     * @return RevisionOptions
     */
    public function disableRevisioningWhenRollingBack(): self
    {
        $this->createRevisionWhenRollingBack = false;

        return $this;
    }
}
