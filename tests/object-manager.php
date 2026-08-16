<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

$environment = $_SERVER['APP_ENV'];

if (!is_string($environment)) {
    throw new LogicException('APP_ENV must be defined as a string.');
}

$kernel = new Kernel($environment, (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');

if (!$registry instanceof ManagerRegistry) {
    throw new LogicException('The doctrine service must be a ManagerRegistry.');
}

return $registry->getManager();
