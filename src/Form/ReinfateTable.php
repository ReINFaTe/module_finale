<?php

namespace Drupal\reinfate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class that provide table form.
 */
class ReinfateTable extends FormBase {

  /**
   * Table headers.
   *
   * @var string[]
   */
  protected array $headers;

  /**
   * Table headers that should be filled by the server.
   *
   * @var string[]
   */
  protected array $computedHeaders;

  /**
   * Amount of tables to be built.
   *
   * @var int
   */
  protected int $tables = 1;

  /**
   * Amount of rows to be built for each table.
   *
   * @var int
   */
  protected int $rows = 1;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->setMessenger($container->get('messenger'));
    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'reinfate_table';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div id="reinfate-form-wrapper">';
    $form['#suffix'] = '</div>';
    $form['addTable'] = [
      '#type' => 'submit',
      '#value' => $this->t("Add table"),
      '#validate' => [],
      '#limit_validation_errors' => [],
      '#submit' => ['::addTable'],
      '#ajax' => [
        'callback' => '::refreshAjax',
        'wrapper' => 'reinfate-form-wrapper',
      ],
    ];
    $form['addRow'] = [
      '#type' => 'submit',
      '#value' => $this->t("Add row"),
      '#limit_validation_errors' => [],
      '#submit' => ['::addRow'],
      '#ajax' => [
        'callback' => '::refreshAjax',
        'wrapper' => 'reinfate-form-wrapper',
      ],
    ];

