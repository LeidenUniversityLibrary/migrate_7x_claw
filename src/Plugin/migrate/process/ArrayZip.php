<?php

namespace Drupal\migrate_7x_claw\Plugin\migrate\process;

use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * This plugin zips two or more arrays into each other.
 *
 * For example, the following input:
 * @code
 * array(
 *   array(1, 2, 3),
 *   array('a', 'b', 'c'),
 * )
 * @endcode
 * is tranformed to:
 * @code
 * array(
 *  array(1, 'a'),
 *  array(2, 'b'),
 *  array(3, 'c'),
 * )
 * @endcode
 *
 * It is also possible to give specific keys.
 * Assuming first is array(1, 2, 3) and second is array('a', 'b', 'c')
 * and the following configuration:
 * @code
 * plugin: array_zip
 * source:
 *  - first
 *  - second
 * keys:
 *  - number
 *  - letter
 * @endcode
 * would give the following output:
 * @code
 * array(
 *   array(
 *     'number' => 1,
 *     'letter' => 'a',
 *   ),
 *   array(
 *     'number' => 2,
 *     'letter' => 'b',
 *   ),
 *   array(
 *     'number' => 3,
 *     'letter' => 'b',
 *   ),
 * )
 * @endcode
 *
 * If the source is an array of associative arrays, the keys can also be used
 * to retrieve the values from the associative arrays.
 * For example, if first is array('number' => 1) and second is array('letter'
 *  => 'a') then the output will be (with the above configuration):
 * @code
 * array(
 *   array(
 *     'number' => 1,
 *     'letter' => 'a',
 *   ),
 * )
 * @endcode
 * The source cannot be an associative array itself.
 *
 * Also, this can be used to build a associative array with single values:
 * Input first and second can be string values, say "first" and "second".
 * Then this would give the following output with the same configuration:
 * @code
 * array(
 *   array(
 *     'number' => 'first',
 *     'letter' => 'second',
 *   ),
 * )
 * @endcode
 *
 * If there are less keys than source values, than some of the source values
 * will not be used.
 *
 * Another usage is to zip two arrays together and coalasce the values:
 * For example, the following input has some NULL values:
 * @code
 * array(
 *   array('', NULL, 'B', 'C', NULL),
 *   array('_', 'a', 'b', NULL, NULL),
 * )
 * @endcode
 * Using without coalesce, the result would be:
 *   array(['','_'], [NULL, 'a'], ['B', 'b'], ['C', NULL], [NULL, NULL])
 *
 * Using coalesce: 'null' will give the result:
 *   array('', 'a', 'B', 'C', NULL).
 *
 * USing coalesce: 'empty' will give the result:
 *   array('_', 'a', 'B', 'C', NULL).
 *
 * @MigrateProcessPlugin(
 *   id = "array_zip",
 *   handle_multiples = TRUE
 * )
 */
class ArrayZip extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Only process non-empty values.
    if (empty($value)) {
      return NULL;
    }
    if (!is_array($value)) {
      $type = gettype($value);
      throw new MigrateException("Source for array_zip should be an array, but is '$type'.");
    }
    if (isset($this->configuration['keys']) && !is_array($this->configuration['keys'])) {
      $type = gettype($this->configuration['keys']);
      throw new MigrateException("Keys for array_zip should be an array, but is '$type'.");
    }
    $allowed_coalesce = ['null', 'empty'];
    if (isset($this->configuration['coalesce']) && !in_array($this->configuration['coalesce'], $allowed_coalesce, TRUE)) {
      throw new MigrateException("coalesce should be either 'null' or 'empty'.");
    }

    $has_keys = isset($this->configuration['keys']);
    $keys = $this->configuration['keys'] ?? range(0, count($value));
    $output = [];
    for ($i = 0; TRUE; $i++) {
      $item = [];
      foreach ($keys as $index => $key) {
        if (array_key_exists($index, $value) && !is_null($value[$index])) {
          if (!is_array($value[$index]) && ($i === 0)) {
            $item[$key] = $value[$index];
          }
          elseif (is_array($value[$index]) && !$has_keys && array_key_exists($i, $value[$index])) {
            $item[$key] = $value[$index][$i];
          }
          elseif (is_array($value[$index]) && $has_keys && array_key_exists($key, $value[$index])) {
            $item[$key] = $value[$index][$key];
          }
        }
      }
      if (empty($item)) {
        break;
      }
      $output[] = $item;
    }
    if (isset($this->configuration['coalesce'])) {
      $coalesce_method = $this->configuration['coalesce'] . 'Coalesce';
      $coalesce_output = [];
      foreach ($output as $item) {
        $coalesce_output[] = $this->$coalesce_method($item);
      }
      $output = $coalesce_output;
    }
    return $output;
  }

  /**
   * Null coalesce helper function.
   *
   * @param array $values
   *   An array of values.
   *
   * @return object|array|string|float|int|bool|null
   *   Returns the first item of $values that is not null.
   */
  private function nullCoalesce(array $values) {
    $return = NULL;
    foreach ($values as $value) {
      if (!is_null($value)) {
        $return = $value;
        break;
      }
    }
    return $return;
  }

  /**
   * Empty coalesce helper function.
   *
   * @param array $values
   *   An array of values.
   *
   * @return object|array|string|float|int|bool|null
   *   Returns the first item of $values that is not empty.
   */
  private function emptyCoalesce(array $values) {
    $return = NULL;
    foreach ($values as $value) {
      if (!empty($value)) {
        $return = $value;
        break;
      }
    }
    return $return;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple() {
    return TRUE;
  }

}
