<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\HttpException;
use PDO;

final class TableRepository
{
    /**
     * @param array<int, string> $columns
     * @param array<int, string> $writableColumns
     * @param array<int, string> $searchableColumns
     * @param array<int, string> $filterableColumns
     * @param array<int, string> $sortableColumns
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table,
        private readonly array $columns,
        private readonly array $writableColumns,
        private readonly array $searchableColumns = [],
        private readonly array $filterableColumns = [],
        private readonly array $sortableColumns = ['id'],
        private readonly string $defaultSort = 'id'
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($query['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $parameters] = $this->buildWhere($query);
        [$sortColumn, $sortDirection] = $this->sort($query);

        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*)::int AS total FROM ' . $this->table . $whereSql
        );
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetch()['total'];

        $parameters['limit'] = $perPage;
        $parameters['offset'] = $offset;

        $statement = $this->pdo->prepare(
            'SELECT ' . $this->columnList() .
            ' FROM ' . $this->table .
            $whereSql .
            ' ORDER BY ' . $sortColumn . ' ' . $sortDirection .
            ' LIMIT :limit OFFSET :offset'
        );

        foreach ($parameters as $name => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue(':' . $name, $value, $type);
        }

        $statement->execute();

        return [
            'data' => $statement->fetchAll(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . $this->columnList() .
            ' FROM ' . $this->table .
            ' WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): array
    {
        $data = $this->writableData($data);

        if ($data === []) {
            throw new HttpException('No writable fields were provided', 422);
        }

        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->table .
            ' (' . implode(', ', $columns) . ')' .
            ' VALUES (' . implode(', ', $placeholders) . ')' .
            ' RETURNING ' . $this->columnList()
        );
        $statement->execute($data);

        return $statement->fetch();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?array
    {
        $data = $this->writableData($data);

        if ($data === []) {
            throw new HttpException('No writable fields were provided', 422);
        }

        $assignments = array_map(
            static fn (string $column): string => $column . ' = :' . $column,
            array_keys($data)
        );

        $data['id'] = $id;

        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table .
            ' SET ' . implode(', ', $assignments) .
            ' WHERE id = :id RETURNING ' . $this->columnList()
        );
        $statement->execute($data);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->table . ' WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $query): array
    {
        $clauses = [];
        $parameters = [];

        $search = trim((string) ($query['search'] ?? ''));

        if ($search !== '' && $this->searchableColumns !== []) {
            $searchClauses = [];

            foreach ($this->searchableColumns as $index => $column) {
                $parameter = 'search' . $index;
                $searchClauses[] = $column . ' ILIKE :' . $parameter;
                $parameters[$parameter] = '%' . $search . '%';
            }

            $clauses[] = '(' . implode(' OR ', $searchClauses) . ')';
        }

        foreach ($this->filterableColumns as $column) {
            if (!array_key_exists($column, $query) || $query[$column] === '') {
                continue;
            }

            $parameter = 'filter_' . $column;
            $clauses[] = $column . ' = :' . $parameter;
            $parameters[$parameter] = $query[$column];
        }

        if ($clauses === []) {
            return ['', $parameters];
        }

        return [' WHERE ' . implode(' AND ', $clauses), $parameters];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private function sort(array $query): array
    {
        $sort = (string) ($query['sort'] ?? $this->defaultSort);
        $direction = 'ASC';

        if (str_starts_with($sort, '-')) {
            $direction = 'DESC';
            $sort = substr($sort, 1);
        }

        if (!in_array($sort, $this->sortableColumns, true)) {
            throw new HttpException('Unsupported sort field', 422, [
                'sort' => $sort,
                'allowed' => $this->sortableColumns,
            ]);
        }

        return [$sort, $direction];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function writableData(array $data): array
    {
        return array_intersect_key($data, array_flip($this->writableColumns));
    }

    private function columnList(): string
    {
        return implode(', ', $this->columns);
    }
}
