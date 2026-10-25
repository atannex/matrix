<?php

namespace Atannex\Matrix\Tests;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Facades\Matrix;
use Atannex\Matrix\MatrixServiceProvider;
use Atannex\Matrix\Tests\Fixtures\Article;
use Atannex\Matrix\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MatrixServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('matrix.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Matrix::moderatorsUsing(fn (User $user) => (bool) $user->moderator);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->default('');
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('moderator')->default(false);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('Story');
            $table->boolean('published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('writers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Users first: the matrix migration links user_id to the users table.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function user(string $name = 'Ada', bool $verified = true, bool $moderator = false): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => strtolower($name).'@example.com',
            'email_verified_at' => $verified ? now() : null,
            'moderator' => $moderator,
        ]);
    }

    protected function article(array $attributes = []): Article
    {
        return Article::query()->create($attributes + ['published_at' => now()]);
    }

    protected function account(?User $user = null): Actor
    {
        return Actor::forUser($user ?? $this->user(), '203.0.113.7', 'Mozilla/5.0 Test');
    }

    protected function guest(?string $id = null): Actor
    {
        return Actor::guest($id ?? (string) Str::uuid(), '198.51.100.20', 'Mozilla/5.0 Test');
    }
}
