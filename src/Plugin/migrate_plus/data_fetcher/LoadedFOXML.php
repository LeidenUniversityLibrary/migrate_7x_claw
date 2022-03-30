<?php

namespace Drupal\migrate_7x_claw\Plugin\migrate_plus\data_fetcher;

use Drupal\migrate_plus\Plugin\migrate_plus\data_fetcher\Http;
use Drupal\migrate\MigrateException;
use GuzzleHttp\Psr7\Utils;

/**
 * Obtain FOXML data for migration and load managed content as in-line content.
 *
 * @DataFetcher(
 *   id = "loaded_foxml",
 *   title = @Translation("Loaded FOXML")
 * )
 */
class LoadedFOXML extends Http {

  use \Drupal\migrate_plus\Plugin\migrate_plus\data_parser\XmlTrait;

  /**
   * The base URL of the Fedora repo.
   *
   * @var string
   */
  private $fedoraBase;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    if (!isset($configuration['fedora_base_url'])) {
      throw new MigrateException("Islandora source plugin requires a \"fedora_base_url\" be defined.");
    }
    $this->fedoraBase = rtrim($configuration['fedora_base_url'], '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getResponseContent($url) {
    $response = $this->getResponse($url);
    $body = $response->getBody();
    if ($body && $this->isFedoraObjectXmlUrl($url)) {
      $did_change = FALSE;
      $digital_object = new \SimpleXMLElement($body);
      $this->registerNamespaces($digital_object);
      $digital_object = $digital_object->xpath('/foxml:digitalObject');
      if (!is_array($digital_object) || count($digital_object) == 0) {
        throw new MigrateException(t("Error retrieving digital object."));
      }
      if (count($digital_object) > 1) {
        throw new MigrateException(t("Error retrieving single digital object, found !c digital objects.", ['!c' => count($digital_object)]));
      }
      $digital_object = $digital_object[0]; 
      $pid = $digital_object['PID'];
      $managed_datastreams = $digital_object->xpath('foxml:datastream[@CONTROL_GROUP="M"][@STATE="A"]');
      if (!is_array($managed_datastreams)) {
        throw new MigrateException(t("Error retrieving managed datastreams."));
      }
      foreach ($managed_datastreams as $managed_datastream) {
        $dsid = $managed_datastream['ID'];
        $versionsxpath = 'foxml:datastreamVersion[@MIMETYPE="text/xml" or @MIMETYPE="application/xml" or @MIMETYPE="application/rdf+xml"]';
        if (TRUE) {
          $versionsxpath .= '[last()]';
        }
        $versions = $managed_datastream->xpath($versionsxpath);
        if (!is_array($versions)) {
          throw new MigrateException(t("Error retrieving versions of managed datastreams."));
        }
        foreach ($versions as $version) {
          $created = $version['CREATED'];
          $url = "{$this->fedoraBase}/objects/{$pid}/datastreams/{$dsid}/content?format=xml&asOfDateTime=$created";
          $dsxml = $this->getResponseContent($url)->getContents();
          if ($dsxml) {
            $dsxml = preg_replace('/^<\\?xml.*?\\?>/',  '', $dsxml);
            $domversion = dom_import_simplexml($version);
            $domxmlcontent = $domversion->ownerDocument->createElementNS('info:fedora/fedora-system:def/foxml#', 'foxml:xmlContent');
            $domversion->appendChild($domxmlcontent);
            $fragment = $domversion->ownerDocument->createDocumentFragment();
            $fragment->appendXML($dsxml);

            $domxmlcontent->appendChild($fragment);
            $managed_datastream['CONTROL_GROUP'] = 'X';
            $did_change = TRUE;
          }
        }
      }
      if ($did_change) {
        $newXml = $digital_object->saveXML();
        $body = Utils::streamFor($newXml);
      }
    }
    return $body;
  }

  function isFedoraObjectXmlUrl($url) {
    $objXml = 'objectXML';
    return (substr($url, 0, strlen($this->fedoraBase)) === $this->fedoraBase) && (substr($url, -strlen($objXml)) === $objXml);
  }
}
