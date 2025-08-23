<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form.
 *
 * Accessible and keyboard-navigable; labels and descriptions meet WCAG 2.2 AA.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['project_context_connector.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'project_context_connector_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $conf = $this->config('project_context_connector.settings');

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure read-only snapshot exposure, rate limits, and CORS allow-list.') . '</p>',
    ];

    $form['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed origins'),
      '#description' => $this->t('Enter one origin per line. Exact match (e.g., https://example.com) or wildcard subdomain (e.g., *.example.com).'),
      '#default_value' => implode("\n", (array) $conf->get('allowed_origins') ?? []),
      '#rows' => 5,
      '#maxlength' => 4000,
    ];

    $form['enable_cors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CORS headers'),
      '#default_value' => (bool) $conf->get('enable_cors'),
    ];

    $form['rate_limit_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit threshold'),
      '#default_value' => (int) $conf->get('rate_limit_threshold'),
      '#min' => 1,
      '#max' => 100000,
      '#step' => 1,
    ];

    $form['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit window (seconds)'),
      '#default_value' => (int) $conf->get('rate_limit_window'),
      '#min' => 1,
      '#max' => 86400,
      '#step' => 1,
    ];

    $form['cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache max age (seconds)'),
      '#default_value' => (int) $conf->get('cache_max_age'),
      '#min' => 0,
      '#max' => 86400,
      '#step' => 1,
    ];

    $form['expose_update_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose update status metadata'),
      '#default_value' => (bool) $conf->get('expose_update_status'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $originsRaw = (string) $form_state->getValue('allowed_origins') ?? '';
    $origins = array_filter(array_map('trim', explode("\n", $originsRaw)));

    foreach ($origins as $origin) {
      if ($origin === '') {
        continue;
      }
      if (str_starts_with($origin, '*.') || str_starts_with($origin, 'http://*.') || str_starts_with($origin, 'https://*.')) {
        // Wildcard subdomain patterns are allowed.
        continue;
      }
      if (!preg_match('@^https?://[^/]+$@i', $origin)) {
        $form_state->setErrorByName('allowed_origins', $this->t('Invalid origin: @o', ['@o' => $origin]));
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $origins = array_values(array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('allowed_origins') ?? ''))));

    $this->config('project_context_connector.settings')
      ->set('allowed_origins', $origins)
      ->set('enable_cors', (bool) $form_state->getValue('enable_cors'))
      ->set('rate_limit_threshold', (int) $form_state->getValue('rate_limit_threshold'))
      ->set('rate_limit_window', (int) $form_state->getValue('rate_limit_window'))
      ->set('cache_max_age', (int) $form_state->getValue('cache_max_age'))
      ->set('expose_update_status', (bool) $form_state->getValue('expose_update_status'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
