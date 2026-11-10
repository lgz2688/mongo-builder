<?php

namespace Lgz2688\MongoBuilder\Contract;

interface IdGeneratorInterface
{
    public function nextId(string $collection): int;
}