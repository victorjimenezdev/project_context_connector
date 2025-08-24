<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\project_context_connector\Service\SignatureValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\project_context_connector\Service\SignatureValidator
 * @group project_context_connector
 */
final class SignatureValidatorTest extends UnitTestCase {

  /**
   * Build a validator instance for a single request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request to validate.
   * @param array<string,string> $keys
   *   Key map: [key_id => secret].
   */
  private function buildValidator(Request $request, array $keys): SignatureValidator {
    new Settings(['project_context_connector_api_keys' => $keys]);

    $stack = new RequestStack();
    $stack->push($request);

    $time = new Time();
    $logger = $this->createMock(LoggerChannelInterface::class);

    return new SignatureValidator(Settings::getInstance(), $stack, $time, $logger);
  }

  /**
   * Valid signature with current timestamp passes.
   *
   * @covers ::isValid
   */
  public function testValidSignatureWithinSkew(): void {
    $ts = (string) time();
    $path = '/project-context-connector/snapshot/signed';
    $secret = 'topsecret';
    $base = "GET\n{$path}\n{$ts}";
    $sig = hash_hmac('sha256', $base, $secret);

    $req = Request::create($path, 'GET');
    $req->headers->set('X-PCC-Key', 'bot');
    $req->headers->set('X-PCC-Timestamp', $ts);
    $req->headers->set('X-PCC-Signature', $sig);

    $validator = $this->buildValidator($req, ['bot' => $secret]);
    $this->assertTrue($validator->isValid(300));
  }

  /**
   * Missing headers fail validation.
   *
   * @covers ::isValid
   */
  public function testMissingHeadersIsInvalid(): void {
    $req = Request::create('/project-context-connector/snapshot/signed', 'GET');
    $validator = $this->buildValidator($req, ['bot' => 'topsecret']);
    $this->assertFalse($validator->isValid(300));
  }

  /**
   * Unknown key id fails validation.
   *
   * @covers ::isValid
   */
  public function testUnknownKeyIsInvalid(): void {
    $ts = (string) time();
    $path = '/project-context-connector/snapshot/signed';
    $base = "GET\n{$path}\n{$ts}";
    $sig = hash_hmac('sha256', $base, 'wrong');

    $req = Request::create($path, 'GET');
    $req->headers->set('X-PCC-Key', 'nope');
    $req->headers->set('X-PCC-Timestamp', $ts);
    $req->headers->set('X-PCC-Signature', $sig);

    $validator = $this->buildValidator($req, ['bot' => 'topsecret']);
    $this->assertFalse($validator->isValid(300));
  }

  /**
   * Timestamp outside allowable skew fails.
   *
   * @covers ::isValid
   */
  public function testSkewTooLargeIsInvalid(): void {
    $ts = (string) (time() - 3600);
    $path = '/project-context-connector/snapshot/signed';
    $secret = 'topsecret';
    $base = "GET\n{$path}\n{$ts}";
    $sig = hash_hmac('sha256', $base, $secret);

    $req = Request::create($path, 'GET');
    $req->headers->set('X-PCC-Key', 'bot');
    $req->headers->set('X-PCC-Timestamp', $ts);
    $req->headers->set('X-PCC-Signature', $sig);

    $validator = $this->buildValidator($req, ['bot' => $secret]);
    $this->assertFalse($validator->isValid(300));
  }

  /**
   * Wrong signature fails.
   *
   * @covers ::isValid
   */
  public function testWrongSignatureIsInvalid(): void {
    $ts = (string) time();
    $path = '/project-context-connector/snapshot/signed';

    $req = Request::create($path, 'GET');
    $req->headers->set('X-PCC-Key', 'bot');
    $req->headers->set('X-PCC-Timestamp', $ts);
    $req->headers->set('X-PCC-Signature', 'deadbeef');

    $validator = $this->buildValidator($req, ['bot' => 'topsecret']);
    $this->assertFalse($validator->isValid(300));
  }

}
