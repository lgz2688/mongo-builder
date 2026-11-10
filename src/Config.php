<?php

namespace Lgz2688\MongoBuilder;

class Config
{
    public function __construct(
        private readonly string $uri,
        private readonly string $database,
        private readonly array $uriOptions = [],
    ) {
    }

    public static function make(
        string $uri,
        string $database,
        ?string $username = null,
        ?string $password = null,
        ?string $authSource = null,
        array $extra = []
    ): self {
        $options = array_filter([
            'username'      => $username,
            'password'      => $password,
            'authSource'    => $authSource,
            'authMechanism' => 'SCRAM-SHA-256',
            'maxPoolSize'   => 50,
        ], static fn ($v) => $v !== null);

        return new self($uri, $database, array_merge($options, $extra));
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getUriOptions(): array
    {
        return $this->uriOptions;
    }
}