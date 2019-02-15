<?php

namespace Zbiller\Revisions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Zbiller\Revisions\Models\Revision;
use Illuminate\Contracts\Foundation\Application;
use Zbiller\Revisions\Contracts\RevisionModelContract;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Create a new service provider instance.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishConfigs();
        $this->publishMigrations();
        $this->registerRouteBindings();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerBindings();
    }

    /**
     * @return void
     */
    protected function publishConfigs()
    {
        $this->publishes([
            __DIR__.'/../config/revisions.php' => config_path('revisions.php'),
        ], 'config');
    }

    /**
     * @return void
     */
    protected function publishMigrations()
    {
        if (empty(File::glob(database_path('migrations/*_create_revisions_table.php')))) {
            $timestamp = date('Y_m_d_His', time());
            $migration = database_path("migrations/{$timestamp}_create_revisions_table.php");

            $this->publishes([
                __DIR__.'/../database/migrations/create_revisions_table.php.stub' => $migration,
            ], 'migrations');
        }
    }

    /**
     * @return void
     */
    protected function registerRouteBindings()
    {
        Route::model('revision', RevisionModelContract::class);
    }

    /**
     * @return void
     */
    protected function registerBindings()
    {
        $this->app->bind(RevisionModelContract::class, $this->config['revisions']['revision_model'] ?? Revision::class);
        $this->app->alias(RevisionModelContract::class, 'revision.model');
    }
}
