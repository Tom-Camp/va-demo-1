<?php

namespace Drupal\webform_access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\WebformEntityReferenceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to define a webform access group.
 */
class WebformAccessGroupForm extends EntityForm {

  /**
   * The database object.
   *
   * @var object
   */
  protected $database;

  /**
   * The webform element manager.
   *
   * @var \Drupal\webform\Plugin\WebformElementManagerInterface
   */
  protected $elementManager;

  /**
   * The webform entity reference manager.
   *
   * @var \Drupal\webform\WebformEntityReferenceManagerInterface
   */
  protected $webformEntityReferenceManager;

  /**
   * Constructs a WebformAccessGroupForm.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Drupal\webform\Plugin\WebformElementManagerInterface $element_manager
   *   The webform element manager.
   * @param \Drupal\webform\WebformEntityReferenceManagerInterface $webform_entity_reference_manager
   *   The webform entity reference manager.
   */
  public function __construct(Connection $database, WebformElementManagerInterface $element_manager, WebformEntityReferenceManagerInterface $webform_entity_reference_manager) {
    $this->database = $database;
    $this->elementManager = $element_manager;
    $this->webformEntityReferenceManager = $webform_entity_reference_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('plugin.manager.webform.element'),
      $container->get('webform.entity_reference_manager')

    );
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {

    if ($this->operation == 'duplicate') {
      $this->setEntity($this->getEntity()->createDuplicate());
    }

    parent::prepareEntity();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webform_access\WebformAccessGroupInterface $webform_access_group */
    $webform_access_group = $this->getEntity();

    // Customize title for duplicate and edit operation.
    switch ($this->operation) {
      case 'duplicate':
        $form['#title'] = $this->t("Duplicate '@label' access group", ['@label' => $webform_access_group->label()]);
        break;

      case 'edit':
        $form['#title'] = $webform_access_group->label();
        break;
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webform_access\WebformAccessGroupInterface $webform_access_group */
    $webform_access_group = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#attributes' => ($webform_access_group->isNew()) ? ['autofocus' => 'autofocus'] : [],
      '#default_value' => $webform_access_group->label(),
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#machine_name' => [
        'exists' => '\Drupal\webform_access\Entity\WebformAccessGroup::load',
        'label' => '<br/>' . $this->t('Machine name'),
      ],
      '#maxlength' => 32,
      '#field_suffix' => ' (' . $this->t('Maximum @max characters', ['@max' => 32]) . ')',
      '#required' => TRUE,
      '#disabled' => !$webform_access_group->isNew(),
      '#default_value' => $webform_access_group->id(),
    ];
    $form['description'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Description'),
      '#default_value' => $webform_access_group->get('description'),
    ];
    $form['type'] = [
      '#type' => 'webform_entity_select',
      '#title' => $this->t('Type'),
      '#target_type' => 'webform_access_type',
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $webform_access_group->get('type'),
    ];

    // Users.
    $form['users'] = [
      '#type' => 'webform_entity_select',
      '#title' => $this->t('Users'),
      '#target_type' => 'user',
      '#multiple' => TRUE,
      '#selection_handler' => 'default:user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#select2' => TRUE,
      '#default_value' => $webform_access_group->getUserIds(),

    ];
    $this->elementManager->processElement($form['users']);

    // Entities (Nodes).
    $form['entities'] = [
      '#type' => 'select',
      '#title' => $this->t('Nodes'),
      '#multiple' => TRUE,
      '#select2' => TRUE,
      '#options' => $this->getEntitiesAsOptions(),
      '#default_value' => $webform_access_group->getEntityIds(),
    ];
    $this->elementManager->processElement($form['entities']);

    // Permissions.
    $form['permissions_label'] = [
      '#type' => 'label',
      '#title' => $this->t('Permissions'),
    ];
    $form['permissions'] = [
      '#type' => 'tableselect',
      '#header' => ['title' => $this->t('Permission')],
      '#js_select' => FALSE,
      '#options' => [
        'administer' => ['title' => $this->t('Administer submissions')],
        'create' => ['title' => $this->t('Create submissions')],
        'view_any' => ['title' => $this->t('View any submissions')],
        'update_any' => ['title' => $this->t('Update any submissions')],
        'delete_any' => ['title' => $this->t('Delete any submissions')],
        'purge_any' => ['title' => $this->t('Purge any submissions')],
        'view_own' => ['title' => $this->t('View own submissions')],
        'update_own' => ['title' => $this->t('Update own submissions')],
        'delete_own' => ['title' => $this->t('Delete own submissions')],
        'test' => ['title' => $this->t('Test webform')],
      ],
      '#default_value' => $webform_access_group->get('permissions'),
    ];
    $this->elementManager->processElement($form['permissions']);

    $form['#attached']['library'][] = 'webform_access/webform_access.admin';

    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $form_state->setValue('permissions', array_filter($form_state->getValue('permissions')));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webform_access\WebformAccessGroupInterface $webform_access_group */
    $webform_access_group = $this->getEntity();
    $webform_access_group->setUserIds($form_state->getValue('users'));
    $webform_access_group->setEntityIds($form_state->getValue('entities'));
    $webform_access_group->save();

    // Log and display message.
    $context = [
      '@label' => $webform_access_group->label(),
      'link' => $webform_access_group->toLink($this->t('Edit'), 'edit-form')->toString(),
    ];
    $this->logger('webform')->notice('Access group @label saved.', $context);
    $this->messenger()->addStatus($this->t('Access group %label saved.', ['%label' => $webform_access_group->label()]));

    // Redirect to list.
    $form_state->setRedirect('entity.webform_access_group.collection');
  }

  /**
   * Get webform entities as options.
   *
   * @return array
   *   An associative array container webform node options.
   */
  protected function getEntitiesAsOptions() {
    // Collects webform nodes.
    $webform_nodes = [];
    $nids = [];
    $webform_ids = [];

    $table_names = $this->webformEntityReferenceManager->getTableNames();
    foreach ($table_names as $table_name => $field_name) {
      if (strpos($table_name, 'node_revision__') !== 0) {
        continue;
      }
      $query = $this->database->select($table_name, 'n');
      $query->distinct();
      $query->fields('n', ['entity_id', $field_name . '_target_id']);
      $query->condition($field_name . '_target_id', '', '<>');
      $query->isNotNull($field_name . '_target_id');
      $result = $query->execute()->fetchAllKeyed();
      foreach ($result as $nid => $webform_id) {
        $webform_nodes[$nid][$field_name][$webform_id] = $webform_id;
        $webform_ids[$webform_id] = $webform_id;
        $nids[$nid] = $nid;
      }
    }

    /** @var \Drupal\webform\WebformInterface[] $webforms */
    $webforms = Webform::loadMultiple($webform_ids);

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = Node::loadMultiple($nids);

    $options = [];
    foreach ($webform_nodes as $nid => $field_names) {
      if (!isset($nodes[$nid])) {
        continue;
      }
      $node = $nodes[$nid];
      foreach ($field_names as $field_name => $webform_ids) {
        foreach ($webform_ids as $webform_id) {
          if (!isset($webforms[$webform_id])) {
            continue;
          }
          $webform = $webforms[$webform_id];
          $options['node:' . $node->id() . ':' . $field_name . ':' . $webform->id()] = $node->label() . ': ' . $webform->label();
        }
      }
    }
    asort($options);
    return $options;
  }

}
