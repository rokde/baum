<?php

namespace Baum;

use Baum\Extensions\Eloquent\Collection;
use Baum\Traits\NodeComparison;
use Baum\Traits\TableColumnNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Node.
 *
 * This abstract class implements Nested Set functionality. A Nested Set is a
 * smart way to implement an ordered tree with the added benefit that you can
 * select all of their descendants with a single query. Drawbacks are that
 * insertion or move operations need more complex sql queries.
 *
 * Nested sets are appropriate when you want either an ordered tree (menus,
 * commercial categories, etc.) or an efficient way of querying big trees.
 *
 * @property-read Node|null $parent
 * @property-read Node[]|array $children
 * @property-read Node[]|array $immediateDescendants
 * @method Builder|Node roots
 * @method Builder|Node leaves
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
abstract class Node extends \Baum\Extensions\Eloquent\Model
{
	use NodeComparison,
		TableColumnNames;

	/**
	 * Column name to store the reference to parent's node.
	 *
	 * @var string
	 */
	protected $parentColumn = 'parent_id';

	/**
	 * Column name for left index.
	 *
	 * @var string
	 */
	protected $leftColumn = 'lft';

	/**
	 * Column name for right index.
	 *
	 * @var string
	 */
	protected $rightColumn = 'rgt';

	/**
	 * Column name for depth field.
	 *
	 * @var string
	 */
	protected $depthColumn = 'depth';

	/**
	 * Column to perform the default sorting.
	 *
	 * @var string|null
	 */
	protected $orderColumn = null;

	/**
	 * Guard NestedSet fields from mass-assignment.
	 *
	 * @var array
	 */
	protected $guarded = ['id', 'parent_id', 'lft', 'rgt', 'depth'];

	/**
	 * Indicates whether we should move to a new parent.
	 *
	 * @var int|null
	 */
	protected static $moveToNewParentId = null;

	/**
	 * Columns which restrict what we consider our Nested Set list.
	 *
	 * @var array
	 */
	protected $scoped = [];

	/**
	 * The "booting" method of the model.
	 *
	 * We'll use this method to register event listeners on a Node instance as
	 * suggested in the beta documentation...
	 *
	 * TODO:
	 *
	 *    - Find a way to avoid needing to declare the called methods "public"
	 *    as registering the event listeners *inside* this methods does not give
	 *    us an object context.
	 *
	 * Events:
	 *
	 *    1. "creating": Before creating a new Node we'll assign a default value
	 *    for the left and right indexes.
	 *
	 *    2. "saving": Before saving, we'll perform a check to see if we have to
	 *    move to another parent.
	 *
	 *    3. "saved": Move to the new parent after saving if needed and re-set
	 *    depth.
	 *
	 *    4. "deleting": Before delete we should prune all children and update
	 *    the left and right indexes for the remaining nodes.
	 *
	 *    5. (optional) "restoring": Before a soft-delete node restore operation,
	 *    shift its siblings.
	 *
	 *    6. (optional) "restore": After having restored a soft-deleted node,
	 *    restore all of its descendants.
	 *
	 * @return void
	 * @throws \Throwable
	 * @throws \Exception
	 */
	protected static function boot()
	{
		parent::boot();

		static::creating(function (Node $node) {
			$node->setDefaultLeftAndRight();
		});

		static::saving(function (Node $node) {
			$node->storeNewParent();
		});

		static::saved(function (Node $node) {
			$node->getConnection()->beginTransaction();
			$node->moveToNewParent();
			$node->setDepth();
		});

		static::deleting(function (Node $node) {
			$node->getConnection()->beginTransaction();
			$node->destroyDescendants();
		});

		if (static::softDeletesEnabled()) {
			static::restoring(function (Node $node) {
				$node->shiftSiblingsForRestore();
			});

			static::restored(function (Node $node) {
				$node->restoreDescendants();
			});
		}
	}

	/**
	 * Finish processing on a successful save operation.
	 *
	 * @param array $options
	 * @return void
	 */
	public function finishSave(array $options)
	{
		parent::finishSave($options);

		if ($this->getConnection()->transactionLevel() > 0) {
			$this->getConnection()->commit();
		}
	}

