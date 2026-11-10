<?php

namespace Lgz2688\MongoBuilder;

use Lgz2688\MongoBuilder\Contract\IdGeneratorInterface;
use Lgz2688\MongoBuilder\Contract\SyncWriterInterface;
use Lgz2688\MongoBuilder\IdGenerator\MongoSequenceIdGenerator;
use Lgz2688\MongoBuilder\Sync\NullSyncWriter;
use MongoDB\BSON\Regex;
use MongoDB\Client;
use MongoDB\Collection;
use RuntimeException;
use Throwable;

class Mongo
{
    private readonly Client $client;
    private readonly string $database;
    private string $collection = '';

    private array $where = [];
    private array $data = [];
    private array $order = [];
    private int $limit = 0;
    private array $field = [];
    private array $groupFields = [];
    private array $aggregateFields = [];
    private array $havingConditions = [];
    private array $sortOrder = [];

    private readonly IdGeneratorInterface $idGenerator;
    private readonly SyncWriterInterface $syncWriter;

    public function __construct(
        Config $config,
        ?IdGeneratorInterface $idGenerator = null,
        ?SyncWriterInterface $syncWriter = null
    ) {
        $this->client   = new Client($config->getUri(), $config->getUriOptions());
        $this->database = $config->getDatabase();

        $this->idGenerator = $idGenerator ?? new MongoSequenceIdGenerator($this->client, $this->database);
        $this->syncWriter  = $syncWriter ?? new NullSyncWriter();
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function table(string $collection): self
    {
        $instance = clone $this;
        $instance->collection = $collection;
        return $instance;
    }

    public function rawCollection(?string $name = null, array $options = []): Collection
    {
        return $this->client->selectCollection($this->database, $name ?? $this->collection, $options);
    }

    private function getCollection(): Collection
    {
        return $this->rawCollection();
    }

    public function where(array $condition): self
    {
        $this->formatWhere($condition);
        $this->where['mysql'] = $condition;
        return $this;
    }

    public function find(): ?array
    {
        $options = [];

        if (!empty($this->order)) {
            $options['sort'] = $this->order;
        }

        if (!empty($this->field)) {
            $options['projection'] = $this->field;
        }

        $result = $this->getCollection()->findOne($this->where['mongo'] ?? [], $options);

        if ($result && isset($result['_id'])) {
            unset($result['_id']);
        }
        $this->reset();
        return $result ? json_decode(json_encode($result), true) : null;
    }

    public function page(int $pageNum, int $pageSize = 15): array
    {
        $options = [];

        if (!empty($this->order)) {
            $options['sort'] = $this->order;
        }

        if (!empty($this->field)) {
            if (empty($this->field['_id'])) {
                $this->field['_id'] = 0;
            }
            $options['projection'] = $this->field;
        } else {
            $options['projection'] = ['_id' => 0];
        }

        $skip = $pageNum > 0 ? ($pageNum - 1) * $pageSize : 0;
        $options['skip'] = $skip;
        $options['limit'] = $pageSize;
        $options['allowDiskUse'] = true;

        $cursor = $this->getCollection()->find($this->where['mongo'] ?? [], $options);
        $this->reset();
        return json_decode(json_encode(iterator_to_array($cursor)), true);
    }

    public function select(): array
    {
        $options = [];

        if (!empty($this->order)) {
            $options['sort'] = $this->order;
        }

        if ($this->limit > 0) {
            $options['limit'] = $this->limit;
        }

        if (!empty($this->field)) {
            if (empty($this->field['_id'])) {
                $this->field['_id'] = 0;
            }
            $options['projection'] = $this->field;
        } else {
            $options['projection'] = ['_id' => 0];
        }

        $cursor = $this->getCollection()->find($this->where['mongo'] ?? [], $options);
        $this->reset();
        return json_decode(json_encode(iterator_to_array($cursor)), true);
    }

    public function insert(array $data = [], bool $sync = true): int|null
    {
        $this->data = $data;
        if (empty($this->data)) {
            throw new RuntimeException('插入的数据为空');
        }

        if ($this->shouldUseInsertMany($this->data)) {
            foreach ($this->data as &$item) {
                $item['id'] ??= $this->idGenerator->nextId($this->collection);
            }
            unset($item);

            if ($sync) {
                try {
                    $this->syncWriter->insertMany($this->collection, $this->data);
                } catch (Throwable $e) {
                    throw new RuntimeException($this->collection . '表批量插入数据失败：' . $e->getMessage(), 0, $e);
                }
            }

            $insertRes = $this->getCollection()->insertMany($this->data)->getInsertedCount();
            $this->reset();
            return $insertRes;
        }

        if (empty($this->data['id'])) {
            $id = $this->idGenerator->nextId($this->collection);
            $this->data['id'] = $id;
        } else {
            $id = $this->data['id'];
        }

        if ($sync) {
            try {
                $this->syncWriter->insertOne($this->collection, $this->data);
            } catch (Throwable $e) {
                throw new RuntimeException($this->collection . '表插入单条数据失败：' . $e->getMessage(), 0, $e);
            }
        }

        $this->getCollection()->insertOne($this->data);
        $this->reset();
        return $id;
    }

    public function update(array $data = [], bool $sync = true): array
    {
        $this->data = $data;
        if (empty($this->where)) {
            throw new RuntimeException('更新条件为空');
        }

        if (empty($this->data)) {
            throw new RuntimeException('更新的数据为空');
        }

        if ($sync) {
            try {
                $this->syncWriter->update($this->collection, $this->where['mysql'] ?? [], $this->data);
            } catch (Throwable $e) {
                throw new RuntimeException($this->collection . '表更新数据失败：' . $e->getMessage(), 0, $e);
            }
        }

        $result = $this->getCollection()->updateMany($this->where['mongo'], ['$set' => $this->data]);
        $this->reset();
        return ['matchedCount' => $result->getMatchedCount(), 'modifiedCount' => $result->getModifiedCount()];
    }

    public function delete(bool $sync = true): int
    {
        if (empty($this->where)) {
            throw new RuntimeException('删除的条件为空');
        }

        if ($sync) {
            try {
                $this->syncWriter->delete($this->collection, $this->where['mysql'] ?? []);
            } catch (Throwable $e) {
                throw new RuntimeException($this->collection . '表删除数据失败：' . $e->getMessage(), 0, $e);
            }
        }

        $result = $this->getCollection()->deleteMany($this->where['mongo']);
        $this->reset();
        return $result->getDeletedCount();
    }

    public function order(array $sort): self
    {
        foreach ($sort as $key => $value) {
            $this->order[$key] = strtolower($value) === 'desc' ? -1 : 1;
        }
        return $this;
    }

    public function limit(int $number): self
    {
        $this->limit = $number;
        return $this;
    }

    public function field(array|string $fields): self
    {
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }

        foreach ($fields as $field) {
            $field = trim($field);
            if (str_starts_with($field, '-')) {
                $this->field[substr($field, 1)] = 0;
            } else {
                $this->field[$field] = 1;
            }
        }

        return $this;
    }

