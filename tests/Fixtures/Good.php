<?php

namespace Atannex\Matrix\Tests\Fixtures;

/** An item kept on its own database ("shop"), as a product with its own data would be. */
class Good extends Article
{
    protected $connection = 'shop';

    protected $table = 'goods';
}
