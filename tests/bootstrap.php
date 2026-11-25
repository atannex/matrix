<?php

require __DIR__.'/../vendor/autoload.php';

// PHP 8.6 warns that Laravel's session handlers lack create_sid() when the
// class is first declared. Declared inside a request, Laravel turns that
// warning into a 500 for whichever HTTP test runs first; declare it here.
@class_exists(\Illuminate\Session\ArraySessionHandler::class);
