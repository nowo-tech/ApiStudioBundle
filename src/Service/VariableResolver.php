<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

/**
 * Resolves {{variable}} placeholders in URLs, headers, and bodies.
 */
final class VariableResolver
{
    /**
     * @param array<string, string> $variables
     */
    public function resolve(string $template, array $variables): string
    {
        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback(
            VariableSyntax::PLACEHOLDER_PATTERN,
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return $variables[$key] ?? $matches[0];
            },
            $template,
        );
    }

    /**
     * @param array<string, string> $variables
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public function resolveMap(array $values, array $variables): array
    {
        $resolved = [];
        foreach ($values as $key => $value) {
            $resolved[$key] = $this->resolve($value, $variables);
        }

        return $resolved;
    }
}