    $this->buildHeaders($form, $form_state);
    $this->buildTables($form, $form_state);

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t("Submit"),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::refreshAjax',
        'wrapper' => 'reinfate-form-wrapper',
      ],
    ];

    $form['#attached']['library'][] = 'reinfate/reinfate_table';
    return $form;
  }

  /**
   * Build tables.
   */
  protected function buildTables(array &$form, FormStateInterface $form_state) {
    for ($i = 0; $i < $this->tables; $i++) {
      $tableKey = 'table-' . ($i + 1);
      $form[$tableKey] = [
        '#type' => 'table',
        '#tree' => TRUE,
        '#header' => $this->headers,
      ];
      $this->buildRows($tableKey, $form[$tableKey], $form_state);
    }
  }

  /**
   * Build rows.
   */
  protected function buildRows(string $tableKey, array &$table, FormStateInterface $form_state) {
    for ($i = $this->rows; $i > 0; $i--) {
      foreach ($this->headers as $key => $value) {
        $table[$i][$key] = [
          '#type' => 'number',
          '#step' => '0.01',
        ];
        // Some additions to fields that should be calculated on the server.
        if (array_key_exists($key, $this->computedHeaders)) {
          // Set default value linked to form_state,
          // so we can change displayed value for user.
          $value = $form_state->getValue($tableKey . '][' . $i . '][' . $key, 0);
          $table[$i][$key]['#disabled'] = TRUE;
          $table[$i][$key]['#default_value'] = round($value, 2);
        }
      }
      $table[$i]['year']['#default_value'] = date('Y') - $i + 1;
    }
  }

  /**
   * Add a table to the form.
   */
  public function addTable(array &$form, FormStateInterface $form_state) {
    $this->tables++;
    $form_state->setRebuild();
    return $form;
  }

  /**
   * Add a row to the form.
   */
  public function addRow(array &$form, FormStateInterface $form_state) {
    $this->rows++;
    $form_state->setRebuild();
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $values = $this->clearValues($values);
    $this->validateTables($values, $form_state);
  }

  /**
   * Remove calculated fields.
   */
  protected function clearValues(array $values) {
    foreach ($values as $key => &$value) {
      // Leave only table fields.
      if (!str_starts_with($key, 'table-')) {
        unset($values[$key]);
      }
      // Remove calculated values from rows.
      else {
        foreach ($value as &$row) {
          $row = array_diff_key($row, $this->computedHeaders);
        }
      }
    }
    return $values;
  }

  /**
   * Validate tables.
   */
  protected function validateTables(array $values, FormStateInterface $form_state) {
    $tablesPlainValues = [];
    foreach ($values as $tableKey => $table) {
      // Store all rows values in a single array for easier access.
      $plainValues = &$tablesPlainValues[$tableKey];
      $input = FALSE;
      foreach ($table as $rowKey => $row) {
        foreach ($row as $cellKey => $cell) {
          // We can set errorsByName with key from this array.
          $plainValues[$tableKey . '][' . $rowKey . '][' . $cellKey] = $cell;

          // Also check if tables has any input from user.
          if (!empty($cell) && $input === FALSE) {
            $input = TRUE;
          }

          /*
           * Also check if tables are similar.
           *
           * Skip first iteration, as there
           * no point to compare the first table to itself.
           */
          if ($tableKey !== 'table-1') {
            // Check if cell empty in one table but not another.
            if (empty($cell) !== empty($values['table-1'][$rowKey][$cellKey])) {
              // If cells are not similar, set error on this cell at all tables.
              for ($i = 1; $i <= count($values); $i++) {
                $form_state->setErrorByName(
                  'table-' . $i . '][' . $rowKey . '][' . $cellKey,
                  'Tables should be similar');
              }
            }
          }
        }
      }

      if ($input) {
        // Delete empty values from array ends.
        while (empty($plainValues[array_key_first($plainValues)])) {
          array_shift($plainValues);
        }
        while (empty($plainValues[array_key_last($plainValues)])) {
          array_pop($plainValues);
        }
        // If there empty values between, the table isn't valid.
        foreach ($plainValues as $cellKey => $cell) {
          // Set errors on empty cells.
          if (empty($cell)) {
            $form_state->setErrorByName($cellKey, 'Table should not contain breaks');
          }
        }
      }
      // If no input, table isn't valid.
      else {
        $form_state->setErrorByName($tableKey, 'Table should not be empty');
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    foreach ($values as $tableKey => $table) {
      foreach ($table as $rowKey => $row) {

        $rowPath = $tableKey . '][' . $rowKey . '][';

        $q1 = ($row['jan'] + $row['feb'] + $row['mar'] + 1) / 3;
        $q2 = ($row['apr'] + $row['may'] + $row['jun'] + 1) / 3;
        $q3 = ($row['jul'] + $row['aug'] + $row['sep'] + 1) / 3;
        $q4 = ($row['oct'] + $row['nov'] + $row['dec'] + 1) / 3;
        $ytd = ($q1 + $q2 + $q3 + $q4 + 1) / 4;

        $form_state->setValue($rowPath . 'q1', $q1);
        $form_state->setValue($rowPath . 'q2', $q2);
        $form_state->setValue($rowPath . 'q3', $q3);
        $form_state->setValue($rowPath . 'q4', $q4);
        $form_state->setValue($rowPath . 'ytd', $ytd);
      }
    }
    $this->messenger->addStatus('Valid');
    $form_state->setRebuild();
  }

  /**
   * Ajax response to refresh form.
   */
  public function refreshAjax(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * Build headers.
   */
  protected function buildHeaders() {
    $this->headers = [
      'year' => $this->t('Year'),
      'jan' => $this->t('Jan'),
      'feb' => $this->t('Feb'),
      'mar' => $this->t('Mar'),
      'q1' => $this->t('Q1'),
      'apr' => $this->t('Apr'),
      'may' => $this->t('May'),
      'jun' => $this->t('Jun'),
      'q2' => $this->t('Q2'),
      'jul' => $this->t('Jul'),
      'aug' => $this->t('Aug'),
      'sep' => $this->t('Sep'),
      'q3' => $this->t('Q3'),
      'oct' => $this->t('Oct'),
      'nov' => $this->t('Nov'),
      'dec' => $this->t('Dec'),
      'q4' => $this->t('Q4'),
      'ytd' => $this->t('YTD'),
    ];
    $this->computedHeaders = [
      'year' => $this->t('Year'),
      'q1' => $this->t('Q1'),
      'q2' => $this->t('Q2'),
      'q3' => $this->t('Q3'),
      'q4' => $this->t('Q4'),
      'ytd' => $this->t('YTD'),
    ];
  }

}
