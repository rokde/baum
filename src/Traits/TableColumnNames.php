<?php

namespace Baum\Traits;

/**
 * Trait TableColumnNames
 * @package Baum\Traits
 * @property string $parentColumn
 * @property string $leftColumn
 * @property string $rightColumn
 * @property string $depthColumn
 * @property string|null $orderColumn
 * @property array $scoped
 */
trait TableColumnNames
{
	/**
	 * Get the parent column name.
	 *
	 * @return string
	 */
	public function getParentColumnName(): string
	{
		return $this->parentColumn;
	}

	/**
	 * Get the table qualified parent column name.
	 *
	 * @return string
	 */
	public function getQualifiedParentColumnName(): string
	{
		return $this->getTable() . '.' . $this->getParentColumnName();
	}

	/**
	 * Get the "left" field column name.
	 *
	 * @return string
	 */
	public function getLeftColumnName(): string
	{
		return $this->leftColumn;
	}

	/**
	 * Get the table qualified "left" field column name.
	 *
	 * @return string
	 */
	public function getQualifiedLeftColumnName(): string
	{
		return $this->getTable() . '.' . $this->getLeftColumnName();
	}

	/**
	 * Get the "right" field column name.
	 *
	 * @return string
	 */
	public function getRightColumnName(): string
	{
		return $this->rightColumn;
	}

	/**
	 * Get the table qualified "right" field column name.
	 *
	 * @return string
	 */
	public function getQualifiedRightColumnName(): string
	{
		return $this->getTable() . '.' . $this->getRightColumnName();
	}

	/**
	 * Get the "depth" field column name.
	 *
	 * @return string
	 */
	public function getDepthColumnName(): string
	{
		return $this->depthColumn;
	}

	/**
	 * Get the table qualified "depth" field column name.
	 *
	 * @return string
	 */
	public function getQualifiedDepthColumnName(): string
	{
		return $this->getTable() . '.' . $this->getDepthColumnName();
	}

	/**
	 * Get the "order" field column name.
	 *
	 * @return string
	 */
	public function getOrderColumnName(): string
	{
		return $this->orderColumn ?: $this->getLeftColumnName();
	}

	/**
	 * Get the table qualified "order" field column name.
	 *
	 * @return string
	 */
	public function getQualifiedOrderColumnName(): string
	{
		return $this->getTable() . '.' . $this->getOrderColumnName();
	}

	/**
	 * Get the column names which define our scope.
	 *
	 * @return array
	 */
	public function getScopedColumns(): array
	{
		return $this->scoped;
	}

	/**
	 * Get the qualified column names which define our scope.
	 *
	 * @return array
	 */
	public function getQualifiedScopedColumns(): array
	{
		if (!$this->isScoped()) {
			return $this->getScopedColumns();
		}

		$prefix = $this->getTable() . '.';

		return array_map(function ($c) use ($prefix) {
			return $prefix . $c;
		}, $this->getScopedColumns());
	}

	/**
	 * Returns whether this particular node instance is scoped by certain fields
	 * or not.
	 *
	 * @return bool
	 */
	public function isScoped(): bool
	{
		return count($this->getScopedColumns()) > 0;
	}
}