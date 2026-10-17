<?php

namespace Ispgrid\Documents;

use App\Models\Account;
use App\Models\User;
use App\Support\Addons\AddonSettings;
use App\Support\Addons\CustomerCards;
use App\Support\Addons\CustomerFormTabs;
use App\Support\Addons\PrivateFile;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions;
use Illuminate\Support\ServiceProvider;
use Ispgrid\Documents\Console\ExportCommand;
use Livewire\Livewire;

/**
 * Customer documents: ID proofs and agreements per customer, in their own database (connection "documents") and
 * encrypted on the add-on disk under documents/.
 */
class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var array<string, mixed> $base */
        $base = config('database.connections.mysql');
        $default = $this->app->environment('testing') ? 'ispgrid_documents_test' : 'ispgrid_documents';

        config(['database.connections.documents' => array_merge($base, [
            'host' => env('DOCUMENTS_DB_HOST', $base['host']),
            'port' => env('DOCUMENTS_DB_PORT', $base['port']),
            'database' => env('DOCUMENTS_DB_DATABASE', $default),
            'username' => env('DOCUMENTS_DB_USERNAME', $base['username']),
            'password' => env('DOCUMENTS_DB_PASSWORD', $base['password']),
        ])]);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        // Registered on every core: core 0.1.0's role form refuses to save a role holding a box it does not list.
        Abilities::register($this->app->make(Permissions::class));

        // Works on every core, so the documents can always be taken out.
        if ($this->app->runningInConsole()) {
            $this->commands([ExportCommand::class]);
        }

        // Sending a decrypted file needs core 0.1.2. On an older core the add-on only keeps its boxes and its data.
        if (! method_exists(PrivateFile::class, 'contentResponse')) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'documents');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        Livewire::addNamespace('documents', viewPath: __DIR__.'/../resources/views/livewire');

        $this->app->make(AddonSettings::class)->register('ispgrid/documents', 'documents::settings', Abilities::TYPES);
        $this->app->make(CustomerFormTabs::class)->register(DocumentsTab::class);
        $this->app->make(CustomerCards::class)->register(
            'documents::customer-card',
            fn (User $user, Account $account): bool => $user->can(Abilities::VIEW) || $user->can(Abilities::UPLOAD),
            60,
        );

        $navigation = $this->app->make(Navigation::class);
        $navigation->add(new NavItem(
            group: 'addons',
            label: fn (): string => __('Missing documents'),
            route: 'documents.missing',
            icon: 'clipboard-document-list',
            active: ['documents.missing'],
            visible: fn (User $user): bool => $user->can(Abilities::VIEW),
            order: 20,
        ));
        $navigation->add(new NavItem(
            group: 'addons',
            label: fn (): string => __('Deleted documents'),
            route: 'documents.deleted',
            icon: 'trash',
            active: ['documents.deleted'],
            visible: fn (User $user): bool => $user->can(Abilities::RESTORE),
            order: 21,
        ));
    }
}
