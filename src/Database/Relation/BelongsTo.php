<?php

namespace Spark\Database\Relation;

use Closure;
use Spark\Database\Model;

/**
 * Class BelongsTo
 * 
 * Represents a "belongs to" relationship in a database model.
 * 
 * This class is used to define a relationship where a model belongs to another model.
 * It encapsulates the related model, foreign key, owner key, and other parameters
 * necessary to establish the relationship.
 * 
 * @package Spark\Database\Relation
 */
class BelongsTo extends Relation
{
    /**
     * Create a new BelongsTo relationship instance.
     * 
     * @param string $related The related model class name.
     * @param string|null $foreignKey The foreign key in the current model that references the related model.
     * @param string|null $ownerKey The primary key in the related model that the foreign key references.
     * @param bool $lazy Whether to load the relationship lazily.
     * @param Closure|null $callback An optional callback to modify the query for the relationship.
     * @param Model|null $model The model instance that this relationship belongs to.
     */
    public function __construct(
        private string $related,
        private ?string $foreignKey = null,
        private ?string $ownerKey = null,
        private bool $lazy = true,
        private ?Closure $callback = null,
        ?Model $model = null
    ) {
        parent::__construct($model);
    }

    /**
     * Get the configuration for the BelongsTo relationship.
     * 
     * @return array{
     *     related: string,
     *     foreignKey: string|null,
     *     ownerKey: string|null,
     *     lazy: bool,
     *     callback: Closure|null
     * }
     */
    public function getConfig(): array
    {
        return [
            'related' => $this->related,
            'foreignKey' => $this->foreignKey,
            'ownerKey' => $this->ownerKey,
            'lazy' => $this->lazy,
            'callback' => $this->callback,
        ];
    }
}