	/**
	 * Delete the model from the database.
	 *
	 * @return bool|null
	 *
	 * @throws \Exception
	 */
	public function delete()
	{
		$return = parent::delete();

		/**
		 * Fixes a bug that destroys the nested set when multiple create or delete operations are running at the
		 * same time. Operations that require to rebuild the tree. Before only the rebuilding of the tree was inside
		 * a transaction. The node is deleted, the rebuild in progress... Then a second node is deleted and the
		 * second rebuild stops because of table locks. After the tree is broken.
		 *
		 * This early transaction should prevent of this case.
		 *
		 * Only commit if transaction was started
		 * @link https://github.com/laravel/framework/issues/12382
		 */
		if ($this->getConnection()->transactionLevel() > 0) {
			$this->getConnection()->commit();
		}

		return $return;
	}

	/**
	 * Get the value of the models "parent_id" field.
	 *
	 * @return int|null
	 */
	public function getParentId(): ?int
	{
		return $this->getAttribute($this->getParentColumnName());
	}

	/**
	 * Get the value of the model's "left" field.
	 *
	 * @return int
	 */
	public function getLeft(): int
	{
		return $this->getAttribute($this->getLeftColumnName()) + 0;
	}

	/**
	 * Get the value of the model's "right" field.
	 *
	 * @return int
	 */
	public function getRight(): int
	{
		return $this->getAttribute($this->getRightColumnName()) + 0;
	}

	/**
	 * Get the model's "depth" value.
	 *
	 * @return int
	 */
	public function getDepth(): int
	{
		return $this->getAttribute($this->getDepthColumnName()) + 0;
	}

	/**
	 * Get the model's "order" value.
	 *
	 * @return mixed
	 */
	public function getOrder()
	{
		return $this->getAttribute($this->getOrderColumnName());
	}

	/**
	 * Parent relation (self-referential) 1-1.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function parent(): BelongsTo
	{
		return $this->belongsTo(get_class($this), $this->getParentColumnName());
	}

	/**
	 * Children relation (self-referential) 1-N.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function children(): HasMany
	{
		return $this->hasMany(get_class($this), $this->getParentColumnName())
			->orderBy($this->getOrderColumnName());
	}

	/**
	 * Get a new "scoped" query builder for the Node's model.
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function newNestedSetQuery(): Builder
	{
		/** @var Builder $builder */
		$builder = $this->newQuery()->orderBy($this->getQualifiedOrderColumnName());

		if ($this->isScoped()) {
			foreach ($this->scoped as $scopeField) {
				$builder->where($scopeField, $this->$scopeField);
			}
		}

