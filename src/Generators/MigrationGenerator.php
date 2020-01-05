<?php

namespace Baum\Generators;

class MigrationGenerator extends Generator
{
    /**
     * Create a new migration at the given path.
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

        $stub = $this->getStub('migration');

        $this->files->put($path, $this->parseStub($stub, [
            'table' => $this->tableize($name),
            'class' => $this->getMigrationClassName($name),
        ]));

        return $path;
    }

    /**
     * Get the migration name.
     *
     * @param string $name
     *
     * @return string
     */
    protected function getMigrationName(string $name): string
    {
        return 'create_' . $this->tableize($name) . '_table';
    }

    /**
     * Get the name for the migration class.
     *
     * @param string $name
     * @return string
     */
    protected function getMigrationClassName(string $name): string
    {
        return $this->classify($this->getMigrationName($name));
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
        return $path . DIRECTORY_SEPARATOR . $this->getDatePrefix() . '_' . $this->getMigrationName($name) . '.php';
    }

    /**
     * Get the date prefix for the migration.
     *
     * @return string
     */
    protected function getDatePrefix(): string
    {
        return date('Y_m_d_His');
    }
}
