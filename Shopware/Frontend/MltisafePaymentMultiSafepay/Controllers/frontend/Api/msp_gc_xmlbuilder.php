<?php

/**
 * Classes used to generate XML data
 * Based on sample code available at http://simon.incutio.com/code/php/XmlWriter.class.php.txt 
 */

/**
 * Generates xml data
 */
class msp_gc_XmlBuilder {

    var $xml;
    var $indent;
    var $stack = array();

    /**
     * msp_gc_XmlBuilder construct
     */
    function msp_gc_XmlBuilder($indent = '  ') {
        $this->indent = $indent;
        $this->xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    }

    /**
     * _indent function for xml builder
     */
    function _indent() {
        for ($i = 0, $j = count($this->stack); $i < $j; $i++) {
            $this->xml .= $this->indent;
        }
    }

    /**
     * Used when an element has sub-elements
     * This function adds an open tag to the output
     */
    function Push($element, $attributes = array()) {
        $this->_indent();
        $this->xml .= '<' . $element;
        foreach ($attributes as $key => $value) {
            $this->xml .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }
        $this->xml .= ">\n";
        $this->stack[] = $element;
    }

    /**
     * Used when an element has no subelements.
     * Data within the open and close tags are provided with the 
     * contents variable 
     */
    function Element($element, $content, $attributes = array()) {
        $this->_indent();
        $this->xml .= '<' . $element;
        foreach ($attributes as $key => $value) {
            $this->xml .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }
        $this->xml .= '>' . htmlspecialchars($content) . '</' . $element . '>' . "\n";
    }

    /**
     * EmptyElement funtion for xml builder
     */
    function EmptyElement($element, $attributes = array()) {
        $this->_indent();
        $this->xml .= '<' . $element;
        foreach ($attributes as $key => $value) {
            $this->xml .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }
        $this->xml .= " />\n";
    }

    /**
     * Pop functon, used to close an open tag
     */
    function Pop($pop_element) {
        $element = array_pop($this->stack);
        $this->_indent();
        if ($element !== $pop_element)
            die('XML Error: Tag Mismatch when trying to close "' . $pop_element . '"');
        else
            $this->xml .= "</$element>\n";
    }

    /**
     * getXml for the xmnl builder
     */
    function GetXML() {
        if (count($this->stack) != 0)
            die('XML Error: No matching closing tag found for " ' . array_pop($this->stack) . '"');
        else
            return $this->xml;
    }

}

?>