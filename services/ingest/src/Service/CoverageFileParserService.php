<?php

namespace App\Service;

use App\Model\ProjectCoverage;
use App\Strategy\Clover\CloverParseStrategy;
use App\Strategy\Lcov\LcovParseStrategy;
use App\Strategy\ParseStrategyInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

class CoverageFileParserService implements ServiceSubscriberInterface
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {
    }

    public function parse(string $coverageFile): ProjectCoverage
    {
        foreach (self::getSubscribedServices() as $strategy) {
            $parserStrategy = $this->container->get($strategy);

            if (!$parserStrategy instanceof ParseStrategyInterface) {
                throw new RuntimeException('Strategy does not implement the correct interface');
            }

            if (!$parserStrategy->supports($coverageFile)) {
                continue;
            }

            return $parserStrategy->parse($coverageFile);
        }

        throw new RuntimeException('No strategy found which supports coverage file content');
    }

    public static function getSubscribedServices(): array
    {
        return [
            CloverParseStrategy::class,
            LcovParseStrategy::class
        ];
    }
}
