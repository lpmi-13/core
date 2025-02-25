<?php

namespace App\Service;

use App\Exception\ParseException;
use App\Strategy\ParseStrategyInterface;
use Packages\Models\Model\Coverage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

class CoverageFileParserService
{
    public function __construct(
        #[TaggedIterator('app.parser_strategy')]
        private readonly iterable $parserStrategies,
        private readonly LoggerInterface $parseStrategyLogger
    ) {
    }

    /**
     * Attempt to parse an arbitrary coverage file content using all supported parsing
     * strategies.
     *
     * @throws ParseException
     */
    public function parse(string $projectRoot, string $coverageFile): Coverage
    {
        foreach ($this->parserStrategies as $strategy) {
            if (!$strategy instanceof ParseStrategyInterface) {
                $this->parseStrategyLogger->critical(
                    'Strategy does not implement the correct interface.',
                    [
                        'strategy' => $strategy::class
                    ]
                );

                continue;
            }

            if (!$strategy->supports($coverageFile)) {
                $this->parseStrategyLogger->info(
                    sprintf(
                        'Not parsing using %s, as it does not support content',
                        $strategy::class
                    )
                );
                continue;
            }

            return $strategy->parse($projectRoot, $coverageFile);
        }

        throw new ParseException('No strategy found which supports coverage file content.');
    }
}
