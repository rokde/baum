<?php

namespace Baum\Providers;

use Baum\Console\InstallCommand;
use Baum\Generators\MigrationGenerator;
use Baum\Generators\ModelGenerator;
use Illuminate\Support\ServiceProvider;

class BaumServiceProvider extends ServiceProvider
{
	/**
	 * Baum version.
	 *
	 * @var string
	 */
	const VERSION = '2.0.0';

	public function register()
	{
		if (!$this->app->runningInConsole()) {
			return;
		}

		$this->registerInstallCommand();

		// Resolve the commands with Artisan by attaching the event listener to Artisan's
		// startup. This allows us to use the commands from our terminal.
		$this->commands('command.baum.install');
	}

	/**
	 * Register the 'baum:install' command.
	 *
	 * @return void
	 */
	protected function registerInstallCommand()
	{
		$this->app->singleton('command.baum.install', function ($app) {
			$migrator = new MigrationGenerator($app['files']);
			$modeler = new ModelGenerator($app['files']);

			return new InstallCommand($migrator, $modeler);
		});
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return ['command.baum.install'];
	}
}
