<?php

namespace Baum\Extensions\Eloquent;

use Baum\Node;
use Illuminate\Database\Eloquent\Collection as BaseCollection;

/**
 * Class Collection
 *
 * @package Baum\Extensions\Eloquent
 */
class Collection extends BaseCollection
{
	public function toHierarchy(): BaseCollection
	{
		return new BaseCollection(
			$this->hierarchical(
				$this->getDictionary()
			)
		);
	}

	public function toSortedHierarchy(): BaseCollection
	{
		$dict = $this->getDictionary();

		// Enforce sorting by $orderColumn setting in Baum\Node instance
		uasort($dict, function (Node $a, Node $b) {
			return ($a->getOrder() >= $b->getOrder()) ? 1 : -1;
		});

		return new BaseCollection($this->hierarchical($dict));
	}

	/**
	 * @param array|Node[] $result
	 * @return array
	 */
	protected function hierarchical(array $result): array
	{
		/** @var Node $node */
		foreach ($result as $node) {
			$node->setRelation('children', new BaseCollection());
		}

		$nestedKeys = [];

		foreach ($result as $node) {
			$parentKey = $node->getParentId();

			if (
				null !== $parentKey &&
				array_key_exists($parentKey, $result)
			) {
				$result[$parentKey]->children[] = $node;
				$nestedKeys[] = $node->getKey();
			}
		}

		foreach ($nestedKeys as $key) {
			unset($result[$key]);
		}

		return $result;
	}
}
