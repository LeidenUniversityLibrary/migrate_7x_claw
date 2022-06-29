<?php

namespace Drupal\migrate_7x_claw\Plugin\migrate\process;

use Drupal\migrate_plus\Plugin\migrate_plus\data_parser\XmlTrait;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * This plugin retrieves a piece or pieces from XML via XPath.
 *
 * Use in the following manner:
 * @code
 * my_field:
 *   plugin: xml_xpath
 *   source:
 *     - my_xml
 *     - my_xpath
 * @endcode
 * 
 * @MigrateProcessPlugin(
 *   id = "xml_xpath"
 * )
 */
class XmlXPath extends ProcessPluginBase {
  use XmlTrait;

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    // Only process non-empty values.
    if (empty($value)) {
      return NULL;
    }

    $old_type = gettype($value);
    if (!is_array($value)) {
      throw new MigrateException("Value '$value' is not an array, but '$old_type'.");
    }
    if (count($value) != 2) {
      throw new MigrateException("Value '$value' is not an array with 2 values, but has " . count($value) . " values.");
    }
    $value_type = $this->configuration['value_type'] ?? 'string';

    $result = [];
    
    [$xml, $xpath] = $value;
  
    if (is_string($xml)) {
      // Parse the xml in the value.
      libxml_use_internal_errors(true);
      $error = '';
      try {
        $xml = new \SimpleXMLElement($xml);
      }
      catch (\Exception $e) {
        $error = $e->getMessage() . "\n";
      }
      foreach (libxml_get_errors() as $libxml_err) {
        $error .= self::parseLibXmlError($libxml_err) . "\n";
      }
      if ($error) {
        throw new MigrateException("Error while parsing xml: $error");
      }
    }
    elseif ($xml instanceof \SimpleXMLElement) {
      // $xml is already the right kind!
    }
    else {
      $type = gettype($xml);
      throw new MigrateException("Xml of type '$type' cannot be handled.");
    }

    $this->registerNamespaces($xml);

    $new_values = $xml->xpath($xpath);
    if (!is_array($new_values)) {
      throw new MigrateException(t("Error retrieving value from Xml with XPath @xpath.", ['@xpath' => $xpath]));
    }

    foreach ($new_values as $new_value) {
      $result[] = trim((string)$new_value);
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple() {
    return TRUE;
  }

}
