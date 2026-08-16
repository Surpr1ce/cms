<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Story\AppStory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Loads the development dataset.
 *
 * The content lives in AppStory rather than here, so that fixtures and tests
 * build entities the same way — through the factories — and there is one
 * definition of what a valid article looks like rather than two that drift.
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        AppStory::load();

        $manager->flush();
    }
}
