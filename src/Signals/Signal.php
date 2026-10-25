<?php

declare(strict_types=1);

namespace Atannex\Matrix\Signals;

use Atannex\Matrix\Actor;
use Atannex\Matrix\Matrix;
use Atannex\Matrix\Support\Counters;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * One kind of engagement. A signal knows its rows (model, table), how to
 * recount its metrics from them, and how to move a guest's rows to an
 * account. Register your own with Matrix::extend(new MySignal).
 */
abstract class Signal
{
    /**
     * The signal's name: key in config('matrix.signals'), ('matrix.models'),
     * ('matrix.tables') and ('matrix.limits').
     */
    abstract public function name(): string;

    /**
     * Columns that, with the item and actor key, identify one row (merged
     * when a guest's rows move to an account). Null: rows are never merged.
     *
     * @return array<int, string>|null
     */
    public function identity(): ?array
    {
        return [];
    }

    /**
     * Column added together when guest and account rows are merged.
     */
    public function summed(): ?string
    {
        return null;
    }

    /**
     * The item's metrics recounted from the raw rows.
     *
     * @return array<string, int>
     */
    public function recount(string $type, int|string $id): array
    {
        return [];
    }

    /**
     * Whether recount() is the source of truth for this metric (stale ones
     * are removed on recount; others are counter-only and kept).
     */
    public function owns(string $metric): bool
    {
        return false;
    }

    /**
     * Interactive signals refuse loudly (exceptions); tracking signals
     * (views, reads, clicks) ignore what they may not count.
     */
    public function interactive(): bool
    {
        return true;
    }

    public function allowsGuests(): bool
    {
        return (bool) $this->config('guests', true);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return config("matrix.signals.{$this->name()}.{$key}", $default);
    }

    /**
     * @return class-string<Model>|null
     */
    public function model(): ?string
    {
        return config("matrix.models.{$this->name()}");
    }

    public function table(): ?string
    {
        return config("matrix.tables.{$this->name()}");
    }

    public function newQuery(): Builder
    {
        $model = $this->model();

        return $model::query();
    }

    /**
     * Rows of this signal about one item.
     */
    public function about(Model $item): Builder
    {
        return $this->newQuery()
            ->where('engageable_type', $item->getMorphClass())
            ->where('engageable_id', $item->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    */

    /**
     * Every item that has rows of this signal.
     *
     * @return Collection<int, array{type: string, id: int|string}>
     */
    public function subjects(?string $type = null): Collection
    {
        if ($this->table() === null || $this->model() === null) {
            return collect();
        }

        return $this->base()
            ->when($type, fn (QueryBuilder $q) => $q->where('engageable_type', $type))
            ->distinct()
            ->get(['engageable_type', 'engageable_id'])
            ->map(fn (object $row) => ['type' => $row->engageable_type, 'id' => $row->engageable_id]);
    }

    /**
     * Remove every row about an item (it was deleted).
     */
    public function forget(string $type, int|string $id): void
    {
        if ($this->table() !== null && $this->model() !== null) {
            $this->base()->where('engageable_type', $type)->where('engageable_id', $id)->delete();
        }
    }

    /**
     * Move a guest's rows to the account. Where the account already has its
     * own row for the same item, the account's row wins (summed columns are
     * added together).
     *
     * @return array<int, array{type: string, id: int|string}> items whose totals changed
     */
    public function transfer(string $guestKey, Actor $account): array
    {
        if ($this->table() === null || $this->model() === null) {
            return [];
        }

        $guestRows = $this->base()->where('actor_key', $guestKey)
            ->get(array_merge(['id', 'engageable_type', 'engageable_id'], $this->identity() ?? [], array_filter([$this->summed()])));

        if ($guestRows->isEmpty()) {
            return [];
        }

        if ($this->identity() !== null) {
            $columns = ['engageable_type', 'engageable_id', ...$this->identity()];

            $accountRows = $this->base()
                ->where('actor_key', $account->key)
                ->whereIn('engageable_id', $guestRows->pluck('engageable_id')->unique()->all())
                ->get(['id', ...$columns])
                ->keyBy(fn (object $row) => $this->identityOf($row, $columns));

            foreach ($guestRows as $row) {
                $existing = $accountRows->get($this->identityOf($row, $columns));

                if ($existing === null) {
                    continue;
                }

                if ($summed = $this->summed()) {
                    $this->base()->where('id', $existing->id)->increment($summed, (int) $row->{$summed});
                }

                $this->base()->where('id', $row->id)->delete();
            }
        }

        $this->base()->where('actor_key', $guestKey)->update([
            'actor_key' => $account->key,
            'user_id' => $account->userId(),
        ]);

        return $guestRows
            ->map(fn (object $row) => ['type' => $row->engageable_type, 'id' => $row->engageable_id])
            ->unique(fn (array $item) => $item['type'].'|'.$item['id'])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{engageable_type: string, engageable_id: int|string}
     */
    protected function subjectKey(Model $item): array
    {
        return ['engageable_type' => $item->getMorphClass(), 'engageable_id' => $item->getKey()];
    }

    /**
     * Recount this signal's metrics for the item.
     */
    protected function refresh(Model $item): void
    {
        $this->counters()->refresh($item->getMorphClass(), $item->getKey(), $this);
    }

    /**
     * Sum of a column grouped by another, for recounts.
     *
     * @return Collection<string, int>
     */
    protected function grouped(string $type, int|string $id, string $by, string $aggregate): Collection
    {
        return $this->base()
            ->where('engageable_type', $type)
            ->where('engageable_id', $id)
            ->groupBy($by)
            ->selectRaw("{$by} as bucket, {$aggregate} as total")
            ->pluck('total', 'bucket')
            ->map(fn ($total) => (int) $total);
    }

    protected function matrix(): Matrix
    {
        return app(Matrix::class);
    }

    protected function counters(): Counters
    {
        return app(Counters::class);
    }

    protected function base(): QueryBuilder
    {
        return $this->connection()->table((string) $this->table());
    }

    protected function connection(): Connection
    {
        return (new ($this->model()))->getConnection();
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function identityOf(object $row, array $columns): string
    {
        return implode('|', array_map(fn (string $column) => (string) $row->{$column}, $columns));
    }
}
