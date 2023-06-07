<?php

namespace App\Service;

use App\Client\BigQueryClient;
use App\Exception\QueryException;
use App\Model\QueryResult\QueryResultInterface;
use App\Query\QueryInterface;
use Packages\Models\Model\Upload;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class QueryService
{
    /**
     * @param BigQueryClient $bigQueryClient
     * @param QueryInterface[] $queries
     */
    public function __construct(
        private readonly BigQueryClient $bigQueryClient,
        #[TaggedIterator('app.coverage_query')]
        private readonly iterable $queries
    ) {
    }

    /**
     * @param class-string $queryClass
     * @param Upload $upload
     * @return QueryResultInterface
     *
     * @throws QueryException
     */
    public function runQuery(string $queryClass, Upload $upload): QueryResultInterface
    {
        foreach ($this->queries as $query) {
            if (
                $query instanceof $queryClass &&
                is_subclass_of($query, QueryInterface::class)
            ) {
                return $this->runQueryAndParseResult($query, $upload);
            }
        }

        throw new QueryException(sprintf('No query found with class name of %s.', $queryClass));
    }

    private function runQueryAndParseResult(QueryInterface $query, Upload $upload): QueryResultInterface
    {
        /** @var QueryInterface $query */
        $job = $this->bigQueryClient->query(
            $query->getQuery(
                $this->bigQueryClient->getTable(),
                $upload
            )
        );

        $results = $this->bigQueryClient->runQuery($job);

        $results->waitUntilComplete();

        return $query->parseResults($results);
    }
}