    public function like(array $condition): self
    {
        foreach ($condition as $field => $value) {
            $this->where['mongo'][$field] = new Regex('.*' . $value . '.*');
            $this->where['mysql'][] = [$field, 'like', "%$value%"];
        }
        return $this;
    }

    public function regex(array $condition): self
    {
        foreach ($condition as $field => $pattern) {
            $this->where['mongo'][$field]['$regex'] = $pattern;
        }
        return $this;
    }

    public function distinct(string $field): array
    {
        if (empty($field)) {
            return [];
        }

        $result = $this->getCollection()->distinct($field, $this->where['mongo'] ?? []);
        $this->reset();
        return $result ?? [];
    }

    public function sum(string $field): mixed
    {
        return $this->aggregateSingle($field, '$sum', 'total');
    }

    public function avg(string $field): mixed
    {
        return $this->aggregateSingle($field, '$avg', 'average');
    }

    public function min(string $field): mixed
    {
        return $this->aggregateSingle($field, '$min', 'min');
    }

    public function max(string $field): mixed
    {
        return $this->aggregateSingle($field, '$max', 'max');
    }

    private function aggregateSingle(string $field, string $operator, string $key): mixed
    {
        if (empty($field)) {
            return 0;
        }

        $pipeline = [];
        if (!empty($this->where['mongo'])) {
            $pipeline[] = ['$match' => $this->where['mongo']];
        }

        $pipeline[] = [
            '$group' => [
                '_id' => null,
                $key  => [$operator => '$' . $field],
            ],
        ];

        $result = $this->getCollection()->aggregate($pipeline);
        $data = current(iterator_to_array($result));
        $this->reset();
        return $data[$key] ?? 0;
    }

