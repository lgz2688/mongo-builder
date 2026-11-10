<?php

namespace Lgz2688\MongoBuilder\IdGenerator;

use Lgz2688\MongoBuilder\Contract\IdGeneratorInterface;
use MongoDB\Client;
use MongoDB\Operation\FindOneAndUpdate;

class MongoSequenceIdGenerator implements IdGeneratorInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $database,
        private readonly string $counterCollection = 'mongo_id_counters',
    ) {
    }

    public function nextId(string $collection): int
    {
        $counter = $this->client->selectCollection($this->database, $this->counterCollection);

        $result = $counter->findOneAndUpdate(
            ['_id' => $collection],
            ['$inc' => ['seq' => 1]],
            [
                'upsert'         => true,
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        return (int) ($result['seq'] ?? 1);
    }
}