<?php

namespace Baum\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

abstract class Generator
{
	/**
	 * The filesystem instance.
	 *
	 * @var \Illuminate\Filesystem\Filesystem
	 */
	protected $files = null;

	/**
	 * Create a new MigrationGenerator instance.
	 *
	 * @param \Illuminate\Filesystem\Filesystem $files
	 *
	 * @return void
	 */
	public function __construct(Filesystem $files)
	{
		$this->files = $files;
	}

	/**
	 * Get the path to the stubs.
	 */
	public function getStubPath(): string
	{
		return __DIR__ . '/stubs';
	}

	public function getFilesystem(): Filesystem
	{
		return $this->files;
	}

	/**
	 * Get the given stub by name.
	 *
	 * @param string $name
	 * @return string
	 * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
	 */
	protected function getStub(string $name): string
	{
		if (stripos($name, '.php.stub') === false) {
			$name = $name . '.php.stub';
		}

		return $this->files->get($this->getStubPath() . '/' . $name);
	}

	/**
	 * Parse the provided stub and replace via the array given.
	 *
	 * @param string $stub
	 * @param array $replacements
	 *
	 * @return string
	 */
	protected function parseStub(string $stub, array $replacements = []): string
	{
		$output = $stub;

		foreach ($replacements as $key => $replacement) {
			$search = '{{' . $key . '}}';
			$output = str_replace($search, $replacement, $output);
		}

		return $output;
	}

	/**
	 * Inflect to a class name.
	 *
	 * @param string $input
	 *
	 * @return string
	 */
	protected function classify(string $input): string
	{
		return Str::studly(Str::singular($input));
	}

	/**
	 * Inflect to table name.
	 *
	 * @param string $input
	 *
	 * @return string
	 */
	protected function tableize(string $input): string
	{
		return Str::snake(Str::plural($input));
	}
}
