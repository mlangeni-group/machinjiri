<?php

namespace Mlangeni\Machinjiri\Testing\Traits;

use Faker\Factory;
use Faker\Generator;

trait WithFaker
{
    protected Generator $faker;

    protected function setUpFaker(): void
    {
        $this->faker = Factory::create();
    }

    protected function faker(): Generator
    {
        return $this->faker;
    }
}
