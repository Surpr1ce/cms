<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

$environment = $_SERVER['APP_ENV'];

if (!is_string($environment)) {
    throw new LogicException('APP_ENV must be defined as a string.');
}

$kernel = new Kernel($environment, (bool) ($_SERVER['APP_DEBUG'] ?? false));

return new Application($kernel);
