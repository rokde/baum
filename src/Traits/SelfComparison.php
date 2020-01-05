<?php

namespace Baum\Traits;

trait SelfComparison
{
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
}