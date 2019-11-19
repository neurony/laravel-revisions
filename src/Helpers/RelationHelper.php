<?php

namespace Neurony\Revisions\Helpers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionException;
use ReflectionMethod;
use SplFileObject;

class RelationHelper
{
    /**
     * List of all relations defined on a model class.
     *
     * @var array
     */
    protected static $relations = [];

    /**
     * Laravel's available relation types (classes|methods).
     *
     * @var array
     */
    protected static $relationTypes = [
        'hasOne',
        'hasMany',
        'hasManyThrough',
        'belongsTo',
        'belongsToMany',
        'morphOne',
        'morphMany',
        'morphTo',
        'morphToMany',
    ];

    /**
     * All available Laravel's direct relations.
     *
     * @var array
     */
    protected static $directRelations = [
        HasOne::class,
        MorphOne::class,
        HasMany::class,
        MorphMany::class,
        BelongsTo::class,
        MorphTo::class,
    ];

    /**
     * All available Laravel's pivoted relations.
     *
     * @var array
     */
    protected static $pivotedRelations = [
        BelongsToMany::class,
        MorphToMany::class,
    ];

    /**
     * All available Laravel's direct parent relations.
     *
     * @var array
     */
    protected static $parentRelations = [
        BelongsTo::class,
        MorphTo::class,
    ];

    /**
     * All available Laravel's direct child relations.
     *
     * @var array
     */
    protected static $childRelations = [
        HasOne::class,
        MorphOne::class,
        HasMany::class,
        MorphMany::class,
    ];

    /**
     * All available Laravel's direct single child relations.
     *
     * @var array
     */
    protected static $childRelationsSingle = [
        HasOne::class,
        MorphOne::class,
    ];

    /**
     * All available Laravel's direct multiple children relations.
     *
     * @var array
     */
    protected static $childRelationsMultiple = [
        HasMany::class,
        MorphMany::class,
    ];

    /**
     * Verify if a given relation is direct or not.
     *
     * @param string $relation
     * @return bool
     */
    public static function isDirect(string $relation): bool
    {
        return in_array($relation, static::$directRelations);
    }

    /**
     * Verify if a given relation is pivoted or not.
     *
     * @param string $relation
     * @return bool
     */
    public static function isPivoted(string $relation): bool
    {
        return in_array($relation, static::$pivotedRelations);
    }

    /**
     * Verify if a given direct relation is of type parent.
     *
     * @param string $relation
     * @return bool
     */
    public static function isParent(string $relation): bool
    {
        return in_array($relation, static::$parentRelations);
    }

    /**
     * Verify if a given direct relation is of type child.
     *
     * @param string $relation
     * @return bool
     */
    public static function isChild(string $relation): bool
    {
        return in_array($relation, static::$childRelations);
    }

    /**
     * Verify if a given direct relation is of type single child.
     * Ex: hasOne, morphOne.
     *
     * @param string $relation
     * @return bool
     */
    public static function isChildSingle(string $relation): bool
    {
        return in_array($relation, static::$childRelationsSingle);
    }

    /**
     * Verify if a given direct relation is of type single child.
     * Ex: hasMany, morphMany.
     *
     * @param string $relation
     * @return bool
     */
    public static function isChildMultiple(string $relation): bool
    {
        return in_array($relation, static::$childRelationsMultiple);
    }

    /**
     * Get all the defined model class relations.
     * Not just the eager loaded ones present in the $relations Eloquent property.
     *
     * @param Model $model
     * @return array
     * @throws ReflectionException
     */
    public static function getModelRelations(Model $model): array
    {
        foreach (get_class_methods($model) as $method) {
            if (! method_exists(Model::class, $method)) {
                $reflection = new ReflectionMethod($model, $method);
                $file = new SplFileObject($reflection->getFileName());
                $code = '';

                $file->seek($reflection->getStartLine() - 1);

                while ($file->key() < $reflection->getEndLine()) {
                    $code .= $file->current();
                    $file->next();
                }

                $code = trim(preg_replace('/\s\s+/', '', $code));
                $begin = strpos($code, 'function(');
                $code = substr($code, $begin, strrpos($code, '}') - $begin + 1);

                foreach (static::$relationTypes as $type) {
                    if (stripos($code, '$this->'.$type.'(')) {
                        $relation = $model->$method();

                        if ($relation instanceof Relation) {
                            static::$relations[$method] = [
                                'type' => get_class($relation),
                                'model' => $relation->getRelated(),
                                'original' => $relation->getParent(),
                            ];
                        }
                    }
                }
            }
        }

        return static::$relations;
    }
}
