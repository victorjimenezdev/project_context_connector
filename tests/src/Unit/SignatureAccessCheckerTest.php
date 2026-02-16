<?php

declare(strict_types=1);

namespace Drupal\Tests\project_context_connector\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\project_context_connector\Access\SignatureAccessChecker;
use Drupal\project_context_connector\Service\SignatureValidator;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\project_context_connector\Access\SignatureAccessChecker
 * @group project_context_connector
 */
final class SignatureAccessCheckerTest extends UnitTestCase {

  /**
   * Tests that OPTIONS requests are always allowed.
   *
   * @covers ::access
   */
  public function testOptionsRequestAllowed(): void {
    $request = Request::create('/test', 'OPTIONS');
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $validator = $this->createMock(SignatureValidator::class);
    $validator->expects($this->never())
      ->method('isValid');

    $checker = new SignatureAccessChecker($validator, $requestStack);
    $account = $this->createMock(AccountInterface::class);

    $result = $checker->access($account);

    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Tests that valid signatures allow access.
   *
   * @covers ::access
   */
  public function testValidSignatureAllowed(): void {
    $request = Request::create('/test', 'GET');
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $validator = $this->createMock(SignatureValidator::class);
    $validator->expects($this->once())
      ->method('isValid')
      ->willReturn(TRUE);

    $checker = new SignatureAccessChecker($validator, $requestStack);
    $account = $this->createMock(AccountInterface::class);

    $result = $checker->access($account);

    $this->assertTrue($result->isAllowed());
    $this->assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Tests that invalid signatures are forbidden.
   *
   * @covers ::access
   */
  public function testInvalidSignatureForbidden(): void {
    $request = Request::create('/test', 'GET');
    $requestStack = new RequestStack();
    $requestStack->push($request);

    $validator = $this->createMock(SignatureValidator::class);
    $validator->expects($this->once())
      ->method('isValid')
      ->willReturn(FALSE);

    $checker = new SignatureAccessChecker($validator, $requestStack);
    $account = $this->createMock(AccountInterface::class);

    $result = $checker->access($account);

    $this->assertTrue($result->isForbidden());
    $this->assertSame(0, $result->getCacheMaxAge());
    $this->assertStringContainsString('Invalid or missing HMAC signature', $result->getReason());
  }

}
