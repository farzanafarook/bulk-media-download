<?php

namespace Drupal\bulk_media_download\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\views_bulk_operations\Form\ViewsBulkOperationsFormTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a 'Download file as ZIP archive' action.
 *
 * @Action(
 *   id = "bulk_media_download_bulk_download_media",
 *   label = @Translation("Download file as ZIP archive"),
 *   type = ""
 * )
 */
class BulkDownloadMedia extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;
  use ViewsBulkOperationsFormTrait;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    return $entity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $objects) {
    $results = [];
    foreach ($objects as $entity) {
      $results[] = $entity->id();
    }
    $temp = \Drupal::service('tempstore.private')->get('bulk_media_download');
    $temp->delete('selected_entity');
    $temp->set('selected_entity', $results);
    // Retrieve the configured view from settings.
    $config = \Drupal::config('bulk_media_download.settings');
    $selected_view = $config->get('selected_view') ?? 'default_view';
    // Store the view in temp storage for use in finished().
    $temp->set('selected_view', $selected_view);
    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public static function finished($success, array $results, array $operations): ?RedirectResponse {
    $download_url = Url::fromRoute('bulk_media_download.download_zip')->toString();
    $build = [
      '#type' => 'container',
      '#markup' => t('Download will start automatically. If you face a problem <a href=":url" class="download-link">click here to download</a>', [':url' => $download_url]),
      '#attributes' => ['class' => ['download-file']],
    ];
    $message = \Drupal::service('renderer')->renderPlain($build);
    \Drupal::messenger()->addMessage($message);
    $config = \Drupal::config('bulk_media_download.settings');
    $selected_views = $config->get('selected_view');
    $view_id = $selected_views[0]['view'];
    $route_name = 'view.' . $view_id . '.page_1';
    $redirect_url = Url::fromRoute($route_name)->toString();
    return new RedirectResponse($redirect_url);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
