<?php

declare(strict_types=1);

namespace Techzapp\Crm\Support;

/**
 * {{variable}} substitution for subject, preview text, HTML and plain text.
 *
 * Contact data is untrusted input: in HTML context every substituted value is
 * escaped, so an imported "company name" of <script>… renders as text and can
 * never execute or alter the markup.
 */
final class Personalization
{
    /** Fields always available from a contact row. */
    public const STANDARD_VARIABLES = [
        'first_name', 'last_name', 'full_name', 'email', 'company_name', 'website', 'country',
    ];

    private const PATTERN = '/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/i';

    /** @return list<string> Distinct variable names used in the template. */
    public static function variablesIn(string $template): array
    {
        if (preg_match_all(self::PATTERN, $template, $matches) === false) {
            return [];
        }
        $names = array_map('strtolower', $matches[1] ?? []);
        // Internal render tokens ({{!link:1}}) never match the pattern, but keep
        // the guard explicit for readability.
        $names = array_filter($names, static fn (string $n): bool => !str_starts_with($n, '!'));
        return array_values(array_unique($names));
    }

    /**
     * Renders a template.
     *
     * @param array<string,mixed> $values   contact values keyed by variable name
     * @param array<string,mixed> $fallbacks used when a value is missing/blank
     * @param bool  $escapeHtml             true for HTML bodies, false for text/subject
     * @param list<string>|null $missing    receives variables that fell back
     */
    public static function render(
        string $template,
        array $values,
        array $fallbacks = [],
        bool $escapeHtml = false,
        ?array &$missing = null
    ): string {
        $missing = [];

        $rendered = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($values, $fallbacks, $escapeHtml, &$missing): string {
                $name = strtolower($match[1]);
                $raw = $values[$name] ?? null;
                $value = is_scalar($raw) ? trim((string) $raw) : '';

                if ($value === '') {
                    $fallback = $fallbacks[$name] ?? null;
                    $value = is_scalar($fallback) ? (string) $fallback : '';
                    $missing[] = $name;
                }

                if ($escapeHtml) {
                    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                // Text context: strip control characters so a value cannot forge
                // a mail header when used in the subject line.
                return (string) preg_replace('/[\r\n\x00]+/', ' ', $value);
            },
            $template
        );

        $missing = array_values(array_unique($missing));
        return $rendered ?? $template;
    }

    /**
     * Builds the substitution map for a contact row.
     *
     * @param array<string,mixed> $contact
     * @return array<string,string>
     */
    public static function valuesForContact(array $contact): array
    {
        $custom = [];
        $json = $contact['custom_fields_json'] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && is_scalar($value)) {
                        $custom[strtolower($key)] = (string) $value;
                    }
                }
            }
        } elseif (is_array($json)) {
            foreach ($json as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $custom[strtolower($key)] = (string) $value;
                }
            }
        }

        $first = trim((string) ($contact['first_name'] ?? ''));
        $last = trim((string) ($contact['last_name'] ?? ''));

        $standard = [
            'first_name'   => $first,
            'last_name'    => $last,
            'full_name'    => trim($first . ' ' . $last),
            'email'        => (string) ($contact['email'] ?? ''),
            'company_name' => (string) ($contact['company_name'] ?? ''),
            'website'      => (string) ($contact['website'] ?? ''),
            'country'      => (string) ($contact['country'] ?? ''),
        ];

        // Standard fields win over a custom field of the same name.
        return array_merge($custom, $standard);
    }

    /** Sample data used by the campaign preview when no contact is selected. */
    public static function sampleValues(): array
    {
        return [
            'first_name'   => 'Alex',
            'last_name'    => 'Morgan',
            'full_name'    => 'Alex Morgan',
            'email'        => 'alex.morgan@example.com',
            'company_name' => 'Northwind Trading',
            'website'      => 'https://northwind.example.com',
            'country'      => 'United Kingdom',
        ];
    }
}
