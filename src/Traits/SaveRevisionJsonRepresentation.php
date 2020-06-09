<?php

namespace Neurony\Revisions\Traits;

use Neurony\Revisions\Helpers\RelationHelper;

trait SaveRevisionJsonRepresentation
{
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
        $data = $this->wasRecentlyCreated === true ? $this->getAttributes() : $this->getRawOriginal();

        $fieldsToRevision = $this->revisionOptions->revisionFields;
        $fieldsToNotRevision = $this->revisionOptions->revisionNotFields;

        unset($data[$this->getKeyName()]);

        if ($this->usesTimestamps() && ! $this->revisionOptions->revisionTimestamps) {
            unset($data[$this->getCreatedAtColumn()]);
            unset($data[$this->getUpdatedAtColumn()]);
        }

        if ($fieldsToRevision && is_array($fieldsToRevision) && ! empty($fieldsToRevision)) {
            foreach ($data as $field => $value) {
                if (! in_array($field, $fieldsToRevision)) {
                    unset($data[$field]);
                }
            }
        } elseif ($fieldsToNotRevision && is_array($fieldsToNotRevision) && ! empty($fieldsToNotRevision)) {
            foreach ($data as $field => $value) {
                if (in_array($field, $fieldsToNotRevision)) {
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
            $data = $this->dataWithForeignKeys(
                $data, $model->getKeyName(), $this->getForeignKey()
            );

            foreach ($model->getRawOriginal() as $field => $value) {
                $data = $this->dataWithAttributeValue(
                    $data, $model->getAttributes(), $index, $field, $value
                );
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
            $accessor = $this->{$relation}()->getPivotAccessor();
            $pivot = $model->{$accessor};

            foreach ($model->getRawOriginal() as $field => $value) {
                $data = $this->dataWithForeignKeys(
                    $data, $model->getKeyName(), $this->getForeignKey()
                );

                $data = $this->dataWithAttributeValue(
                    $data, $model->getAttributes(), $index, $field, $value
                );
            }

            foreach ($pivot->getRawOriginal() as $field => $value) {
                $data = $this->dataWithPivotForeignKeys(
                    $data, $pivot->getKeyName(), $pivot->getForeignKey(), $pivot->getRelatedKey()
                );

                $data = $this->dataWithPivotAttributeValue(
                    $data, $pivot->getAttributes(), $index, $field, $value
                );
            }
        }

        return $data;
    }

    /**
     * Verify if the data array contains the foreign keys.
     *
     * @param array $data
     * @return bool
     */
    protected function dataHasForeignKeys(array $data = []): bool
    {
        return $data['records']['primary_key'] && $data['records']['foreign_key'];
    }

    /**
     * Verify if the data array contains the pivoted foreign keys.
     *
     * @param array $data
     * @return bool
     */
    protected function dataHasPivotForeignKeys(array $data = []): bool
    {
        return $data['pivots']['primary_key'] && $data['pivots']['foreign_key'] && $data['pivots']['related_key'];
    }

    /**
     * Attach the foreign keys to the data array.
     *
     * @param array $data
     * @param string $primaryKey
     * @param string $foreignKey
     * @return array
     */
    protected function dataWithForeignKeys(array $data, string $primaryKey, string $foreignKey): array
    {
        if (! $this->dataHasForeignKeys($data)) {
            $data['records']['primary_key'] = $primaryKey;
            $data['records']['foreign_key'] = $foreignKey;
        }

        return $data;
    }

    /**
     * Attach the pivoted foreign keys to the data array.
     *
     * @param array $data
     * @param string $primaryKey
     * @param string $foreignKey
     * @param string $relatedKey
     * @return array
     */
    protected function dataWithPivotForeignKeys(array $data, string $primaryKey, string $foreignKey, string $relatedKey): array
    {
        if (! $this->dataHasPivotForeignKeys($data)) {
            $data['pivots']['primary_key'] = $primaryKey;
            $data['pivots']['foreign_key'] = $foreignKey;
            $data['pivots']['related_key'] = $relatedKey;
        }

        return $data;
    }

    /**
     * Build the data array with each attribute<->value set for the given model.
     *
     * @param array $data
     * @param array $attributes
     * @param int $index
     * @param string $field
     * @param string|int|null $value
     * @return array
     */
    protected function dataWithAttributeValue(array $data, array $attributes, int $index, string $field, $value = null): array
    {
        if (array_key_exists($field, $attributes)) {
            $data['records']['items'][$index][$field] = $value;
        }

        return $data;
    }

    /**
     * Build the data array with each pivoted attribute<->value set for the given model.
     *
     * @param array $data
     * @param array $attributes
     * @param int $index
     * @param string $field
     * @param string|int|null $value
     * @return array
     */
    protected function dataWithPivotAttributeValue(array $data, array $attributes, int $index, string $field, $value = null): array
    {
        if (array_key_exists($field, $attributes)) {
            $data['pivots']['items'][$index][$field] = $value;
        }

        return $data;
    }

    /**
     * Get the relations that should be revisionable alongside the original model.
     *
     * @return array
     * @throws \ReflectionException
     */
    protected function getRelationsForRevision(): array
    {
        $relations = [];

        foreach (RelationHelper::getModelRelations($this) as $relation => $attributes) {
            if (in_array($relation, $this->revisionOptions->revisionRelations)) {
                $relations[$relation] = $attributes;
            }
        }

        return $relations;
    }
}
