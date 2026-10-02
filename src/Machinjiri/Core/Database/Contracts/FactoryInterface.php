<?php

namespace Mlangeni\Machinjiri\Core\Database\Contracts;

use Faker\Generator;

interface FactoryInterface 
{
    
    public function __construct(Generator $faker);

    public function definition(): array;

}