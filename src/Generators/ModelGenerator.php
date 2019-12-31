<?php

namespace Baum\Generators;

class ModelGenerator extends Generator
{
	/**
	 * Create a new model at the given path.
	 *
	 * @param string $name
	 * @param string $path
	 *
	 * @return string
	 * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
	 */
	public function create(string $name, string $path): string
	{
		$path = $this->getPath($name, $path);

		$stub = $this->getStub('model');

		$this->files->put($path, $this->parseStub($stub, [
			'table' => $this->tableize($name),
			'class' => $this->classify($name),
		]));

		return $path;
	}

	/**
	 * Get the full path name to the migration.
	 *
	 * @param string $name
	 * @param string $path
	 *
	 * @return string
	 */
	protected function getPath(string $name, string $path): string
	{
		return $path . DIRECTORY_SEPARATOR . $this->classify($name) . '.php';
	}
}
