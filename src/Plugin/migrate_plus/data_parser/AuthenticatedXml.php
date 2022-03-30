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
          if (is_countable($value->children()) && count($value->children()) > 0) {
            // is SimpleXML element with children, so keep it as XML.
            $this->currentItem[$field_name][] = $value->asXML();
          }
          else {
            $this->currentItem[$field_name][] = (string) $value;
          }
        }
      }
      // Add hash and reduce single-value results to scalars.
      foreach ($this->currentItem as $field_name => $values) {
        if ($field_name !== 'hash64') {
          foreach ($values as $index => $value) {
            $this->currentItem[$field_name . '_hash64'][$index] = Crypt::hashBase64($value);
          }
          if (count($values) == 1) {
            $this->currentItem[$field_name] = reset($values);
            $this->currentItem[$field_name . '_hash64'] = reset($this->currentItem[$field_name . '_hash64']);
          }
        }
      }
    }
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
