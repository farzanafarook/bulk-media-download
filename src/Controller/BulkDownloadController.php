<?php

namespace Drupal\bulk_media_download\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Class BulkDownloadController does.
 */
class BulkDownloadController extends ControllerBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The temporary store private service.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The configuration factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a BulkDownloadController object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\file\FileRepositoryInterface $file_repository
   *   The file repository service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The temporary store private service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   */
  public function __construct(FileSystemInterface $file_system, $file_repository, $temp_store_factory, $entity_type_manager, $config_factory) {
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
    $this->tempStoreFactory = $temp_store_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('tempstore.private'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * Download selected files as a zip.
   */
  public function downloadZip() {
    $temp = $this->tempStoreFactory->get('bulk_media_download');
    $selected_entity = $temp->get('selected_entity');

    $config = $this->configFactory->get('bulk_media_download.settings');
    $filename = $config->get('bulk_media_download_zip_filename') ?: 'Download' . '.zip';

    $bulk_media_download_table = $config->get('bulk_media_download_table');
    if (empty($bulk_media_download_table)) {
      $this->messenger()->addError($this->t('No fields are selected, please select them from the config form.'));
      return $this->redirect('<front>');
    }

    $wrapper = 'temporary';
    $destination = $wrapper . '://' . $filename;
    $system_destination = $this->fileSystem->realpath($destination);

    $file = $this->fileRepository->writeData('', $destination, FileSystemInterface::EXISTS_REPLACE);
    $zip = new \ZipArchive();
    $zip->open($system_destination, \ZipArchive::OVERWRITE);
    foreach ($bulk_media_download_table as $values) {
      $field_name = $values['field_name'];
      $field_type = $values['field_type'];
      if (!empty($selected_entity) && $field_name) {
        $entities = $this->entityTypeManager->getStorage('node')->loadMultiple($selected_entity);
        foreach ($entities as $entity) {
          if ($entity instanceof NodeInterface) {
            if ($entity->hasField($field_name)) {
              if ($field_type == 'media') {
                $media = $entity->get($field_name)->entity;
                if ($media instanceof MediaInterface) {
                  foreach ($media->getFields() as $media_field_name => $media_field) {
                    if ($media_field->getFieldDefinition()->getType() === 'file' || $media_field->getFieldDefinition()->getType() === 'image') {
                      $file_entity = $media->get($media_field_name)->entity;
                      if ($file_entity instanceof FileInterface) {
                        $file_path = $this->fileSystem->realpath($file_entity->getFileUri());
                        $zip->addFile($file_path, $file_entity->getFilename());
                      }
                      else {
                        $this->messenger()->addError($this->t('Failed to get file entity from media field: @field', ['@field' => $media_field_name]));
                      }
                    }
                  }
                }
              }
              elseif ($field_type == 'file') {
                foreach ($entity->get($field_name)->referencedEntities() as $file_entity) {
                  if ($file_entity instanceof FileInterface) {
                    $file_path = $this->fileSystem->realpath($file_entity->getFileUri());
                    $zip->addFile($file_path, $file_entity->getFilename());
                  }
                  else {
                    $this->messenger()->addError($this->t('Failed to get file entity from file field: @field', ['@field' => $field_name]));
                  }
                }
              }
            }
            else {
              $this->messenger()->addError($this->t('Entity does not have the specified field: @field', ['@field' => $field_name]));
            }
          }
        }
      }
    }

    $zip->close();
    $file->setTemporary();
    $file->save();

    $headers = [
      'Content-Type' => 'application/zip',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      'Content-Length' => filesize($system_destination),
      'Content-Description' => 'File Transfer',
    ];

    $response = new BinaryFileResponse($system_destination, 200, $headers, TRUE);
    $response->send();

    $this->fileSystem->unlink($system_destination);
    return $response;
  }

}
