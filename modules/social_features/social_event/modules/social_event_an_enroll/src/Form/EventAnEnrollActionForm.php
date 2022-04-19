<?php

namespace Drupal\social_event_an_enroll\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\social_event\SocialEventTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EventAnEnrollActionForm.
 *
 * @package Drupal\social_event_an_enroll\Form
 */
class EventAnEnrollActionForm extends FormBase implements ContainerInjectionInterface {

  use SocialEventTrait;

  /**
   * The node storage for event enrollments.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $entityStorage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs an EventAnEnrollActionForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $currentUser
  ) {
    $this->entityStorage = $entity_type_manager->getStorage('event_enrollment');
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'event_an_enroll_action_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Node $node = NULL) {
    $nid = $node->id();
    $token = $this->getRequest()->query->get('token');

    if (!empty($token) && \Drupal::service('social_event_an_enroll.service')->tokenExists($token, $nid)) {
      $form['event'] = [
        '#type' => 'hidden',
        '#value' => $nid,
      ];

      $form['enroll_for_this_event'] = [
        '#type' => 'submit',
        '#value' => $this->t('Enrolled'),
        '#attributes' => [
          'class' => [
            'btn',
            'btn-accent',
            'btn-lg',
            'btn-raised',
            'brand-bg-accent',
            'dropdown-toggle',
            'waves-effect',
          ],
          'autocomplete' => 'off',
          'data-toggle' => 'dropdown',
          'aria-haspopup' => 'true',
          'aria-expanded' => 'false',
          'data-caret' => 'true',
        ],
      ];

      $cancel_text = $this->t('Cancel enrollment');
      $form['feedback_user_has_enrolled'] = [
        '#markup' => '<ul class="dropdown-menu dropdown-menu-right"><li><a href="#" class="enroll-form-submit"> ' . $cancel_text . ' </a></li></ul>',
      ];
      $form['#attached']['library'][] = 'social_event/form_submit';
    }
    else {
      if ($this->eventHasBeenFinished($node)) {
        $form['event_enrollment'] = [
          '#type' => 'submit',
          '#value' => $this->t('Event has passed'),
          '#disabled' => TRUE,
          '#attributes' => [
            'class' => [
              'btn',
              'btn-accent',
              'btn-lg',
              'btn-raised',
              'brand-bg-accent',
              'waves-effect',
            ],
          ],
        ];
      }
      else {
        $attributes = [
          'class' => [
            'use-ajax',
            'js-form-submit',
            'form-submit',
            'btn',
            'btn-accent',
            'btn-lg',
          ],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => json_encode([
            'title' => t('Enroll in') . ' ' . strip_tags($node->getTitle()),
            'width' => 'auto',
          ]),
        ];

        $form['event_enrollment'] = [
          '#type' => 'link',
          '#title' => $this->t('Enroll'),
          '#url' => Url::fromRoute('social_event_an_enroll.enroll_dialog', ['node' => $nid]),
          '#attributes' => $attributes,
        ];
      }
    }
    $form['#cache'] = ['max-age' => 0];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    $token = $this->getRequest()->query->get('token');
    if (!empty($token)) {
      $nid = $form_state->getValue('event');
      $conditions = [
        'field_account' => $uid,
        'field_event' => $nid,
        'field_token' => $token,
      ];

      $enrollments = $this->entityStorage->loadByProperties($conditions);

      // Invalidate cache for our enrollment cache tag in
      // social_event_node_view_alter().
      $cache_tags[] = 'enrollment:' . $nid . '-' . $uid;
      $cache_tags[] = 'node:' . $nid;
      Cache::invalidateTags($cache_tags);

      if ($enrollment = array_pop($enrollments)) {
        $enrollment->delete();
        $this->messenger()->addStatus($this->t('You are no longer enrolled in this event. Your personal data used for the enrollment is also deleted.'));
      }
    }
  }

}
