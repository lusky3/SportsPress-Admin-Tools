<?php
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
        if ($zip->open($file_path) !== TRUE) {
            return false;
        }

        // Read shared strings
        $shared_strings = array();
        $strings_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($strings_xml) {
            $strings_doc = new DOMDocument();

            // Prevent XXE attacks
            $libxml_loader = libxml_disable_entity_loader(true);
            $strings_doc->loadXML($strings_xml);
            libxml_disable_entity_loader($libxml_loader);

            $string_nodes = $strings_doc->getElementsByTagName('t');
            foreach ($string_nodes as $node) {
                $shared_strings[] = $node->nodeValue;
            }
        }

        // Read worksheet
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheet_xml) {
            $zip->close();
            return false;
        }

        $zip->close();

        // Parse worksheet XML
        $doc = new DOMDocument();

        // Prevent XXE attacks
        $libxml_loader = libxml_disable_entity_loader(true);
        $doc->loadXML($sheet_xml);
        libxml_disable_entity_loader($libxml_loader);

        $rows = $doc->getElementsByTagName('row');
        $this->data = array();

        foreach ($rows as $row) {
            $row_data = array();
            $cells = $row->getElementsByTagName('c');
            $col_index = 0;

            foreach ($cells as $cell) {
                $cell_ref = $cell->getAttribute('r');
                $col_letter = preg_replace('/\d+/', '', $cell_ref);
                $target_col = $this->columnIndexFromString($col_letter);

                // Fill empty columns
                while ($col_index < $target_col) {
                    $row_data[] = '';
                    $col_index++;
                }

                $value = '';
                $type = $cell->getAttribute('t');
                $value_node = $cell->getElementsByTagName('v')->item(0);

                if ($value_node) {
                    if ($type === 's') {
                        // Shared string
                        $index = (int)$value_node->nodeValue;
                        $value = isset($shared_strings[$index]) ? $shared_strings[$index] : '';
                    }
                    else {
                        $value = $value_node->nodeValue;
                    }
                }

                $row_data[] = $value;
                $col_index++;
            }

            $this->data[] = $row_data;
        }

        return !empty($this->data);
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