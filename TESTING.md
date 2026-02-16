# Testing Best Practices for Drupal Modules

This document outlines testing best practices to avoid common CI/CD failures.

## Table of Contents
- [General Principles](#general-principles)
- [Service Mocking Rules](#service-mocking-rules)
- [Test Type Selection](#test-type-selection)
- [Common Pitfalls](#common-pitfalls)
- [Running Tests Locally](#running-tests-locally)
- [CI/CD Pipeline](#cicd-pipeline)

## General Principles

### 1. Never Use `final` on Services That Need Mocking
**Problem**: PHPUnit cannot create mock objects or test doubles for `final` classes.

**Solution**: Only use `final` on classes that will NEVER need to be mocked in tests:
```php
// BAD - Blocks testing
final class MyService {
  public function doSomething(): string {}
}

// GOOD - Allows mocking
class MyService {
  public function doSomething(): string {}
}

// ACCEPTABLE - Only if truly never needs mocking
final class ValueObject {
  public function __construct(
    public readonly string $value
  ) {}
}
```

**Services that need to be mockable:**
- Any service injected into other services/controllers
- Any service used in tests with `$this->createMock()`
- Any service that might have test doubles created

### 2. Test Type Determines Service Override Approach

Different test types require different approaches for overriding services:

| Test Type | Base Class | Service Override Method | Persists Across Requests? |
|-----------|------------|------------------------|---------------------------|
| Unit | `TestCase` | Constructor injection / `createMock()` | N/A (no HTTP) |
| Kernel | `KernelTestBase` | `containerBuild()` | Yes |
| Functional | `BrowserTestBase` | ❌ **Cannot override** | No - each request rebuilds kernel |

### 3. Choose the Right Test Type

**Unit Tests** (`TestCase`):
- Test a single class in isolation
- Mock all dependencies
- No Drupal bootstrap
- Fast execution
- **Use for**: Services, value objects, utility classes

**Kernel Tests** (`KernelTestBase`):
- Drupal kernel bootstrap (database, config, services)
- Can override services with `containerBuild()`
- No HTTP layer
- Medium execution speed
- **Use for**: Services that interact with database/config

**Functional Tests** (`BrowserTestBase`):
- Full Drupal HTTP stack
- Tests through real HTTP requests
- **Cannot mock services** across requests
- Slow execution
- **Use for**: End-to-end user workflows, permissions, routing

## Service Mocking Rules

### Rule 1: Use `containerBuild()` Only in KernelTestBase

```php
// WRONG - BrowserTestBase doesn't have containerBuild()
class MyFunctionalTest extends BrowserTestBase {
  public static function containerBuild(ContainerBuilder $container): void {
    // This method doesn't exist in BrowserTestBase!
  }
}

// CORRECT - KernelTestBase supports containerBuild()
class MyKernelTest extends KernelTestBase {
  public static function containerBuild(ContainerBuilder $container): void {
    parent::containerBuild($container);
    $container->set('my.service', new MockService());
  }
}
```

### Rule 2: Don't Try to Mock Services in Functional Tests

```php
// WRONG - Service override doesn't persist across drupalGet()
class MyFunctionalTest extends BrowserTestBase {
  protected function setUp(): void {
    parent::setUp();
    // This ONLY affects $this->container, NOT HTTP requests!
    $this->container->set('my.service', new MockService());
  }

  public function testSomething(): void {
    // This creates a NEW kernel with ORIGINAL services
    $this->drupalGet('/my-route');
  }
}

// CORRECT - Test the real service behavior
class MyFunctionalTest extends BrowserTestBase {
  public function testSomething(): void {
    // Test with real services - functional tests are for integration
    $this->drupalGet('/my-route');
    $this->assertSession()->statusCodeEquals(200);
  }
}

// CORRECT - Move service testing to Unit/Kernel tests
class MyServiceTest extends TestCase {
  public function testServiceBehavior(): void {
    $mock = $this->createMock(DependencyInterface::class);
    $service = new MyService($mock);
    // Test service in isolation
  }
}
```

### Rule 3: Remove `final` from Services Used in Tests

**Check if a service is mockable:**
```bash
# Find services that might need mocking
grep -r "final class.*Service" src/Service/
grep -r "createMock.*Service" tests/
```

**Remove `final` if:**
- The class is injected into other services
- Tests create mocks with `$this->createMock(ClassName::class)`
- Tests extend the class for test doubles

## Test Type Selection

### When to Use Unit Tests
✅ Testing a single service in isolation
✅ Service has external dependencies (API calls, complex logic)
✅ Need fast, precise tests
✅ Testing value objects, helpers, utilities

Example:
```php
final class SignatureValidatorTest extends TestCase {
  public function testValidSignature(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $validator = new SignatureValidator(..., $logger);
    $this->assertTrue($validator->isValid());
  }
}
```

### When to Use Kernel Tests
✅ Testing services that interact with database
✅ Testing services that use config system
✅ Need to override services that persist across test methods
✅ Testing event subscribers, hooks

Example:
```php
final class MyServiceKernelTest extends KernelTestBase {
  public static function containerBuild(ContainerBuilder $container): void {
    parent::containerBuild($container);
    $container->set('external.api', new MockApiClient());
  }

  public function testServiceWithDatabase(): void {
    $service = $this->container->get('my.service');
    $result = $service->saveToDatabase();
    $this->assertEquals(1, $result);
  }
}
```

### When to Use Functional Tests
✅ Testing full HTTP request/response cycle
✅ Testing permissions and access control
✅ Testing routing and controllers
✅ Testing forms and user workflows
❌ **NOT for testing services** - use Unit/Kernel instead

Example:
```php
final class SnapshotEndpointTest extends BrowserTestBase {
  public function testAnonymousForbidden(): void {
    $this->drupalGet('/my-endpoint');
    $this->assertSession()->statusCodeEquals(403);
  }

  public function testAuthenticatedSuccess(): void {
    $user = $this->drupalCreateUser(['my permission']);
    $this->drupalLogin($user);
    $this->drupalGet('/my-endpoint');
    $this->assertSession()->statusCodeEquals(200);
  }
}
```

## Common Pitfalls

### Pitfall 1: Using `final` on Services

**Symptom**: `PHPUnit\Framework\MockObject\Generator\ClassIsFinalException`

**Cause**: PHPUnit cannot mock `final` classes

**Solution**:
```bash
# Find final services
grep -n "^final class.*Service" src/

# Remove final keyword
sed -i '' 's/^final class MyService/class MyService/' src/Service/MyService.php
```

### Pitfall 2: Using `containerBuild()` in BrowserTestBase

**Symptom**: PHPStan error "Call to undefined static method containerBuild()"

**Cause**: `containerBuild()` only exists in `KernelTestBase`, not `BrowserTestBase`

**Solution**: Either:
1. Change to `KernelTestBase` if testing services
2. Remove service override and test with real services
3. Move test to Unit test with constructor injection

### Pitfall 3: Expecting Service Overrides to Persist in Functional Tests

**Symptom**: Test failures with unexpected service behavior

**Cause**: Each `drupalGet()` creates a new kernel with original services

**Solution**: Move service-level testing to Unit or Kernel tests

## Running Tests Locally

### Before Pushing to CI

Always run tests locally BEFORE creating a release:

```bash
# 1. Check for final classes that might need mocking
grep -r "final class.*Service\|final class.*Validator" src/

# 2. Run PHPStan (if you have Drupal environment)
vendor/bin/phpstan analyze src/ tests/ --level=1

# 3. Check test structure
# - Unit tests should extend TestCase
# - Kernel tests should extend KernelTestBase
# - Functional tests should extend BrowserTestBase

# 4. Verify test types match their purpose
grep -A 5 "extends BrowserTestBase" tests/ | grep "createMock"
# ⚠️  If this returns results, tests are in wrong category!
```

### PHPStan Local Limitations

**Note**: Running PHPStan locally WITHOUT full Drupal environment will show errors for Drupal classes. This is expected:

```bash
# These errors are EXPECTED locally:
# - "unknown interface Drupal\Core\..."
# - "unknown class Symfony\Component\..."

# These errors are REAL PROBLEMS:
# - "Call to undefined static method containerBuild()"
# - "Cannot extend final class"
# - "Class ... does not exist" (for your own classes)
```

## CI/CD Pipeline

### Pipeline Stages

1. **composer**: Install dependencies
2. **cspell**: Spell checking
3. **phpcs**: Code style (PSR-12, Drupal standards)
4. **phpstan**: Static analysis
5. **phpunit**: Run all tests

### Common CI Failures and Fixes

| Error | Cause | Fix |
|-------|-------|-----|
| "Cannot extend final class" | Service is final | Remove `final` keyword |
| "Call to undefined method containerBuild()" | Using in BrowserTestBase | Change to KernelTestBase or remove |
| "Cannot mock final class" | Service is final | Remove `final` keyword |
| Functional test unexpected behavior | Service override not working | Move to Unit/Kernel test |

### Checking Pipeline Outputs

When pipeline fails, download the output logs:

```bash
# Save outputs to local directory
/Users/vj/Documents/work/personal/gitlaboutpus/phpstan.txt
/Users/vj/Documents/work/personal/gitlaboutpus/phpunit.txt

# Find errors
grep "ERROR\|FAIL" phpstan.txt
grep "ERRORS\|FAILURES" phpunit.txt
```

## Quick Reference Card

```
┌─────────────────────────────────────────────────────────┐
│  TESTING DECISION TREE                                  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  Testing a service in isolation?                        │
│  └─> Use Unit Test (TestCase) + createMock()           │
│                                                          │
│  Testing service with database/config?                  │
│  └─> Use Kernel Test + containerBuild()                │
│                                                          │
│  Testing HTTP/permissions/forms?                        │
│  └─> Use Functional Test (NO mocking!)                 │
│                                                          │
│  Need to mock across HTTP requests?                     │
│  └─> ❌ IMPOSSIBLE - split into Unit/Kernel tests      │
│                                                          │
└─────────────────────────────────────────────────────────┘

BEFORE COMMITTING:
☑ Removed final from mocked services?
☑ Right test type (Unit/Kernel/Functional)?
☑ No containerBuild() in BrowserTestBase?
☑ No service mocking in Functional tests?
```

## History of Fixes

### Version 1.1.5 Issues

**Problem 1**: `SignatureValidator` was `final`, blocking PHPUnit mocks
**Solution**: Removed `final` keyword

**Problem 2**: Used `containerBuild()` in `BrowserTestBase`
**Solution**: Removed - method doesn't exist in BrowserTestBase

**Problem 3**: Tried to mock `RateLimiter` across HTTP requests
**Solution**: Removed functional test - rate limiting tested in Unit tests

### Lessons Learned

1. **Service overrides in BrowserTestBase don't work** - each HTTP request rebuilds the kernel
2. **containerBuild() only exists in KernelTestBase** - not in BrowserTestBase
3. **Don't mark services as final** if they're used in tests
4. **Match test type to what you're testing**:
   - Unit → Single class logic
   - Kernel → Service + database/config
   - Functional → Full HTTP workflow

## Additional Resources

- [Drupal PHPUnit Documentation](https://www.drupal.org/docs/develop/automated-testing/phpunit-in-drupal)
- [Drupal KernelTestBase API](https://api.drupal.org/api/drupal/core!tests!Drupal!KernelTests!KernelTestBase.php/class/KernelTestBase/11.x)
- [Drupal BrowserTestBase API](https://api.drupal.org/api/drupal/core!tests!Drupal!Tests!BrowserTestBase.php/class/BrowserTestBase/11.x)
- [PHPUnit Best Practices](https://phpunit.de/manual/current/en/index.html)
