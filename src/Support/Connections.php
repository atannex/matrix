<?php

declare(strict_types=1);

namespace Atannex\Matrix\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Which database holds an item's engagement.
 *
 * By default every row lives on config('matrix.connection'). With
 * matrix.follow_subject_connection on, an item whose model names its own
 * connection keeps its engagement there too, so an application that splits
 * its data across databases can still sort and filter by engagement inside
 * one query. Work that spans every item (claiming a guest's rows, site-wide
 * totals, rebuilds, pruning) runs on each of all().
 */
final class Connections
{
    /** @var array<string, string|null> */
    private static array $resolved = [];

    /** The connection holding engagement about items of this morph type. */
    public static function for(?string $type): ?string
    {
        $default = config('matrix.connection');

        if ($type === null || ! config('matrix.follow_subject_connection', false)) {
            return $default;
        }

        return self::$resolved[$type] ??= self::subjectConnection($type) ?? $default;
    }

    public static function forItem(Model $item): ?string
    {
        return self::for($item->getMorphClass());
    }

    /**
     * Every connection that may hold engagement: the default one and
     * config('matrix.connections').
     *
     * @return list<string|null>
     */
    public static function all(): array
    {
        $all = [config('matrix.connection')];
        $seen = [self::target($all[0]) => true];

        if (config('matrix.follow_subject_connection', false)) {
            foreach ((array) config('matrix.connections', []) as $connection) {
                // Two names for one database (a connection not split off yet) would count its rows twice.
                if (! isset($seen[$target = self::target($connection)])) {
                    $seen[$target] = true;
                    $all[] = $connection;
                }
            }
        }

        return $all;
    }

    /** Where a connection's data lives; every in-memory SQLite connection is a database of its own. */
    public static function target(?string $connection): string
    {
        $name = $connection ?? (string) config('database.default');
        $config = (array) config("database.connections.{$name}", []);

        if (($config['driver'] ?? null) === 'sqlite' && ($config['database'] ?? null) === ':memory:') {
            return 'memory:'.$name;
        }

        return implode('|', [$config['driver'] ?? '', $config['url'] ?? '', $config['host'] ?? '', $config['port'] ?? '', $config['database'] ?? '']);
    }

    /** Forget what was resolved (tests, or after the configuration changes). */
    public static function flush(): void
    {
        self::$resolved = [];
    }

    private static function subjectConnection(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return (new $class)->getConnectionName();
    }
}
