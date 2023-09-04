<?php

namespace App\Query;

use App\Enum\QueryParameter;
use App\Exception\QueryException;
use App\Model\QueryParameterBag;
use App\Query\Result\TotalUploadsQueryResult;
use App\Query\Trait\ScopeAwareTrait;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\Core\Exception\GoogleException;
use Packages\Models\Model\Upload;

class TotalUploadsQuery implements QueryInterface
{
    use ScopeAwareTrait;

    public function getQuery(string $table, ?QueryParameterBag $parameterBag = null): string
    {
        $parent = self::getNamedQueries($table, $parameterBag);
        return <<<SQL
        {$parent}
        SELECT
            SUM(successful) as successfulUploads,
            SUM(pending) as pendingUploads
        FROM
            uploads
        SQL;
    }

    public function getNamedQueries(string $table, ?QueryParameterBag $parameterBag = null): string
    {
        $commitScope = self::getCommitScope($parameterBag);
        $repositoryScope = self::getRepositoryScope($parameterBag);

        return <<<SQL
        WITH uploads AS (
            SELECT
                uploadId,
                IF(totalLines >= COUNT(*), 1, 0) as successful,
                IF(totalLines < COUNT(*), 1, 0) as pending
            FROM
                `$table`
            WHERE
                {$commitScope} AND
                {$repositoryScope}
            GROUP BY
                uploadId,
                totalLines
        )
        SQL;
    }

    /**
     * @throws GoogleException
     * @throws QueryException
     */
    public function parseResults(QueryResults $results): TotalUploadsQueryResult
    {
        if (!$results->isComplete()) {
            throw new QueryException('Query was not complete when attempting to parse results.');
        }

        /** @var array $row */
        $row = $results->rows()
            ->current();

        if (!is_int($row['successfulUploads'])) {
            throw QueryException::typeMismatch(gettype($row['successfulUploads']), 'int');
        }

        if (!is_int($row['pendingUploads'])) {
            throw QueryException::typeMismatch(gettype($row['pendingUploads']), 'int');
        }

        return TotalUploadsQueryResult::from($row['successfulUploads'], $row['pendingUploads']);
    }

    public function validateParameters(?QueryParameterBag $parameterBag = null): void
    {
        if (
            !$parameterBag ||
            !$parameterBag->has(QueryParameter::UPLOAD) ||
            !($parameterBag->get(QueryParameter::UPLOAD) instanceof Upload)
        ) {
            throw QueryException::invalidParameters(QueryParameter::UPLOAD);
        }
    }
}
