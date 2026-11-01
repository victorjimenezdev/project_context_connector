<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\project_context_connector\Service\SignatureValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the HMAC signature contract.
 */
#[Group('project_context_connector')]
final class SignatureValidatorTest extends UnitTestCase {

  private const int NOW = 1700000000;
  private const string PATH = '/project-context-connector/snapshot/signed';

  /**
   * Tests the canonical string, the window and malformed values.
   */
  public function testSignatures(): void {
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    $keys = ['bot' => 's3cret', 'empty' => ''];
    $validator = new SignatureValidator(new Settings(['project_context_connector_api_keys' => $keys]), $time);

    $sign = static fn(string $ts, string $method = 'GET', string $path = self::PATH, string $secret = 's3cret'): string => hash_hmac('sha256', "$method\n$path\n$ts", $secret);
    $now = (string) self::NOW;
    $early = (string) (self::NOW - 300);
    $late = (string) (self::NOW + 300);
    $old = (string) (self::NOW - 301);

    $this->assertTrue($validator->isValid($this->request('bot', $now, $sign($now))));
    $this->assertTrue($validator->isValid($this->request('bot', $now, strtoupper($sign($now)))));
    $this->assertTrue($validator->isValid($this->request('bot', $early, $sign($early))));
    $this->assertTrue($validator->isValid($this->request('bot', $late, $sign($late))));

    $invalid = [
      ['', $now, $sign($now)],
      ['bot', '', $sign($now)],
      ['bot', $now, ''],
      ['nobody', $now, $sign($now)],
      ['empty', $now, hash_hmac('sha256', "GET\n" . self::PATH . "\n$now", '')],
      ['bot', $old, $sign($old)],
      ['bot', '0' . $now, $sign('0' . $now)],
      ['bot', $now . 'x', $sign($now . 'x')],
      ['bot', '-' . $now, $sign('-' . $now)],
      ['bot', $now, $sign($now, 'POST')],
      ['bot', $now, $sign($now, 'GET', '/other')],
      ['bot', $now, $sign($now, 'GET', self::PATH, 'wrong')],
    ];
    foreach ($invalid as $case) {
      $this->assertFalse($validator->isValid($this->request(...$case)), json_encode($case, JSON_THROW_ON_ERROR));
    }
  }

  /**
   * Builds a signed request.
   */
  private function request(string $key, string $timestamp, string $signature): Request {
    $request = Request::create(self::PATH);
    $request->headers->add(['X-PCC-Key' => $key, 'X-PCC-Timestamp' => $timestamp, 'X-PCC-Signature' => $signature]);
    return $request;
  }

}
