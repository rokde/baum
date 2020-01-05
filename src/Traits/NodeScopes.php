<?php

namespace Baum\Traits;

use Baum\Node;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait NodeScopes
 * @package Baum\Traits
 *
 * @method Builder|Node scoped
 * @method Builder|Node roots
 * @method Builder allLeaves
 * @method Builder|Node leaves
 * @method Builder allTrunks
 * @method Builder|Node trunks
 * @method Builder|Node withoutNode(Node $node)
 * @method Builder|Node withoutSelf
 * @method Builder|Node withoutRoot
 * @method Builder|Node limitDepth(int $depth)
 * @method Builder|Node ancestorsAndSelf
 * @method Builder|Node ancestors
 * @method Builder|Node siblingsAndSelf
 * @method Builder|Node siblings
 * @method Builder|Node descendantsAndSelf
 * @method Builder|Node descendants
 */
trait NodeScopes
{
    /**
     * Adds a scope to the query when set in the node model
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeScoped(Builder $query): Builder
    {
        foreach ($this->scoped as $scopeField) {
            $query->where($scopeField, $this->$scopeField);
        }

        return $query;
    }

    /**
     * Static query scope. Returns a query scope with all root nodes.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query
            ->whereNull($this->getParentColumnName())
            ->orderBy($this->getQualifiedOrderColumnName());
    }

    /**
     * Static query scope. Returns a query scope with all nodes which are at
     * the end of a branch.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAllLeaves(Builder $query): Builder
    {
        /** @var \Illuminate\Database\Query\Grammars\Grammar $grammar */
        $grammar = $this->getConnection()->getQueryGrammar();

        $rightColumnName = $grammar->wrap($this->getQualifiedRightColumnName());
        $leftColumnName = $grammar->wrap($this->getQualifiedLeftColumnName());

        return $query
            ->whereRaw($rightColumnName . ' - ' . $leftColumnName . ' = 1')
            ->orderBy($this->getQualifiedOrderColumnName());
    }

    /**
     * Static query scope. Returns a query scope with all nodes which are at
     * the end of a branch.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeLeaves(Builder $query): Builder
    {
        return $query->allLeaves()
            ->descendants();
    }

    /**
     * Static query scope. Returns a query scope with all nodes which are at
     * the middle of a branch (not root and not leaves).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAllTrunks(Builder $query): Builder
    {
        /** @var \Illuminate\Database\Query\Grammars\Grammar $grammar */
        $grammar = $this->getConnection()->getQueryGrammar();

        $rightColumnName = $grammar->wrap($this->getQualifiedRightColumnName());
        $leftColumnName = $grammar->wrap($this->getQualifiedLeftColumnName());

        return $query
            ->whereNotNull($this->getParentColumnName())
            ->whereRaw($rightColumnName . ' - ' . $leftColumnName . ' != 1')
            ->orderBy($this->getQualifiedOrderColumnName());
    }

    /**
     * Static query scope. Returns a query scope with all nodes which are at
     * the middle of a branch (not root and not leaves).
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTrunks(Builder $query): Builder
    {
        return $query
            ->allTrunks()
            ->descendants();
    }

    /**
     * Query scope which extracts a certain node object from the current query
     * expression.
     *
     * @param Builder $query
     * @param Node $node
     * @return Builder
     * @throws \InvalidArgumentException
     */
    public function scopeWithoutNode(Builder $query, Node $node): Builder
    {
        return $query->scoped()->where($node->getKeyName(), '!=', $node->getKey());
    }

    /**
     * Extracts current node (self) from current query expression.
     *
     * @param Builder $query
     * @return Builder
     * @throws \InvalidArgumentException
     */
    public function scopeWithoutSelf(Builder $query): Builder
    {
        return $this->scopeWithoutNode($query, $this);
    }

    /**
     * Extracts first root (from the current node p-o-v) from current query
     * expression.
     *
     * @param Builder $query
     * @return Builder
     * @throws \InvalidArgumentException
     */
    public function scopeWithoutRoot(Builder $query): Builder
    {
        return $this->scopeWithoutNode($query, $this->getRoot());
    }

    /**
     * Provides a depth level limit for the query.
     *
     * @param Builder $query
     * @param int $limit
     * @return Builder
     */
    public function scopeLimitDepth(Builder $query, int $limit): Builder
    {
        $depth = $this->exists ? $this->getDepth() : $this->getLevel();
        $max = $depth + $limit;
        $scopes = [$depth, $max];

        return $query->whereBetween($this->getDepthColumnName(), [min($scopes), max($scopes)]);
    }

    /**
     * Instance scope which targes all the ancestor chain nodes including
     * the current one.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAncestorsAndSelf(Builder $query): Builder
    {
        return $query
            ->where($this->getLeftColumnName(), '<=', $this->getLeft())
            ->where($this->getRightColumnName(), '>=', $this->getRight())
            ->orderBy($this->getQualifiedOrderColumnName());
    }

    /**
     * Instance scope which targets all the ancestor chain nodes excluding
     * the current one.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeAncestors(Builder $query): Builder
    {
        return $query->ancestorsAndSelf()->withoutSelf();
    }

    /**
     * Instance scope which targets all children of the parent, including self.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSiblingsAndSelf(Builder $query): Builder
    {
        return $query->where($this->getParentColumnName(), $this->getParentId());
    }

    /**
     * Instance scope targeting all children of the parent, except self.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeSiblings(Builder $query): Builder
    {
        return $query->siblingsAndSelf()->withoutSelf();
    }

    /**
     * Scope targeting itself and all of its nested children.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDescendantsAndSelf(Builder $query): Builder
    {
        return $query
            ->where($this->getLeftColumnName(), '>=', $this->getLeft())
            ->where($this->getLeftColumnName(), '<', $this->getRight());
    }

    /**
     * Set of all children & nested children.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDescendants(Builder $query): Builder
    {
        return $query->descendantsAndSelf()->withoutSelf();
    }
}
