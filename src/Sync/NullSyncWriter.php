<?php

namespace Lgz2688\MongoBuilder\Sync;

use Lgz2688\MongoBuilder\Contract\SyncWriterInterface;

class NullSyncWriter implements SyncWriterInterface
{
    public function insertOne(string $collection, array $data): void
    {
    }

    public function insertMany(string $collection, array $rows): void
    {
    }

    public function update(string $collection, array $where, array $data): void
    {
    }

    public function delete(string $collection, array $where): void
    {
    }
}