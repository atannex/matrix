<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('matrix.connection');
    }

    public function up(): void
    {
        $table = fn (string $key) => config("matrix.tables.{$key}");

        Schema::create($table('view'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'null', userAgent: true);
            $t->unsignedInteger('view_count')->default(1);
            $t->timestamp('last_viewed_at')->nullable();
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key'], 'matrix_views_actor_unique');
        });

        Schema::create($table('click'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'null', session: false);
            $t->string('placement', 80)->nullable()->index();
            $t->string('page')->nullable();
            $t->string('referrer_host')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();

            $t->index(['engageable_type', 'engageable_id', 'actor_key', 'created_at'], 'matrix_clicks_debounce_index');
        });

        Schema::create($table('share'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'null', userAgent: true);
            $t->string('platform', 40)->index();
            $t->unsignedInteger('share_count')->default(1);
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key', 'platform'], 'matrix_shares_actor_unique');
        });

        Schema::create($table('reaction'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'cascade');
            $t->string('type', 32);
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key'], 'matrix_reactions_actor_unique');
            $t->index(['engageable_type', 'engageable_id', 'type'], 'matrix_reactions_type_index');
        });

        Schema::create($table('rating'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'null');
            $t->string('dimension', 40)->default('overall');
            $t->unsignedTinyInteger('value');
            $t->text('comment')->nullable();
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key', 'dimension'], 'matrix_ratings_actor_unique');
        });

        Schema::create($table('review'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'cascade', userAgent: true);
            $this->author($t);
            $t->string('title')->nullable();
            $t->text('body');
            $t->unsignedTinyInteger('rating')->nullable();
            $t->string('status', 20)->default('approved')->index();
            $t->json('meta')->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->softDeletes();
            $t->timestamps();

            $t->index(['engageable_type', 'engageable_id', 'status'], 'matrix_reviews_subject_status_index');
            $t->index(['engageable_type', 'engageable_id', 'actor_key'], 'matrix_reviews_actor_index');
        });

        Schema::create($table('comment'), function (Blueprint $t) use ($table) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'cascade', userAgent: true);
            $this->author($t);
            $t->foreignId('parent_id')->nullable()->constrained($table('comment'))->cascadeOnDelete();
            $t->foreignId('root_id')->nullable()->constrained($table('comment'))->cascadeOnDelete();
            $t->unsignedTinyInteger('depth')->default(0);
            $t->text('body');
            $t->string('status', 20)->default('approved')->index();
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('pinned_at')->nullable();
            $t->timestamps();

            $t->index(['engageable_type', 'engageable_id', 'status'], 'matrix_comments_subject_status_index');
        });

        Schema::create($table('bookmark'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'cascade', session: false);
            $t->string('collection', 60)->default('default');
            $t->string('note', 500)->nullable();
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key', 'collection'], 'matrix_bookmarks_actor_unique');
        });

        Schema::create($table('follow'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'cascade', session: false);
            $t->boolean('notify')->default(true);
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key'], 'matrix_follows_actor_unique');
        });

        Schema::create($table('report'), function (Blueprint $t) {
            $t->id();
            $this->subject($t);
            $this->actor($t, onUserDelete: 'cascade');
            $t->string('reason', 40);
            $t->text('details')->nullable();
            $t->string('status', 20)->default('open')->index();
            $t->foreignId('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->string('resolution')->nullable();
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'actor_key'], 'matrix_reports_actor_unique');
        });

        Schema::create($table('counter'), function (Blueprint $t) {
            $t->id();
            $this->subject($t, index: false);
            $t->string('metric', 64);
            $t->bigInteger('value')->default(0);
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'metric'], 'matrix_counters_unique');
            $t->index(['engageable_type', 'metric', 'value'], 'matrix_counters_rank_index');
        });

        Schema::create($table('daily'), function (Blueprint $t) {
            $t->id();
            $this->subject($t, index: false);
            $t->date('day');
            $t->string('metric', 64);
            $t->bigInteger('value')->default(0);
            $t->timestamps();

            $t->unique(['engageable_type', 'engageable_id', 'day', 'metric'], 'matrix_daily_unique');
            $t->index(['day', 'metric'], 'matrix_daily_day_index');
            $t->index(['engageable_type', 'metric', 'day'], 'matrix_daily_rank_index');
        });
    }

    public function down(): void
    {
        foreach (['daily', 'counter', 'report', 'follow', 'bookmark', 'comment', 'review', 'rating', 'reaction', 'share', 'click', 'view'] as $key) {
            Schema::dropIfExists(config("matrix.tables.{$key}"));
        }
    }

    /**
     * The engaged-with item. Key type follows config('matrix.morph_key'):
     * integer (default), uuid or ulid.
     */
    private function subject(Blueprint $t, bool $index = true): void
    {
        match (config('matrix.morph_key', 'integer')) {
            'uuid' => $index ? $t->uuidMorphs('engageable') : [$t->string('engageable_type'), $t->uuid('engageable_id')],
            'ulid' => $index ? $t->ulidMorphs('engageable') : [$t->string('engageable_type'), $t->ulid('engageable_id')],
            default => $index ? $t->morphs('engageable') : [$t->string('engageable_type'), $t->unsignedBigInteger('engageable_id')],
        };
    }

    /**
     * Who engaged: the account (when signed in) and the actor key every row
     * is de-duplicated on.
     */
    private function actor(Blueprint $t, string $onUserDelete, bool $session = true, bool $userAgent = false): void
    {
        $user = $t->foreignId('user_id')->nullable();

        if (Schema::hasTable($this->usersTable())) {
            $user = $user->constrained($this->usersTable());
            $onUserDelete === 'cascade' ? $user->cascadeOnDelete() : $user->nullOnDelete();
        } else {
            $user->index();
        }

        $t->string('actor_key', 64)->index();

        if ($session) {
            $t->string('session_id')->nullable();
        }

        $t->string('ip_address', 45)->nullable();

        if ($userAgent) {
            $t->string('user_agent')->nullable();
        }
    }

    /**
     * Public name and contact for guests (accounts use their profile).
     */
    private function author(Blueprint $t): void
    {
        $t->string('guest_name', 80)->nullable();
        $t->string('guest_email')->nullable();
    }

    private function usersTable(): string
    {
        $model = config('matrix.user_model');

        return is_string($model) && class_exists($model) ? (new $model)->getTable() : 'users';
    }
};
