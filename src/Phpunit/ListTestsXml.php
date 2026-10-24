<?php

declare(strict_types=1);

namespace Selective\PHPUnit\Phpunit;

final class ListTestsXml
{
    public static function parse(string $xml, int $major): array
    {
        $document = new \DOMDocument();
        if (@$document->loadXML($xml) === false) {
            return [];
        }
        $xpath = new \DOMXPath($document);
        $classNames = $major >= 11 ? ['testClass'] : ['testCaseClass'];
        $methodNames = $major >= 11 ? ['testMethod'] : ['testCaseMethod'];
        $cases = [];
        foreach ($xpath->query('//*') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if (!in_array($node->localName, $classNames, true)) {
                continue;
            }
            $class = $node->getAttribute('name');
            $file = $node->getAttribute('file');
            foreach ($node->childNodes as $child) {
                if (!$child instanceof \DOMElement || !in_array($child->localName, $methodNames, true)) {
                    continue;
                }
                $id = $child->getAttribute('id');
                if ($id === '') {
                    continue;
                }
                $method = $child->getAttribute('name');
                $dataset = '';
                $marker = strpos($id, '#');
                if ($marker !== false) {
                    $dataset = substr($id, $marker + 1);
                }
                $cases[] = [
                    'id' => $id,
                    'class' => $class,
                    'method' => $method,
                    'dataset' => $dataset,
                    'file' => $file,
                ];
            }
        }
        $phptNames = $major >= 11 ? ['phpt'] : ['phptFile'];
        foreach ($xpath->query('//*') ?: [] as $node) {
            if (!$node instanceof \DOMElement || !in_array($node->localName, $phptNames, true)) {
                continue;
            }
            $path = $node->getAttribute($major >= 11 ? 'file' : 'path');
            if ($path === '') {
                continue;
            }
            $cases[] = [
                'id' => $path,
                'class' => '',
                'method' => '',
                'dataset' => '',
                'file' => $path,
            ];
        }

        return $cases;
    }
}
