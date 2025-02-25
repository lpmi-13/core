<?php

namespace App\Query;

use App\Exception\QueryException;
use App\Model\QueryParameterBag;
use App\Query\Result\CoverageQueryResult;
use App\Query\Trait\CarryforwardAwareTrait;
use App\Query\Trait\DiffAwareTrait;
use Google\Cloud\BigQuery\QueryResults;
use Google\Cloud\Core\Exception\GoogleException;
use Packages\Models\Enum\LineState;

class TotalCoverageQuery extends AbstractLineCoverageQuery
{
    use DiffAwareTrait;
    use CarryforwardAwareTrait;

    private const UPLOAD_TABLE_ALIAS = 'upload';

    public function getQuery(string $table, ?QueryParameterBag $parameterBag = null): string
    {
        return <<<SQL
        {$this->getNamedQueries($table, $parameterBag)}
        SELECT
            SUM(lines) as lines,
            SUM(covered) as covered,
            SUM(partial) as partial,
            SUM(uncovered) as uncovered,
            ROUND((SUM(covered) + SUM(partial)) / IF(SUM(lines) = 0, 1, SUM(lines)) * 100, 2) as coveragePercentage
        FROM
            summedCoverage
        SQL;
    }

    public function getNamedQueries(string $table, ?QueryParameterBag $parameterBag = null): string
    {
        $parent = parent::getNamedQueries($table, $parameterBag);

        $covered = LineState::COVERED->value;
        $partial = LineState::PARTIAL->value;
        $uncovered = LineState::UNCOVERED->value;

        return <<<SQL
        {$parent},
        summedCoverage AS (
            SELECT
                COUNT(*) as lines,
                COALESCE(SUM(IF(state = "{$covered}", 1, 0)), 0) as covered,
                COALESCE(SUM(IF(state = "{$partial}", 1, 0)), 0) as partial,
                COALESCE(SUM(IF(state = "{$uncovered}", 1, 0)), 0) as uncovered,
            FROM
                lines
        )
        SQL;
    }

    public function getUnnestQueryFiltering(string $table, ?QueryParameterBag $parameterBag = null): string
    {
        $parent = parent::getUnnestQueryFiltering($table, $parameterBag);
        $carryforwardScope = !empty(
            $scope = self::getCarryforwardTagsScope(
                $parameterBag,
                self::UPLOAD_TABLE_ALIAS
            )
        ) ? 'OR ' . $scope : '';
        $lineScope = !empty($scope = self::getLineScope($parameterBag)) ? 'AND ' . $scope : '';

        return <<<SQL
        (
            (
                {$parent}
            )
            {$carryforwardScope}
        )
        {$lineScope}
        SQL;
    }

    /**
     * @throws GoogleException
     * @throws QueryException
     */
    public function parseResults(QueryResults $results): CoverageQueryResult
    {
        if (!$results->isComplete()) {
            throw new QueryException('Query was not complete when attempting to parse results.');
        }

        /** @var array $coverageValues */
        $coverageValues = $results->rows()
            ->current();

        return CoverageQueryResult::from($coverageValues);
    }
}
