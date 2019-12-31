<?php

namespace Baum\Traits;

use Baum\Node;

trait NodeComparisonTrait
{
	/**
	 * Returns true if node is a descendant.
	 *
	 * @param \Baum\Node $other
	 * @return bool
	 */
	public function isDescendantOf(Node $other): bool
	{
		return
			$this->getLeft() > $other->getLeft() &&
			$this->getLeft() < $other->getRight() &&
			$this->inSameScope($other);
	}

	/**
	 * Returns true if node is self or a descendant.
	 *
	 * @param \Baum\Node $other
	 * @return bool
	 */
	public function isSelfOrDescendantOf(Node $other): bool
	{
		return
			$this->getLeft() >= $other->getLeft() &&
			$this->getLeft() < $other->getRight() &&
			$this->inSameScope($other);
	}

	/**
	 * Returns true if node is an ancestor.
	 *
	 * @param \Baum\Node $other
	 * @return bool
	 */
	public function isAncestorOf(Node $other): bool
	{
		return
			$this->getLeft() < $other->getLeft() &&
			$this->getRight() > $other->getLeft() &&
			$this->inSameScope($other);
	}

	/**
	 * Returns true if node is self or an ancestor.
	 *
	 * @param \Baum\Node $other
	 * @return bool
	 */
	public function isSelfOrAncestorOf(Node $other): bool
	{
		return
			$this->getLeft() <= $other->getLeft() &&
			$this->getRight() > $other->getLeft() &&
			$this->inSameScope($other);
	}

	/**
	 * Equals?
	 *
	 * @param \Baum\Node $node
	 *
	 * @return bool
	 */
	public function equals(Node $node): bool
	{
		return $this == $node;
	}

	/**
	 * Checks if the given node is in the same scope as the current one.
	 *
	 * @param Node $other
	 *
	 * @return bool
	 */
	public function inSameScope(Node $other): bool
	{
		foreach ($this->getScopedColumns() as $fld) {
			if ($this->$fld != $other->$fld) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks wether the given node is a descendant of itself. Basically, whether
	 * its in the subtree defined by the left and right indices.
	 *
	 * @param \Baum\Node $node
	 * @return bool
	 */
	public function insideSubtree(Node $node): bool
	{
		return
			$this->getLeft() >= $node->getLeft() &&
			$this->getLeft() <= $node->getRight() &&
			$this->getRight() >= $node->getLeft() &&
			$this->getRight() <= $node->getRight();
	}
}