    public function value(string $field): mixed
    {
        $arr = $this->find();
        return $arr[$field] ?? null;
    }

    public function group(array|string $field): self
    {
        if (is_string($field)) {
            $this->groupFields[] = $field;
        } elseif (is_array($field)) {
            foreach ($field as $f) {
                $this->groupFields[] = $f;
            }
        }

        return $this;
    }

    private function buildAggregateFields(): array
    {
        $fields = [];

        if (!empty($this->groupFields)) {
            $_id = [];
            foreach ($this->groupFields as $f) {
                $_id[$f] = '$' . $f;
            }
            $fields['_id'] = $_id;
        }

        foreach ($this->aggregateFields as $alias => $def) {
            $fields[$alias] = $def;
        }

        return $fields;
    }

    public function groupSum(string $field, string $as = 'total'): self
    {
        $this->aggregateFields[$as] = ['$sum' => '$' . $field];
        return $this;
    }

    public function groupAvg(string $field, string $as = 'average'): self
    {
        $this->aggregateFields[$as] = ['$avg' => '$' . $field];
        return $this;
    }

    public function groupMin(string $field, string $as = 'min'): self
    {
        $this->aggregateFields[$as] = ['$min' => '$' . $field];
        return $this;
    }

    public function groupMax(string $field, string $as = 'max'): self
    {
        $this->aggregateFields[$as] = ['$max' => '$' . $field];
        return $this;
    }

    public function groupCount(string $as = 'count'): self
    {
        $this->aggregateFields[$as] = ['$sum' => 1];
        return $this;
    }

    public function get(): array
    {
        $pipeline = [];

        if (!empty($this->where['mongo'])) {
            $pipeline[] = ['$match' => $this->where['mongo']];
        }

        $group = $this->buildAggregateFields();
        if (!empty($group)) {
            $pipeline[] = ['$group' => $group];
        }

        $having = $this->buildHavingPipeline();
        if (!empty($having)) {
            $pipeline[] = $having;
        }

        $sort = $this->buildSortPipeline();
        if (!empty($sort)) {
            $pipeline[] = $sort;
        }

        if ($this->limit > 0) {
            $pipeline[] = ['$limit' => $this->limit];
        }

        $result = $this->getCollection()->aggregate($pipeline);
        $data = iterator_to_array($result);

        $this->reset();
        return $data ? json_decode(json_encode($data), true) : [];
    }

    public function count(): int
    {
        $res = $this->getCollection()->countDocuments($this->where['mongo'] ?? []);
        $this->reset();
        return $res;
    }

    public function having(string $field, string $operator, mixed $value): self
    {
        $this->havingConditions[] = [
            'field'    => $field,
            'operator' => strtolower($operator),
            'value'    => $value,
        ];

        return $this;
    }

    public function groupOrder(string $field, string $order = 'desc'): self
    {
        $this->sortOrder[$field] = strtolower($order) === 'desc' ? -1 : 1;
        return $this;
    }

    private function buildHavingPipeline(): array
    {
        if (empty($this->havingConditions)) {
            return [];
        }

        $match = [];
        foreach ($this->havingConditions as $cond) {
            $field = '$' . $cond['field'];
            $value = $cond['value'];

            switch ($cond['operator']) {
                case '>':
                    $match[] = ['$expr' => ['$gt' => [$field, $value]]];
                    break;
                case '<':
                    $match[] = ['$expr' => ['$lt' => [$field, $value]]];
                    break;
                case '>=':
                    $match[] = ['$expr' => ['$gte' => [$field, $value]]];
                    break;
                case '<=':
                    $match[] = ['$expr' => ['$lte' => [$field, $value]]];
                    break;
                case '=':
                case '==':
                    $match[] = ['$expr' => ['$eq' => [$field, $value]]];
                    break;
                case '!=':
                case '<>':
                    $match[] = ['$expr' => ['$ne' => [$field, $value]]];
                    break;
            }
        }

        return ['$match' => ['$and' => $match]];
    }

    private function buildSortPipeline(): array
    {
        if (empty($this->sortOrder)) {
            return [];
        }

        $sort = [];
        foreach ($this->sortOrder as $field => $dir) {
            $sort['$' . $field] = $dir;
        }

        return ['$sort' => $sort];
    }

