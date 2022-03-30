<?php

namespace Drupal\migrate_7x_claw\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * This plugin checks if the input value is an array containing specific values.
 * If it is only the value, it is wrapped in an array.
 * If it is not an array containing the value, the value can be cast in the necessary value.
 *
 * For example, the following input:
 * @code
 *   'test1'
 * @endcode
 * is tranformed to:
 * @code
 * array(
 *  0 => 'test1'
 * )
 * @endcode
 * using the following settings:
 * @code
 * my_field:
 *   source: my_source
 *   plugin: array_with_value
 *   value_type: string
 * @endcode
 *
 * But the following input:
 * @code
 * array(
 *   0 => 'test1'
 * )
 * @endcode
 * is tranformed to:
 * @code
 * array(
 *   array(
 *     0 => 'test1'
 *   )
 * )
 * @endcode
 * using the following settings:
 * @code
 * my_field:
 *   source: my_source
 *   plugin: array_with_value
 *   value_type: array
 * @endcode
 * but stays the same (is untouched) when using value_type: string
 *
 * The following value_types are supported: 
 * array, string, int (or integer), float (or double), bool (or boolean), object.
 * Casting from object to array is supported.
 * 
 * @MigrateProcessPlugin(
 *   id = "array_with_value"
 *   handle_multiples = TRUE
 * )
 */
class ArrayWithValue extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    // Only process non-empty values.
    if (empty($value)) {
      return NULL;
    }

    $value_type = $this->configuration['value_type'] ?? 'anything';

    // Check if it is already an array.
    if (is_array($value)) {
      switch ($value_type) {
        case "array":
          // Check if value is a non-associative array, should have a value at the 0 index.
          if (array_key_exists(0, $value)) {
            // Cast containing objects to arrays if only objects.
            $containsObjects = TRUE;
            foreach ($value as $v) {
              if (!is_object($v)) {
                $containsObjects = FALSE;
                break;
              }
            }
            if ($containsObjects) {
              // $value only contains objects, so cast them.
              $newarray = [];
              foreach ($value as $k => $v) {
                $old_type = gettype($v);
                if (!settype($v, $value_type)) {
                  throw new MigrateException("Value '$value' of type '$old_type' cannot be cast to type '$value_type'.");
                }
                $newarray[$k] = $v;
              }
              $value = $newarray;
            }
            else {
              // Check if all containing values are arrays.
              $containsArrays = TRUE;
              foreach ($value as $v) {
                if (!is_array($v)) {
                  $containsArrays = FALSE;
                  break;
                } 
              }
              // $value does not only contain arrays, so wrap it in an array.
              if (!$containsArrays) {
                $value = [$value];
              }
            }
          }
          else {
            // $value is an associative array, so wrap it in an array.
            $value = [$value];
          }
          break;
        case "anything":
          break;
        case "object":
          // Only cast to object if $value only contains arrays.
          $containsArrays = TRUE;
          foreach ($value as $v) {
            if (!is_array($v)) {
              $containsArrays = FALSE;
              break;
            } 
          }
          if (!$containsArrays) {
            throw new MigrateException("Value '$value' of type that is not array is not cast to object.");
          }
          // Pass through.
        case "string":
        case "int":
        case "integer":
        case "float":
        case "double":
        case "bool":
        case "boolean":
          $newarray = [];
          foreach ($value as $k => $v) {
            $old_type = gettype($v);
            if (!settype($v, $value_type)) {
              throw new MigrateException("Value '$value' of type '$old_type' cannot be cast to type '$value_type'.");
            }
            $newarray[$k] = $v;
          }
          $value = $newarray;
          break;
        default:
          throw new MigrateException("Cannot cast to value_type '$value_type'.");
          break;
      }
    }
    else {
      $old_type = gettype($value);
      switch ($value_type) {
        case "array":
          if ($old_type !== 'object') {
            throw new MigrateException("Value '$value' of type '$old_type' is not cast to array.");
          }
          // Pass through.
        case "object":
          if ($old_type !== 'array') {
            throw new MigrateException("Value '$value' of type '$old_type' is not cast to object.");
          }
          // Pass through.
        case "string":  
        case "int":  
        case "integer":
        case "float":
        case "double":
        case "bool":
        case "boolean":
          if (!settype($value, $value_type)) {
            throw new MigrateException("Value '$value' of type '$old_type' cannot be cast to type '$value_type'.");
          }
          break;
        case "anything":
          break;
        default:
          throw new MigrateException("Cannot cast to value_type '$value_type'.");
          break;
      }
      $value = [$value];
    }

    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple() {
    return TRUE;
  }

}