		return $builder;
	}

	/**
	 * Overload new Collection.
	 *
	 * @param array|Node[] $models
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|Node[]
	 */
	public function newCollection(array $models = []): Collection
	{
		return new \Baum\Extensions\Eloquent\Collection($models);
	}

	/**
	 * Get all of the nodes from the database.
	 *
	 * @param array $columns
	 *
	 * @return \Illuminate\Database\Eloquent\Collection|Node[]
	 */
	public static function all($columns = ['*'])
	{
		$instance = new static();

		return $instance->newQuery()
			->orderBy($instance->getQualifiedOrderColumnName())
			->get($columns);
	}

	/**
	 * Returns the first root node.
	 *
	 * @return Node|null
	 */
	public static function root(): ?self
	{
		return static::roots()->first();
	}

	/**
	 * Static query scope. Returns a query scope with all root nodes.
	 *
	 * @param Builder $query
	 * @return Builder
	 */
	public static function scopeRoots(Builder $query): Builder
	{
		return $query
			->whereNull(with(new static())->getParentColumnName())
			->orderBy(with(new static())->getQualifiedOrderColumnName());
	}

	/**
	 * Static query scope. Returns a query scope with all nodes which are at
	 * the end of a branch.
	 *
	 * @param Builder $query
	 * @return Builder
	 */
	public static function scopeLeaves(Builder $query): Builder
	{
		$instance = new static();

		$grammar = $instance->getConnection()->getQueryGrammar();

		$rgtCol = $grammar->wrap($instance->getQualifiedRightColumnName());
		$lftCol = $grammar->wrap($instance->getQualifiedLeftColumnName());

		return $query
			->whereRaw($rgtCol . ' - ' . $lftCol . ' = 1')
			->orderBy($instance->getQualifiedOrderColumnName());
	}

	/**
	 * Static query scope. Returns a query scope with all nodes which are at
	 * the middle of a branch (not root and not leaves).
	 *
	 * @param Builder $query
	 * @return Builder
	 */
	public static function scopeTrunks(Builder $query): Builder
	{
		$instance = new static();

		$grammar = $instance->getConnection()->getQueryGrammar();

		$rgtCol = $grammar->wrap($instance->getQualifiedRightColumnName());
		$lftCol = $grammar->wrap($instance->getQualifiedLeftColumnName());

		return $query
			->whereNotNull($instance->getParentColumnName())
			->whereRaw($rgtCol . ' - ' . $lftCol . ' != 1')
			->orderBy($instance->getQualifiedOrderColumnName());
	}

	/**
	 * Checks whether the underlying Nested Set structure is valid.
	 *
	 * @return bool
	 */
	public static function isValidNestedSet(): bool
	{
		return with(new SetValidator(new static()))->passes();
	}

	/**
	 * Rebuilds the structure of the current Nested Set.
	 *
	 * @return void
	 */
	public static function rebuild(): void
	{
		with(new SetBuilder(new static()))->rebuild();
	}

	/**
	 * Maps the provided tree structure into the database.
	 *
	 * @param array|\Baum\Node[]|\Illuminate\Contracts\Support\Arrayable
	 * @return bool
	 */
	public static function buildTree($nodeList): bool
	{
		return with(new static())->makeTree($nodeList);
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
		return $query->where($node->getKeyName(), '!=', $node->getKey());
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
	 * Returns true if this is a root node.
	 *
	 * @return bool
	 */
	public function isRoot(): bool
	{
		return null === $this->getParentId();
	}

	/**
	 * Returns true if this is a leaf node (end of a branch).
	 *
	 * @return bool
	 */
	public function isLeaf(): bool
	{
		return $this->exists && ($this->getRight() - $this->getLeft() === 1);
	}

	/**
	 * Returns true if this is a trunk node (not root or leaf).
	 *
	 * @return bool
	 */
	public function isTrunk(): bool
	{
		return !$this->isRoot() && !$this->isLeaf();
	}

	/**
	 * Returns true if this is a child node.
	 *
	 * @return bool
	 */
	public function isChild(): bool
	{
		return !$this->isRoot();
	}

	/**
	 * Returns the root node starting at the current node.
	 *
	 * @return Node|null
	 */
	public function getRoot(): ?self
	{
		if ($this->exists) {
			return $this->ancestorsAndSelf()
				->whereNull($this->getParentColumnName())
				->first();
		}

		$parentId = $this->getParentId();

		if (
			null !== $parentId &&
			$currentParent = static::find($parentId)
		) {
			return $currentParent->getRoot();
		}

		return $this;
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
			->where($this->getRightColumnName(), '>=', $this->getRight());
	}

	/**
	 * Get all the ancestor chain from the database including the current node.
	 *
	 * @param array $columns
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getAncestorsAndSelf(array $columns = ['*']): Collection
	{
		return $this->ancestorsAndSelf()->get($columns);
	}

	/**
	 * Get all the ancestor chain from the database including the current node
	 * but without the root node.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getAncestorsAndSelfWithoutRoot($columns = ['*']): Collection
	{
		return $this->ancestorsAndSelf()->withoutRoot()->get($columns);
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
	 * Get all the ancestor chain from the database excluding the current node.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getAncestors($columns = ['*']): Collection
	{
		return $this->ancestors()->get($columns);
	}

	/**
	 * Get all the ancestor chain from the database excluding the current node
	 * and the root node (from the current node's perspective).
	 *
	 * @param array $columns
	 *
	 * @return \Illuminate\Database\Eloquent\Collection|\Baum\Node[]
	 */
	public function getAncestorsWithoutRoot($columns = ['*']): Collection
	{
		return $this->ancestors()->withoutRoot()->get($columns);
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
	 * Get all children of the parent, including self.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getSiblingsAndSelf($columns = ['*']): Collection
	{
		return $this->siblingsAndSelf()->get($columns);
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
	 * Return all children of the parent, except self.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getSiblings($columns = ['*']): Collection
	{
		return $this->siblings()->get($columns);
	}

	/**
	 * Return all of its nested children which do not have children.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getLeaves($columns = ['*']): Collection
	{
		return $this->leaves()->get($columns);
	}

	/**
	 * Return all of its nested children which are trunks.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getTrunks($columns = ['*']): Collection
	{
		return $this->trunks()->get($columns);
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
	 * Retrieve all nested children an self.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getDescendantsAndSelf($columns = ['*']): Collection
	{
		if (is_array($columns)) {
			return $this->descendantsAndSelf()->get($columns);
		}

		$arguments = func_get_args();
		$limit = intval(array_shift($arguments));
		$columns = array_shift($arguments) ?: ['*'];

		return $this->descendantsAndSelf()->limitDepth($limit)->get($columns);
	}

	/**
	 * Retrieve all other nodes at the same depth,.
	 *
	 * @return Builder|\Baum\Node[]
	 */
	public function getOthersAtSameDepth(): Builder
	{
		return $this->newNestedSetQuery()
			->where($this->getDepthColumnName(), '=', $this->getDepth())
			->withoutSelf();
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

	/**
	 * Retrieve all of its children & nested children.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getDescendants($columns = ['*']): Collection
	{
		if (is_array($columns)) {
			return $this->descendants()->get($columns);
		}

		$arguments = func_get_args();

		$limit = intval(array_shift($arguments));
		$columns = array_shift($arguments) ?: ['*'];

		return $this->descendants()->limitDepth($limit)->get($columns);
	}

	/**
	 * Set of "immediate" descendants (aka children), alias for the children relation.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function immediateDescendants(): HasMany
	{
		return $this->children();
	}

	/**
	 * Retrive all of its "immediate" descendants.
	 *
	 * @param array $columns
	 *
	 * @return \Baum\Extensions\Eloquent\Collection|\Baum\Node[]
	 */
	public function getImmediateDescendants($columns = ['*']): Collection
	{
		return $this->children()->get($columns);
	}

	/**
	 * Returns the level of this node in the tree.
	 * Root level is 0.
	 *
	 * @return int
	 */
	public function getLevel(): int
	{
		if (null === $this->getParentId()) {
			return 0;
		}

		return $this->computeLevel();
	}

	/**
	 * Returns the first sibling to the left.
	 *
	 * @return Node
	 */
	public function getLeftSibling(): Node
	{
		return $this->siblings()
			->where($this->getLeftColumnName(), '<', $this->getLeft())
			->orderBy($this->getOrderColumnName(), 'desc')
			->get()
			->last();
	}

	/**
	 * Returns the first sibling to the right.
	 *
	 * @return Node
	 */
	public function getRightSibling(): Node
	{
		return $this->siblings()
			->where($this->getLeftColumnName(), '>', $this->getLeft())
			->first();
	}

	/**
	 * Find the left sibling and move to left of it.
	 *
	 * @return Node
	 */
	public function moveLeft(): Node
	{
		return $this->moveToLeftOf($this->getLeftSibling());
	}

	/**
	 * Find the right sibling and move to the right of it.
	 *
	 * @return Node
	 */
	public function moveRight(): Node
	{
		return $this->moveToRightOf($this->getRightSibling());
	}

	/**
	 * Move to the node to the left of ...
	 *
	 * @param Node|int $node
	 * @return Node
	 */
	public function moveToLeftOf($node): Node
	{
		return $this->moveTo($node, 'left');
	}

	/**
	 * Move to the node to the right of ...
	 *
	 * @param Node|int $node
	 * @return Node
	 */
	public function moveToRightOf($node): Node
	{
		return $this->moveTo($node, 'right');
	}

	/**
	 * Alias for moveToRightOf.
	 *
	 * @param Node|int $node
	 * @return Node
	 */
	public function makeNextSiblingOf($node): Node
	{
		return $this->moveToRightOf($node);
	}

	/**
	 * Alias for moveToRightOf.
	 *
	 * @param Node|int $node
	 * @return Node
	 */
	public function makeSiblingOf($node): Node
	{
		return $this->moveToRightOf($node);
	}

	/**
	 * Alias for moveToLeftOf.
	 *
	 * @param Node|int $node
	 * @return Node
	 */
	public function makePreviousSiblingOf($node): Node
	{
		return $this->moveToLeftOf($node);
	}

	/**
	 * Make the node a child of ...
	 *
	 * @param Node|int $node
	 * @return Node
	 */
	public function makeChildOf($node): Node
	{
		return $this->moveTo($node, 'child');
	}

	/**
	 * Make the node the first child of ...
	 *
	 * @param Node $node
	 * @return Node
	 */
	public function makeFirstChildOf(Node $node): Node
	{
		if ($node->children()->count() == 0) {
			return $this->makeChildOf($node);
		}

		return $this->moveToLeftOf($node->children()->first());
	}

	/**
	 * Make the node the last child of ...
	 *
	 * @param Node $node
	 * @return Node
	 */
	public function makeLastChildOf(Node $node): Node
	{
		return $this->makeChildOf($node);
	}

	/**
	 * Make current node a root node.
	 *
	 * @return Node
	 */
	public function makeRoot(): Node
	{
		return $this->moveTo($this, 'root');
	}

	/**
	 * Sets default values for left and right fields.
	 *
	 * @return void
	 */
	public function setDefaultLeftAndRight(): void
	{
		/**
		 * @var Node $withHighestRight
		 */
		$withHighestRight = $this->newNestedSetQuery()
			->reOrderBy($this->getRightColumnName(), 'desc')
			->take(1)
			->sharedLock()
			->first();

		$maxRgt = 0;
		if (null !== $withHighestRight) {
			$maxRgt = $withHighestRight->getRight();
		}

		$this->setAttribute($this->getLeftColumnName(), $maxRgt + 1);
		$this->setAttribute($this->getRightColumnName(), $maxRgt + 2);
	}

	/**
	 * Store the parent_id if the attribute is modified so as we are able to move
	 * the node to this new parent after saving.
	 *
	 * @return void
	 */
	public function storeNewParent(): void
	{
		static::$moveToNewParentId = false;
		if ((
				$this->exists ||
				!$this->isRoot()
			) &&
			$this->isDirty($this->getParentColumnName())
		) {
			static::$moveToNewParentId = $this->getParentId();
		}
	}

	/**
	 * Move to the new parent if appropiate.
	 *
	 * @return void
	 */
	public function moveToNewParent(): void
	{
		$pid = static::$moveToNewParentId;

		if (null === $pid) {
			$this->makeRoot();
		} elseif ($pid !== false) {
			$this->makeChildOf($pid);
		}
	}

	/**
	 * Sets the depth attribute.
	 *
	 * @return \Baum\Node
	 * @throws \Throwable
	 */
	public function setDepth(): self
	{
		$self = $this;

		$this->getConnection()->transaction(function () use ($self) {
			$self->reload();
			$level = $self->getLevel();
			$self->newNestedSetQuery()
				->where($self->getKeyName(), $self->getKey())
				->update([$self->getDepthColumnName() => $level]);

			$self->setAttribute($self->getDepthColumnName(), $level);
		});

		return $this;
	}

	/**
	 * Sets the depth attribute for the current node and all of its descendants.
	 *
	 * @return \Baum\Node
	 * @throws \Throwable
	 */
	public function setDepthWithSubtree(): self
	{
		$self = $this;

		$this->getConnection()->transaction(function () use ($self) {
			$self->reload();

			$self->descendantsAndSelf()->select($self->getKeyName())->lockForUpdate()->get();

			$oldDepth = $self->getDepth() ?: 0;
			$newDepth = $self->getLevel();

			$self->newNestedSetQuery()->where($self->getKeyName(), '=',
				$self->getKey())->update([$self->getDepthColumnName() => $newDepth]);
			$self->setAttribute($self->getDepthColumnName(), $newDepth);

			$diff = $newDepth - $oldDepth;
			if ($diff != 0 && !$self->isLeaf()) {
				$self->descendants()->increment($self->getDepthColumnName(), $diff);
			}
		});

		return $this;
	}

	/**
	 * Prunes a branch off the tree, shifting all the elements on the right
	 * back to the left so the counts work.
	 *
	 * @return void;
	 * @throws \Throwable
	 */
	public function destroyDescendants(): void
	{
		if (
			null === $this->getRight() ||
			null === $this->getLeft()
		) {
			return;
		}

		$self = $this;

		$this->getConnection()->transaction(function () use ($self) {
			$self->reload();

			$lftCol = $self->getLeftColumnName();
			$rgtCol = $self->getRightColumnName();
			$lft = $self->getLeft();
			$rgt = $self->getRight();

			// Apply a lock to the rows which fall past the deletion point
			$self->newNestedSetQuery()->where($lftCol, '>=', $lft)->select($self->getKeyName())->lockForUpdate()->get();

			// Prune children
			$self->newNestedSetQuery()->where($lftCol, '>', $lft)->where($rgtCol, '<', $rgt)->delete();

			// Update left and right indexes for the remaining nodes
			$diff = $rgt - $lft + 1;

			$self->newNestedSetQuery()->where($lftCol, '>', $rgt)->decrement($lftCol, $diff);
			$self->newNestedSetQuery()->where($rgtCol, '>', $rgt)->decrement($rgtCol, $diff);
		});
	}

	/**
	 * "Makes room" for the the current node between its siblings.
	 *
	 * @return void
	 * @throws \Throwable
	 */
	public function shiftSiblingsForRestore(): void
	{
		if (
			null === $this->getRight() ||
			null === $this->getLeft()
		) {
			return;
		}

		$self = $this;

		$this->getConnection()->transaction(function () use ($self) {
			$lftCol = $self->getLeftColumnName();
			$rgtCol = $self->getRightColumnName();
			$lft = $self->getLeft();
			$rgt = $self->getRight();

			$diff = $rgt - $lft + 1;

			$self->newNestedSetQuery()->where($lftCol, '>=', $lft)->increment($lftCol, $diff);
			$self->newNestedSetQuery()->where($rgtCol, '>=', $lft)->increment($rgtCol, $diff);
		});
	}

	/**
	 * Restores all of the current node's descendants.
	 * Only when using SoftDeletes trait
	 *
	 * @return void
	 * @throws \Throwable
	 */
	public function restoreDescendants(): void
	{
		if (
			null === $this->getRight() ||
			null === $this->getLeft()
		) {
			return;
		}

		$self = $this;

		$this->getConnection()->transaction(function () use ($self) {
			$self->newNestedSetQuery()
				->withTrashed()
				->where($self->getLeftColumnName(), '>', $self->getLeft())
				->where($self->getRightColumnName(), '<', $self->getRight())
				->update([
					$self->getDeletedAtColumn() => null,
					$self->getUpdatedAtColumn() => $self->{$self->getUpdatedAtColumn()},
				]);
		});
	}

	/**
	 * Return an key-value array indicating the node's depth with $seperator.
	 *
	 * @param string $column
	 * @param string|null $key
	 * @param string $separator
	 * @param string $symbol
	 * @return array
	 */
	public static function getNestedList(
		string $column,
		string $key = null,
		string $separator = ' ',
		string $symbol = ''
	): array {
		$instance = new static();

		$key = $key ?: $instance->getKeyName();
		$depthColumn = $instance->getDepthColumnName();

		$nodes = $instance->newNestedSetQuery()->get()->toArray();

		return array_combine(array_map(function ($node) use ($key) {
			return $node[$key];
		}, $nodes), array_map(function ($node) use ($separator, $depthColumn, $column, $symbol) {
			return str_repeat($separator, $node[$depthColumn]) . $symbol . $node[$column];
		}, $nodes));
	}

	/**
	 * Maps the provided tree structure into the database using the current node
	 * as the parent. The provided tree structure will be inserted/updated as the
	 * descendancy subtree of the current node instance.
	 *
	 * @param array|\Illuminate\Contracts\Support\Arrayable
	 * @return bool
	 */
	public function makeTree($nodeList)
	{
		$mapper = new SetMapper($this);

		return $mapper->map($nodeList);
	}

	/**
	 * Main move method. Here we handle all node movements with the corresponding
	 * lft/rgt index updates.
	 *
	 * @param Node|int $target
	 * @param string $position
	 *
	 * @return Node
	 * @throws \Throwable
	 */
	protected function moveTo($target, string $position): Node
	{
		return Move::to($this, $target, $position);
	}

	/**
	 * Compute current node level. If could not move past ourselves return
	 * our ancestor count, otherwise get the first parent level + the computed
	 * nesting.
	 *
	 * @return int
	 */
	protected function computeLevel(): int
	{
		[$node, $nesting] = $this->determineDepth($this);

		if ($node->equals($this)) {
			return $this->ancestors()->count();
		}

		return $node->getLevel() + $nesting;
	}

	/**
	 * Return an array with the last node we could reach and its nesting level.
	 *
	 * @param Node $node
	 * @param int $nesting
	 *
	 * @return array[Node, int]
	 */
	protected function determineDepth($node, $nesting = 0): array
	{
		// Traverse back up the ancestry chain and add to the nesting level count
		while ($parent = $node->parent()->first()) {
			$nesting++;
			$node = $parent;
		}

		return [$node, $nesting];
	}
}