    public function orLike(array $conditions): self
    {
        $orConditions = [];
        foreach ($conditions as $field => $value) {
            $orConditions[] = [$field, 'like', $value];
        }

        $this->where['mongo']['$or'] = $this->returnCondition($orConditions);
        return $this;
    }

    public function returnCondition(array $orArr): array
    {
        $o_conditions = [];
        if (!empty($orArr)) {
            foreach ($orArr as $o_subCondition) {
                if (is_array($o_subCondition)) {
                    $o_conditions[] = $this->getOrConditions($o_subCondition);
                }
            }
        }
        return $o_conditions;
    }

    public function getOrConditions(array $v): array
    {
        $where = [];
        if (count($v) >= 3) {
            [$field, $o, $value] = $v;
            $where = $this->applyOperator($field, strtolower($o), $value);
        } else {
            [$field, $value] = $v;
            $where[$field] = $value;
        }
        return $where;
    }

    private function formatWhere(array $condition): void
    {
        if (!empty($condition[0]) && is_string($condition[0]) && strtolower($condition[0]) === 'or') {
            $orConditions = $this->returnCondition($condition[1]);
            if (!empty($orConditions)) {
                $this->where['mongo']['$or'] = $orConditions;
            }
            return;
        }

        foreach ($condition as $key => $item) {
            if (is_array($item) && !empty($item[0]) && is_string($item[0]) && strtolower($item[0]) === 'or') {
                $orConditions = $this->returnCondition($item[1]);
                if (!empty($orConditions)) {
                    $this->where['mongo']['$or'] = $orConditions;
                }
                unset($condition[$key]);
            }
        }

        if (empty($condition)) {
            return;
        }

        if (is_array(reset($condition)) && count(array_filter($condition, 'is_array')) === count($condition)) {
            foreach ($condition as $subCondition) {
                if (count($subCondition) >= 3) {
                    [$field, $operator, $value] = $subCondition;
                    $this->mergeOperator($field, strtolower($operator), $value);
                } else {
                    [$field, $value] = $subCondition;
                    $this->where['mongo'][$field] = $value;
                }
            }
        } elseif (count($condition) === 3 && $this->getArrayType($condition) === 'list') {
            [$field, $operator, $value] = $condition;
            $this->mergeOperator($field, strtolower($operator), $value);
        } else {
            foreach ($condition as $field => $value) {
                $this->where['mongo'][$field] = $value;
            }
        }
    }

    private function mergeOperator(string $field, string $operator, mixed $value): void
    {
        $partial = $this->applyOperator($field, $operator, $value);
        if (isset($partial[$field]) && is_array($partial[$field])) {
            $this->where['mongo'][$field] = array_merge($this->where['mongo'][$field] ?? [], $partial[$field]);
        } else {
            $this->where['mongo'][$field] = $partial[$field];
        }
    }

    private function applyOperator(string $field, string $operator, mixed $value): array
    {
        $where = [];
        switch ($operator) {
            case '>':
                $where[$field]['$gt'] = $value;
                break;
            case '<':
                $where[$field]['$lt'] = $value;
                break;
            case '>=':
                $where[$field]['$gte'] = $value;
                break;
            case '<=':
                $where[$field]['$lte'] = $value;
                break;
            case '<>':
            case '!=':
                $where[$field]['$ne'] = $value;
                break;
            case '=':
            case '==':
                $where[$field] = $value;
                break;
            case 'between':
                if (is_array($value) && count($value) === 2) {
                    $where[$field]['$gte'] = $value[0];
                    $where[$field]['$lte'] = $value[1];
                }
                break;
            case 'in':
                $where[$field]['$in'] = $value;
                break;
            case 'not in':
            case 'notin':
                $where[$field]['$nin'] = $value;
                break;
            case 'like':
                $where[$field] = new Regex('.*' . $value . '.*');
                break;
        }
        return $where;
    }

    public function getArrayType(array $array): string
    {
        $keys = array_keys($array);
        return $keys === range(0, count($array) - 1) ? 'list' : 'dict';
    }

    public function isSequentialArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }

    public function shouldUseInsertMany(array $data): bool
    {
        return $this->isSequentialArray($data) && !empty($data) && is_array(reset($data));
    }

    private function reset(): void
    {
        $this->field = [];
        $this->groupFields = [];
        $this->aggregateFields = [];
        $this->havingConditions = [];
        $this->sortOrder = [];
    }
}