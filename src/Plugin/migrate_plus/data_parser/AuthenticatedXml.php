<?php

namespace Drupal\migrate_7x_claw\Plugin\migrate_plus\data_parser;

use Drupal\migrate_plus\Plugin\migrate_plus\data_parser\SimpleXml;
use Drupal\migrate\MigrateException;
use Drupal\Component\Utility\Crypt;

/**
 * Obtain XML data for migration using the XMLReader pull parser.
 * Also add hash64 for entire XML and keyName_hash64 for every key.
 *
 * @DataParser(
 *   id = "authenticated_xml",
 *   title = @Translation("Authenticated XML")
 * )
 */
class AuthenticatedXml extends SimpleXml {

  /**
   * Update the configuration for the dataparserplugin.
   *
   * The XML dataParserPlugin assumes you give it all the URLs to start,
   * but I am dynamically generating them based on the batch.
   *
   * @param array|string $urls
   *   New array of URLs to add to the FedoraDatastream processor.
   */
  public function updateUrls($urls) {
    if (!is_array($urls)) {
      $urls = [$urls];
    }
    $this->urls = $urls;
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchNextRow() {
    $target_element = array_shift($this->matches);
    if (is_null($target_element)) {
      return;
    }
    // If we've found the desired element, populate the currentItem and
    // currentId with its data.
    $this->currentItem = [];
    if ($target_element !== FALSE && !is_null($target_element)) {
      $this->registerNamespaces($target_element);
      $this->currentItem['hash64'] = Crypt::hashBase64($target_element->asXML());
      foreach ($this->fieldSelectors() as $field_name => $xpath) {
        $values = $target_element->xpath($xpath);
        if (!is_array($values)) {
          throw new MigrateException(t("Error retrieving field @field with XPath @xpath.", ['@field' => $field_name, '@xpath' => $xpath]));
        }
        foreach ($values as $value) {
          if ($this->all_child_elements_count($value) > 0) {
            // Is SimpleXML element with children, so keep it as XML.
            $xml = $value->asXML();
            // First, calculate the base65 hase before modifying the Xml.
            $this->currentItem[$field_name . '_hash64'][] = Crypt::hashBase64($xml);
            // Then add the namespace declaration to this piece of XML.
            $first_close = strpos($xml, '>');
            if ($first_close) {
              if (substr($xml, $first_close - 1, 1) == '/') {
                // the xml is something like '<element/>', so position before the /.
                $first_close = $first_close - 1;
              }
              $new_xml = substr($xml, 0, $first_close);
              $namespaces = $value->getNamespaces(TRUE);
              foreach ($namespaces as $nsprefix => $nsuri) {
                $new_xml .= " xmlns:$nsprefix=\"$nsuri\"";
              }
              $new_xml .= substr($xml, $first_close);
              $xml = $new_xml;
            }
            $this->currentItem[$field_name][] = $xml;
          }
          else {
            $this->currentItem[$field_name . '_hash64'][] = Crypt::hashBase64((string) $value);
            $this->currentItem[$field_name][] = (string) $value;
          }
        }
      }
      // Add hash and reduce single-value results to scalars.
      foreach ($this->currentItem as $field_name => $values) {
        if ($field_name !== 'hash64') {
          if (count($values) == 1) {
            $this->currentItem[$field_name] = reset($values);
          }
        }
      }
    }
  }

  /**
   * Helper function to obtain the count for all child elements, regardless of namespace(s).
   */
  private function all_child_elements_count(\SimpleXMLElement $simplexml) {
    $children = $simplexml->xpath('child::*');
    if (is_array($children)) {
      return count($children);
    }
    return 0;
  }

  /**
   * {@inheritdoc}
   *
   * Islandora Source can provide 0 urls, we need to exit or it throws an
   * error.
   */
  protected function nextSource() {
    if (count($this->urls) == 0) {
      return FALSE;
    }
    return parent::nextSource();
  }

}
