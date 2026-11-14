<?php

namespace AllsWeb\InRegistry\Tests;

/** Validates EPP documents against the official XSDs (tests/xsd). */
final class Schema
{
    /** Validation errors since the last reset; the runner fails a test that leaves any. */
    public static $errors = [];
    /** Number of documents validated (for the summary). */
    public static $validated = 0;

    /** @return string[] error messages, empty when valid */
    public static function errorsFor($xml)
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new \DOMDocument();
        $errors = [];
        if (!$doc->loadXML($xml)) {
            $errors[] = 'not well-formed';
        } elseif (!$doc->schemaValidate(__DIR__ . '/../xsd/all.xsd')) {
            foreach (libxml_get_errors() as $e) {
                $errors[] = trim($e->message) . ' (line ' . $e->line . ')';
            }
            if (!$errors) {
                $errors[] = 'invalid';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::$validated++;
        return $errors;
    }

    /** Validates and records failures under $label. */
    public static function check($xml, $label)
    {
        $errors = self::errorsFor($xml);
        if ($errors) {
            self::$errors[] = $label . ': ' . implode('; ', $errors) . "\n" . $xml;
        }
        return !$errors;
    }
}
