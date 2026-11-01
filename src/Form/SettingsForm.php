<?php

declare(strict_types=1);

namespace Drupal\project_context_connector\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\project_context_connector\Service\OriginValidator;

/**
 * Settings form for snapshot exposure, quotas and CORS.
 */
final class SettingsForm extends ConfigFormBase {

  private const array INTEGER_FIELDS = [
    'rate_limit_threshold',
    'rate_limit_window',
    'failed_auth_threshold',
    'failed_auth_window',
    'cache_max_age',
  ];
  private const array BOOLEAN_FIELDS = [
    'enable_cors',
    'expose_update_status',
    'expose_database_version',
    'expose_paths',
    'expose_custom_modules',
  ];

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

    $form['enable_cors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable CORS for the standard endpoint'),
      '#default_value' => (bool) $conf->get('enable_cors'),
      '#description' => $this->t('The signed endpoint is for server clients and never sends CORS headers.'),
    ];
    $form['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed origins'),
      '#description' => $this->t('Enter one origin per line, such as https://example.com, https://example.com:8443 or https://*.example.com. A wildcard matches subdomains only. Scheme, host and port must all match.'),
      '#default_value' => implode("\n", (array) $conf->get('allowed_origins')),
      '#rows' => 5,
    ];
    $form['rate_limit_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit threshold'),
      '#description' => $this->t('Authorized requests per IP address and per credential, shared by both endpoints. All clients behind one IP address share this allowance.'),
      '#default_value' => (int) $conf->get('rate_limit_threshold'),
      '#min' => 1,
      '#max' => 100000,
    ];
    $form['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit window (seconds)'),
      '#default_value' => (int) $conf->get('rate_limit_window'),
      '#min' => 1,
      '#max' => 86400,
    ];
    $form['failed_auth_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed authorization threshold'),
      '#description' => $this->t('Denied requests per IP address and claimed key. One IP address can reach five times this number across all keys.'),
      '#default_value' => (int) $conf->get('failed_auth_threshold'),
      '#min' => 1,
      '#max' => 100000,
    ];
    $form['failed_auth_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Failed authorization window (seconds)'),
      '#default_value' => (int) $conf->get('failed_auth_window'),
      '#min' => 1,
      '#max' => 86400,
    ];
    $form['cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Client cache max age (seconds)'),
      '#description' => $this->t('A private client copy for the standard endpoint. Zero sends no-store. The signed endpoint always sends no-store.'),
      '#default_value' => (int) $conf->get('cache_max_age'),
      '#min' => 0,
      '#max' => 86400,
    ];
    $form['expose_update_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose update status metadata'),
      '#default_value' => (bool) $conf->get('expose_update_status'),
      '#description' => $this->t('Include cached per-project security status and its freshness.'),
    ];
    $form['expose_database_version'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose database driver and version'),
      '#default_value' => (bool) $conf->get('expose_database_version'),
    ];
    $form['expose_paths'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose extension paths'),
      '#default_value' => (bool) $conf->get('expose_paths'),
    ];
    $form['expose_custom_modules'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose custom and unclassified extensions'),
      '#default_value' => (bool) $conf->get('expose_custom_modules'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($this->parseOrigins($form_state) as $origin) {
      if (!OriginValidator::isValidPattern($origin)) {
        $form_state->setErrorByName('allowed_origins', $this->t('Invalid origin: @origin', ['@origin' => $origin]));
        return;
      }
      if (str_starts_with(strtolower($origin), 'http://')) {
        $this->messenger()->addWarning($this->t('The origin @origin uses HTTP. Use HTTPS in production.', ['@origin' => $origin]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('project_context_connector.settings')
      ->set('allowed_origins', $this->parseOrigins($form_state));
    foreach (self::INTEGER_FIELDS as $field) {
      $config->set($field, (int) $form_state->getValue($field));
    }
    foreach (self::BOOLEAN_FIELDS as $field) {
      $config->set($field, (bool) $form_state->getValue($field));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the submitted origins.
   *
   * @return string[]
   *   The trimmed non-empty lines.
   */
  private function parseOrigins(FormStateInterface $form_state): array {
    return array_values(array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('allowed_origins')))));
  }

}
