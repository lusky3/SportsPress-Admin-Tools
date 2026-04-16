<?php
if (!defined('ABSPATH')) { exit; }
/**
 * XLSX parser for PHP
 * Reads actual XLSX files using ZIP extraction
 *
 * @author Cody (lusky3)
 */

class SimpleXLSX
{
    private $data = array();

    public static function parse($file_path)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        $instance = new self();
        return $instance->parseFile($file_path) ? $instance : false;
    }

    private function parseFile($file_path)
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);

        if (strtolower($extension) === 'csv') {
            return $this->parseCSV($file_path);
        }

        if (strtolower($extension) === 'xlsx') {
            return $this->parseXLSX($file_path);
        }

        return false;
    }

    private function parseXLSX($file_path)
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return false;
        }

        $shared_strings = $this->extractSharedStrings($zip);
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!$sheet_xml) {
            return false;
        }

        $doc = $this->loadXmlSafe($sheet_xml);
        $this->data = array();

        foreach ($doc->getElementsByTagName('row') as $row) {
            $this->data[] = $this->parseRow($row, $shared_strings);
        }

        return !empty($this->data);
    }

    private function extractSharedStrings($zip)
    {
        $shared_strings = array();
        $strings_xml = $zip->getFromName('xl/sharedStrings.xml');

        if (!$strings_xml) {
            return $shared_strings;
        }

        $doc = $this->loadXmlSafe($strings_xml);
        foreach ($doc->getElementsByTagName('t') as $node) {
            $shared_strings[] = $node->nodeValue;
        }

        return $shared_strings;
    }

    private function loadXmlSafe($xml_string)
    {
        $doc = new DOMDocument();
        if (PHP_VERSION_ID < 80000) {
            $libxml_loader = libxml_disable_entity_loader(true);
        }
        $doc->loadXML($xml_string);
        if (PHP_VERSION_ID < 80000) {
            libxml_disable_entity_loader($libxml_loader);
        }
        return $doc;
    }

    private function parseRow($row, $shared_strings)
    {
        $row_data = array();
        $col_index = 0;

        foreach ($row->getElementsByTagName('c') as $cell) {
            $col_letter = preg_replace('/\d+/', '', $cell->getAttribute('r'));
            $target_col = $this->columnIndexFromString($col_letter);

            // Fill empty columns
            while ($col_index < $target_col) {
                $row_data[] = '';
                $col_index++;
            }

            $row_data[] = $this->getCellValue($cell, $shared_strings);
            $col_index++;
        }

        return $row_data;
    }

    private function getCellValue($cell, $shared_strings)
    {
        $value_node = $cell->getElementsByTagName('v')->item(0);

        if (!$value_node) {
            return '';
        }

        if ($cell->getAttribute('t') === 's') {
            $index = (int)$value_node->nodeValue;
            return isset($shared_strings[$index]) ? $shared_strings[$index] : '';
        }

        return $value_node->nodeValue;
    }

    private function columnIndexFromString($column)
    {
        $index = 0;
        $length = strlen($column);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($column[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    private function parseCSV($file_path)
    {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return false;
        }

        $this->data = array();
        while (($row = fgetcsv($handle)) !== false) {
            $this->data[] = $row;
        }

        fclose($handle);
        return !empty($this->data);
    }

    public function rows()
    {
        return $this->data;
    }
}
