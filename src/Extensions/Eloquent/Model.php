<?php

namespace Baum\Extensions\Eloquent;

use Baum\Extensions\Query\Builder as QueryBuilder;
use Baum\Node;
use Illuminate\Database\Eloquent\Model as BaseModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;

abstract class Model extends BaseModel
{
    /**
     * Reloads the model from the database.
     *
     * @return \Baum\Node
     * @throws ModelNotFoundException
     *
     */
    public function reload(): Node
    {
        if ($this->exists || ($this->areSoftDeletesEnabled() && $this->trashed())) {
            $fresh = $this->getFreshInstance();

            if (is_null($fresh)) {
                throw with(new ModelNotFoundException())->setModel(get_called_class());
            }

            $this->setRawAttributes($fresh->getAttributes(), true);

            $this->setRelations($fresh->getRelations());

            $this->exists = $fresh->exists;
        } else {
            // Revert changes if model is not persisted
            $this->attributes = $this->original;
        }

        return $this;
    }

    /**
     * Get the observable event names.
     *
     * @return array
     */
    public function getObservableEvents()
    {
        return array_merge(['moving', 'moved'], parent::getObservableEvents());
    }

    /**
     * Register a moving model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function moving($callback)
    {
        static::registerModelEvent('moving', $callback);
    }

    /**
     * Register a moved model event with the dispatcher.
     *
     * @param \Closure|string $callback
     * @return void
     */
    public static function moved($callback)
    {
        static::registerModelEvent('moved', $callback);
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Baum\Extensions\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();

        $grammar = $conn->getQueryGrammar();

        return new QueryBuilder($conn, $grammar, $conn->getPostProcessor());
    }

    /**
     * Returns a fresh instance from the database.
     *
     * @return \Baum\Node
     */
    protected function getFreshInstance()
    {
        if ($this->areSoftDeletesEnabled()) {
            return static::withTrashed()->find($this->getKey());
        }

        return static::find($this->getKey());
    }

    /**
     * Returns whether soft delete functionality is enabled on the model or not.
     */
    public function areSoftDeletesEnabled(): bool
    {
        // To determine if there's a global soft delete scope defined we must
        // first determine if there are any, to workaround a non-existent key error.
        $globalScopes = $this->getGlobalScopes();

        if (count($globalScopes) === 0) {
            return false;
        }

        // Now that we're sure that the calling class has some kind of global scope
        // we check for the SoftDeletingScope existence
        return static::hasGlobalScope(new SoftDeletingScope());
    }

    /**
     * Static method which returns wether soft delete functionality is enabled
     * on the model.
     */
    public static function softDeletesEnabled(): bool
    {
        return with(new static())->areSoftDeletesEnabled();
    }
}
