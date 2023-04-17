<?php

namespace App\Strategy\Clover;

use App\Enum\LineTypeEnum;
use App\Exception\ParseException;
use App\Model\FileCoverage;
use App\Model\LineCoverage;
use App\Model\ProjectCoverage;
use App\Strategy\ParseStrategyInterface;
use XMLReader;

abstract class AbstractCloverParseStrategy implements ParseStrategyInterface
{
    private const PROJECT = "project";
    private const FILE = "file";
    private const LINE = "line";

    public function supports(string $content): bool
    {
        libxml_use_internal_errors(true);

        $reader = $this->buildXmlReader($content);
        if (!$reader->read()) {
            return false;
        }

        while ($reader->read()) {
            if (!$reader->isValid()) {
                return false;
            }
        }

        return true;
    }

    public function parse(string $content): ProjectCoverage
    {
        if (!$this->supports($content)) {
            throw ParseException::notSupportedException();
        }

        $reader = $this->buildXmlReader($content);
        $project = new ProjectCoverage();

        while ($reader->read()) {
            $project = $this->handleNode($project, $reader);
        }

        return $project;
    }

    abstract protected function buildXmlReader(string $content): XMLReader;

    private function handleNode(ProjectCoverage $coverage, XMLReader $reader): ProjectCoverage
    {
        switch ($reader->name) {
            case self::PROJECT:
                $coverage->setGeneratedAt($reader->getAttribute("timestamp"));
                break;
            case self::FILE:
                $coverage->addFileCoverage(
                    new FileCoverage(
                        $reader->getAttribute("name")
                    )
                );
                break;
            case self::LINE:
                $files = $coverage->getFileCoverage();

                end($files)->addLineCoverage(
                    new LineCoverage(
                        $this->convertLineType($reader->getAttribute("type")),
                        $reader->getAttribute("num"),
                        $reader->getAttribute("name"),
                        $reader->getAttribute("count") ?? 0,
                        $reader->getAttribute("complexity") ?? 0,
                        $reader->getAttribute("crap") ?? 0,
                    )
                );
                break;
        }

        return $coverage;
    }

    private function convertLineType(?string $type): LineTypeEnum
    {
        return match ($type) {
            "stmt" => LineTypeEnum::STATEMENT,
            "cond" => LineTypeEnum::CONDITION,
            "method" => LineTypeEnum::METHOD,
            default => ParseException::lineTypeParseException($type)
        };
    }
}