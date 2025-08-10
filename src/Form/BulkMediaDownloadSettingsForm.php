<?php

namespace Drupal\bulk_media_download\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure bulk media download settings for this site.
 */
class BulkMediaDownloadSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Construct function.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory load.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['bulk_media_download.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bulk_media_download_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('bulk_media_download.settings');

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('This configuration form allows you to set up bulk download for specific 
      fields. You can select field types and field names that should be included in the operation. 
      After saving the configuration changes, these settings will be used to download files.'),
    ];

    // Headers for the table.
    $header = [
      $this->t('View'),
      $this->t('Field Type'),
      $this->t('Field Name'),
      $this->t('Operation'),
    ];

    // Multi-value table form.
    $form['bulk_media_download_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => $this->t('There are no items yet. Add an item.'),
      '#prefix' => '<div id="custom-bulk-operation-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    // Load all field configurations.
    $fields = $this->entityTypeManager->getStorage('field_storage_config')->loadMultiple();
    $fields_list = ['file' => [], 'media' => []];

    $views = $this->entityTypeManager->getStorage('view')->loadMultiple();
    $views_list = [];

    foreach ($views as $view_machine_name => $view) {
      $views_list[$view_machine_name] = $view->get('label');
    }
    // Filter fields.
    foreach ($fields as $field) {
      $field_type = $field->getType();
      $target_type = $field->getSetting('target_type') ?? '';

      if ($field_type === 'entity_reference') {
        $field_name = $field->getName();
        if ($target_type === 'media') {
          $fields_list['media'][$field_name] = $field_name;
        }
      }
      elseif ($field_type === 'file') {
        $field_name = $field->getName();
        $fields_list['file'][$field_name] = $field_name;
      }
    }

    $bulk_media_download_table = $form_state->get('bulk_media_download_table');
    if (empty($bulk_media_download_table)) {
      if ($config->get('bulk_media_download_table')) {
        $bulk_media_download_table = $config->get('bulk_media_download_table');
        $form_state->set('bulk_media_download_table', $bulk_media_download_table);
      }
      else {
        $bulk_media_download_table = [['field_type' => '', 'field_name' => '']];
        $form_state->set('bulk_media_download_table', $bulk_media_download_table);
      }
    }

    // Provide ability to remove first element.
    if (isset($bulk_media_download_table['removed']) && $bulk_media_download_table['removed']) {
      reset($bulk_media_download_table);
      unset($bulk_media_download_table['removed']);
    }

    foreach ($bulk_media_download_table as $i => $value) {

      $form['bulk_media_download_table'][$i]['view'] = [
        '#type' => 'select',
        '#title' => $this->t('View'),
        '#title_display' => 'invisible',
        '#options' => $views_list,
        '#default_value' => $value['view'] ?? [],
        '#ajax' => [
          'callback' => '::updateFieldNames',
          'wrapper' => 'custom-bulk-operation-fieldset-wrapper',
          'event' => 'change',
        ],
      ];

      $form['bulk_media_download_table'][$i]['field_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Field Type'),
        '#title_display' => 'invisible',
        '#options' => [
          'file' => $this->t('File'),
          'media' => $this->t('Media'),
        ],
        '#default_value' => $value['field_type'] ?? '',
        '#ajax' => [
          'callback' => '::updateFieldNames',
          'wrapper' => 'custom-bulk-operation-fieldset-wrapper',
          'event' => 'change',
        ],
      ];
      $selected_field_type = $form_state->getValue(['bulk_media_download_table', $i, 'field_type']) ?? $value['field_type'] ?? '';

      $form['bulk_media_download_table'][$i]['field_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Field Name'),
        '#title_display' => 'invisible',
        '#options' => $fields_list[$selected_field_type] ?? [],
        '#default_value' => $value['field_name'] ?? '',
      ];

      $form['bulk_media_download_table'][$i]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => "remove-" . $i,
        '#submit' => ['::removeRow'],
        '#limit_validation_errors' => [],
        '#ajax' => [
          'callback' => '::removeCallback',
          'wrapper' => 'custom-bulk-operation-fieldset-wrapper',
        ],
        '#index_position' => $i,
      ];
    }
    $form['add_name'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add one more'),
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addMoreCallback',
        'wrapper' => 'custom-bulk-operation-fieldset-wrapper',
      ],
    ];
    $form_state->setCached(FALSE);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Callback for ajax-enabled add buttons.
   */
  public function addMoreCallback(array &$form, FormStateInterface $form_state) {
    return $form['bulk_media_download_table'];
  }

  /**
   * Submit handler for the "Add one more" button.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $bulk_media_download_table = $form_state->get('bulk_media_download_table');
    array_push($bulk_media_download_table, ['field_type' => '', 'field_name' => '']);
    $form_state->set('bulk_media_download_table', $bulk_media_download_table);
    $form_state->setRebuild();
  }

  /**
   * Callback to remove the element from table.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    return $form['bulk_media_download_table'];
  }

  /**
   * Remove the element from table.
   */
  public function removeRow(array &$form, FormStateInterface $form_state) {
    // Get table.
    $bulk_media_download_table = $form_state->get('bulk_media_download_table');
    $remove = key($form_state->getValue('bulk_media_download_table'));
    unset($bulk_media_download_table[$remove]);
    if (empty($bulk_media_download_table)) {
      array_push($bulk_media_download_table, ['field_type' => '', 'field_name' => '']);
    }

    $bulk_media_download_table['removed'] = TRUE;
    $form_state->set('bulk_media_download_table', $bulk_media_download_table);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback to update field names based on field type selection.
   */
  public function updateFieldNames(array &$form, FormStateInterface $form_state) {
    return $form['bulk_media_download_table'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the submitted values.
    $submitted_values = $form_state->getValue('bulk_media_download_table');

    $this->config('bulk_media_download.settings')
      ->set('bulk_media_download_table', $submitted_values)
      ->set('selected_view', $submitted_values)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
