<?php

namespace Drupal\migrate_7x_claw\Plugin\migrate_plus\data_parser;

use Drupal\migrate\MigrateException;
use Drupal\migrate_plus\DataParserPluginBase;
use function GuzzleHttp\Psr7\build_query;

/**
 * Obtain datastream info from Solr.
 *
 * @DataParser(
 *   id = "solr_datastreams",
 *   title = @Translation("Solr_datastreams")
 * )
 *
 * The field selector is a Solr field where DSID is replaced by the actual DSID.
 *
 * This will auto-populate the following fields
 *  PID -> PID of the fedora object
 *  PID_DSID -> a concatenated ID for use in media <-> file matching.
 *
 * It also will populate with the datastream ID any field with a selector of
 * DSID.
 */
class SolrDatastream extends DataParserPluginBase {

  /**
   * The base URL for the Solr instance.
   *
   * @var string
   */
  private $solrBase;

  /**
   * Solr datastreams field.
   *
   * @var string
   */
  private $solrDatastreamsField;

  /**
   * Exclude datastreams.
   *
   * @var array
   */
  private $excludeDatastreams;

  /**
   * The current datastreams to view.
   *
   * @var array
   */
  private $datastreams;

  /**
   * The PID for currently loaded object.
   *
   * @var string
   */
  private $PID;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    if (!isset($configuration['solr_base_url'])) {
      throw new MigrateException("Solr datastream data_parser plugin requires a \"solr_base_url\" be defined.");
    }
    $this->solrBase = rtrim($configuration['solr_base_url'], '/');
    $this->solrDatastreamsField = $configuration['datastream_solr_field'] ?? 'fedora_datastreams_ms';
    $this->excludeDatastreams = $configuration['exclude_datastreams'];
  }

  /**
   * {@inheritdoc}
   */
  protected function openSourceUrl($url) {
    $this->datastreams = [];
    $this->PID = NULL;
    $this->solrdoc = NULL;
   
    if (substr($url, 0, strlen($this->solrBase)) === $this->solrBase) {
      // Is a Solr query url
      $query = $url . '&' . build_query(['rows' => 1, 'start' => 0], FALSE); 
      if (!preg_match('~q=PID%3A"([^"]+)"~', $url, $match)) {
        return FALSE;
      }
      $pid = $match[1];
    }
    else {
      // We expect PIDs or Fedora object URLs.
      if (!preg_match('~^(?>' . preg_quote($this->baseUrl) . 'objects/)([^:/]+:[^:/]+)~', $url, $match)) {
        return FALSE;
      }
      $pid = $match[1];

      $query = $this->getQuery($pid);
    }

    $result = $this->getDataFetcherPlugin()->getResponseContent($query)->getContents();
    $body = json_decode($result, TRUE);
    $count = intval($body['response']['numFound']);
    if ($count === 1 && isset($body['response']['docs'][0][$this->solrDatastreamsField])) {
      $this->PID = $pid;
      $solrdoc = $body['response']['docs'][0];
      $excludeDSIDs = array_combine($this->excludeDatastreams, $this->excludeDatastreams); 
 
      foreach ($solrdoc[$this->solrDatastreamsField] as $dsid) {
        if (isset($excludeDSIDs[$dsid])) {
          continue;
        }
        $this->datastreams[$dsid] = ['DSID' => $dsid];
        foreach ($this->fieldSelectors() as $field_name => $property_name) {
          $solrfield = str_replace('DSID', $dsid, $property_name);
          if (isset($solrdoc[$solrfield]) && !isset($this->datastreams[$dsid][$field_name])) {
            $this->datastreams[$dsid][$field_name] = $solrdoc[$solrfield];
          }
        }
      }
      return TRUE;
    }
     
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchNextRow() {
    $datastream = array_shift($this->datastreams);

    if ($datastream) {
      $this->currentItem['PID'] = $this->PID;
      foreach ($this->fieldSelectors() as $field_name => $property_name) {
        $this->currentItem[$field_name] = $datastream[$field_name];
      }
      if (isset($datastream['DSID'])) {
        $this->currentItem['PID_DSID'] = $this->currentItem['PID'] . '_' . $datastream['DSID'];
      }
      // Reduce single-value results to scalars.
      foreach ($this->currentItem as $field_name => $values) {
        if (is_array($values) && count($values) == 1) {
          $this->currentItem[$field_name] = reset($values);
        }
      }
    }
  }

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

  /**
   * Generate a Solr query string.
   *
   * @param string $pid
   *   The PID to search for.
   *
   * @return string
   *   The Full query URL.
   */
  private function getQuery($pid, $additionalFields = NULL) {
    $params = [];
    $params['rows'] = 1;
    $params['start'] = 0;
    $params['q'] = "PID:\"$pid\"";
    if ($additionalFields !== NULL) {
      $params['fl'] = 'PID' . ',' . implode(",", $additionalFields);
    }
    $params['wt'] = 'json';
    return $this->solrBase . "/select?" . build_query($params, FALSE);
  }

}
