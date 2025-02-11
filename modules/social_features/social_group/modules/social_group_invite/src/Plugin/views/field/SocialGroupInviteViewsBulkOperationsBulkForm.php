<?php

namespace Drupal\social_group_invite\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\views_bulk_operations\Plugin\views\field\ViewsBulkOperationsBulkForm;

/**
 * Defines the Groups Views Bulk Operations field plugin.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("social_views_bulk_operations_bulk_form_invites")
 */
class SocialGroupInviteViewsBulkOperationsBulkForm extends ViewsBulkOperationsBulkForm {

  /**
   * {@inheritdoc}
   */
  public function getBulkOptions() {
    $bulk_options = parent::getBulkOptions();

    if ($this->view->id() !== 'social_group_invitations') {
      return $bulk_options;
    }

    return $bulk_options;
  }

  /**
   * {@inheritdoc}
   */
  public function viewsForm(array &$form, FormStateInterface $form_state): void {
    $this->view->setExposedInput(['status' => TRUE]);

    parent::viewsForm($form, $form_state);

    // Continue, if group members as a result on the manage members view.
    if (empty($form['output'][0]['#rows']) || $this->view->id() !== 'social_group_invitations') {
      return;
    }

    $group = _social_group_get_current_group();
    $tempstoreData = $this->getTempstoreData($this->view->id(), $this->view->current_display);
    // Make sure the selection is saved for the current group.
    if ($group instanceof GroupInterface) {
      if (!empty($tempstoreData['group_id']) && $tempstoreData['group_id'] !== $group->id()) {
        // If not we clear it right away.
        // Since we don't want to mess with cached date.
        $this->deleteTempstoreData($this->view->id(), $this->view->current_display);
        // Reset initial values.
        $this->updateTempstoreData();
        // Initialize it again.
        $tempstoreData = $this->getTempstoreData($this->view->id(), $this->view->current_display);
      }
      // Add the Group ID to the data.
      $tempstoreData['group_id'] = $group->id();
    }

    $this->setTempstoreData($tempstoreData, $this->view->id(), $this->view->current_display);

    // Reorder the form array.
    $multipage = $form['header'][$this->options['id']]['multipage'];
    unset($form['header'][$this->options['id']]['multipage']);
    $form['header'][$this->options['id']]['multipage'] = $multipage;

    // Render proper classes for the header in VBO form.
    $wrapper = &$form['header'][$this->options['id']];

    // Styling related for the wrapper div.
    $wrapper['#attributes']['class'][] = 'card';
    $wrapper['#attributes']['class'][] = 'card__block';

    // Add some JS for altering titles and switches.
    $form['#attached']['library'][] = 'social_group/views_bulk_operations.frontUi';

    // Render select all results checkbox.
    if (!empty($wrapper['select_all'])) {
      $wrapper['select_all']['#title'] = $this->t('Select / unselect all @count invites across all the pages', [
        '@count' => $this->tempStoreData['total_results'] ? ' ' . $this->tempStoreData['total_results'] : '',
      ]);
      // Styling attributes for the select box.
      $form['header'][$this->options['id']]['select_all']['#attributes']['class'][] = 'form-no-label';
      $form['header'][$this->options['id']]['select_all']['#attributes']['class'][] = 'checkbox';
    }

    /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $title */
    $title = $wrapper['multipage']['#title'];
    $arguments = $title->getArguments();
    $count = empty($arguments['%count']) ? 0 : $arguments['%count'];

    $title = $this->formatPlural($count, '<b><em class="placeholder">@count</em> Invite</b> is selected', '<b><em class="placeholder">@count</em> Invites</b> are selected');
    $wrapper['multipage']['#title'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $title,
      '#attributes' => [
        'class' => [
          'vbo-info-list-wrapper',
        ],
      ],
    ];

    // Add selector so the JS of VBO applies correctly.
    $wrapper['multipage']['#attributes']['class'][] = 'vbo-multipage-selector';

    // Get tempstore data so we know what messages to show based on the data.
    $tempstoreData = $this->getTempstoreData($this->view->id(), $this->view->current_display);
    if (!empty($wrapper['multipage']['list']['#items']) && count($wrapper['multipage']['list']['#items']) > 0) {
      $excluded = FALSE;
      if (isset($tempstoreData['exclude_mode']) && $tempstoreData['exclude_mode']) {
        $excluded = TRUE;
      }
      $wrapper['multipage']['list']['#title'] = !$excluded ? $this->t('See selected invites on other pages') : $this->t('Invites excluded on other pages:');
    }

    // Update the clear submit button.
    if (!empty($wrapper['multipage']['clear'])) {
      $wrapper['multipage']['clear']['#value'] = $this->t('Clear selection on all pages');
      $wrapper['multipage']['clear']['#attributes']['class'][] = 'btn-default dropdown-toggle waves-effect waves-btn margin-top-l margin-left-m';
    }

    // Add the group to the display id, so the ajax callback that is run
    // will count and select across pages correctly.
    if ($group instanceof GroupInterface) {
      $wrapper['multipage']['#attributes']['data-group-id'] = $group->id();
      if (!empty($wrapper['multipage']['#attributes']['data-display-id'])) {
        $current_display = $wrapper['multipage']['#attributes']['data-display-id'];
        $wrapper['multipage']['#attributes']['data-display-id'] = $current_display . '/' . $group->id();
      }
    }

    // Actions are not a select list but a dropbutton list.
    $actions = &$wrapper['actions'];

    $actions['#theme'] = 'links__dropbutton__operations__actions';
    $actions['#label'] = $this->t('Actions');
    $actions['#type'] = 'dropbutton';

    $items = [];
    foreach ($wrapper['action']['#options'] as $key => $value) {
      if ($key !== '' && array_key_exists($key, $this->bulkOptions)) {
        $items[] = [
          '#type' => 'submit',
          '#value' => $value,
        ];
      }
    }

    // Add our links to the dropdown buttondrop type.
    $actions['#links'] = $items;

    // Remove the Views select list and submit button.
    $form['actions']['#type'] = 'hidden';
    $form['header']['social_views_bulk_operations_bulk_form_invites']['action']['#access'] = FALSE;
    // Hide multipage list.
    $form['header']['social_views_bulk_operations_bulk_form_invites']['multipage']['list']['#access'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormValidate(&$form, FormStateInterface $form_state): void {
    if ($this->view->id() === 'social_group_invitations') {
      $user_input = $form_state->getUserInput();
      $available_options = $this->getBulkOptions();

      $selected_actions = array_map(function ($item) {
        return $item['action_id'];
      }, $this->options['selected_actions']);

      // Grab all the actions that are available.
      foreach (Element::children($this->actions) as $action) {
        // If the option is not in our selected options, next.
        if (($action_key = array_search($action, $selected_actions)) === FALSE) {
          continue;
        }

        $label = $available_options[$action_key];

        // Match the Users action from our custom dropdown.
        // Find the action from the VBO selection.
        // And set that as the chosen action in the form_state.
        if (strip_tags($label instanceof TranslatableMarkup ? $label->render() : $label) === $user_input['op']) {

          $user_input['action'] = $action_key;
          $form_state->setUserInput($user_input);
          $form_state->setValue('action', $action_key);
          $form_state->setTriggeringElement($this->actions[$action]);
          break;
        }
      }
    }

    parent::viewsFormValidate($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function viewsFormSubmit(array &$form, FormStateInterface $form_state): void {
    parent::viewsFormSubmit($form, $form_state);

    if ($form_state->get('step') === 'views_form_views_form' && $this->view->id() === 'social_group_invitations') {
      /** @var \Drupal\Core\Url $url */
      $url = $form_state->getRedirect();

      if ($url->getRouteName() === 'views_bulk_operations.confirm') {
        $parameters = $url->getRouteParameters();

        if (
          empty($parameters['group']) &&
          ($group = _social_group_get_current_group()) !== NULL
        ) {
          $parameters['group'] = $group->id();
        }

        $url = Url::fromRoute('social_group_invite.views_bulk_operations.confirm', [
          'group' => $parameters['group'],
        ]);

        $form_state->setRedirectUrl($url);
      }
    }
  }

}
