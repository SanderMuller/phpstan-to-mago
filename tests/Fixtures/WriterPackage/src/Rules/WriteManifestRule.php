<?php

declare(strict_types=1);

namespace Fixture\Rules;

use Fixture\Collectors\ManifestCollector;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;

/**
 * Writes a manifest and reports nothing.
 *
 * A build artefact wearing a rule's interface. `report()` is an analyzer plugin's only output, so there is
 * nothing here for a plugin to do — and agreement has no meaning for a file.
 *
 * @implements Rule<CollectedDataNode>
 */
final class WriteManifestRule implements Rule
{
    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $records = [];
        foreach ($node->get(ManifestCollector::class) as $file => $fileRecords) {
            $records[$file] = $fileRecords;
        }

        file_put_contents('manifest.json', json_encode($records));

        return [];
    }
}
