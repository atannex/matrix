<?php

namespace Atannex\Matrix\Tests\Unit;

use Atannex\Matrix\Support\Connections;
use Atannex\Matrix\Tests\TestCase;

class ConnectionsTest extends TestCase
{
    public function test_two_names_for_one_database_are_covered_once(): void
    {
        config([
            'database.connections.shop' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'atannex'],
            'database.connections.shop_alias' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'atannex'],
            'database.connections.books' => ['driver' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'atannex_books'],
            'matrix.follow_subject_connection' => true,
            'matrix.connections' => ['shop', 'shop_alias', 'books'],
        ]);

        $this->assertSame([null, 'shop', 'books'], Connections::all());
    }

    public function test_without_following_only_the_configured_connection_is_used(): void
    {
        config(['matrix.follow_subject_connection' => false, 'matrix.connections' => ['shop']]);

        $this->assertSame([null], Connections::all());
        $this->assertNull(Connections::for('App\\Models\\Anything'));
    }
}
