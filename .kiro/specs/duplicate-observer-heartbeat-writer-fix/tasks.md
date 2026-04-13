# Implementation Plan

## Phase 1: Bug Condition Exploration

- [-] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - SseWriter Final Class Prevents Inheritance
  - **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior - it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate the bug exists
  - **Scoped PBT Approach**: For this deterministic bug, scope the property to the concrete failing case: attempting to extend the final SseWriter class
  
  **Test Implementation Details from Bug Condition in design:**
  - Attempt to load the test file `app/code/GuoLaiRen/PageBuilder/test/Integration/AiSiteAgentOperationObserverIntegrationTest.php`
  - Verify that the class declaration `final class DuplicateObserverHeartbeatWriter extends SseWriter` exists
  - Attempt to instantiate `DuplicateObserverHeartbeatWriter` with a callback parameter
  - Assert that the class can be loaded and instantiated without fatal errors
  
  **The test assertions should match the Expected Behavior Properties from design:**
  - Class loading succeeds (no "Cannot extend final class" error)
  - Class instantiation succeeds
  - Instance is an instance of `SseWriter`
  - Overridden methods are callable
  
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS with "Cannot extend final class SseWriter" error (this is correct - it proves the bug exists)
  - Document counterexamples found to understand root cause:
    - Counterexample: `DuplicateObserverHeartbeatWriter extends SseWriter` fails because `SseWriter` is marked as `final`
    - Root cause: The `final` keyword on `SseWriter` class declaration prevents any inheritance
  - Mark task complete when test is written, run, and failure is documented
  - _Requirements: 1.1, 1.2_

## Phase 2: Preservation Property Tests

- [~] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Direct SseWriter Usage Continues to Work
  - **IMPORTANT**: Follow observation-first methodology
  - Observe behavior on UNFIXED code for non-buggy inputs (direct SseWriter usage, not inheritance)
  
  **Observation Phase:**
  - Observe: `new SseWriter($response, $callback)` instantiates successfully
  - Observe: `$writer->sendEvent('event', $data)` sends events without errors
  - Observe: `$writer->sendData($data)` sends data without errors
  - Observe: `$writer->sendComment($comment)` sends comments without errors
  - Observe: `$writer->complete()` completes the stream without errors
  - Observe: `$writer->isAlive()` returns boolean status correctly
  - Observe: Direct instantiation and method calls work as expected
  
  **Write property-based tests capturing observed behavior patterns from Preservation Requirements:**
  - Property: For all valid SseWriter instantiations with response and callback, direct method calls work identically
  - Property: For all event data, sendEvent() produces the same output before and after fix
  - Property: For all data values, sendData() produces the same output before and after fix
  - Property: For all comment strings, sendComment() produces the same output before and after fix
  - Property: complete() and isAlive() methods work identically before and after fix
  
  - Property-based testing generates many test cases for stronger guarantees
  - Run tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

## Phase 3: Implementation

- [~] 3. Fix SseWriter to allow inheritance for testing

  - [ ] 3.1 Implement the fix
    - Remove the `final` keyword from the `SseWriter` class declaration in `app/code/Weline/Framework/Http/Sse/SseWriter.php`
    - Change: `final class SseWriter` → `class SseWriter`
    - Verify all methods that need to be overridden are `public` or `protected` (sendEvent, maybeHeartbeat, complete, isAlive, sendError, sendData, sendComment)
    - Add PHPDoc comment to class indicating it's designed to be extended for testing: "This class is designed to be extended for testing purposes"
    - Verify constructor doesn't prevent subclass instantiation
    - No changes to method implementations or signatures
    - _Bug_Condition: isBugCondition(input) where input.className = 'DuplicateObserverHeartbeatWriter' AND input.extendsClass = 'SseWriter' AND SseWriter.isExtensible() = false_
    - _Expected_Behavior: expectedBehavior(result) where test class can extend SseWriter and override methods without compilation errors_
    - _Preservation: All production code using SseWriter directly must continue to work identically_
    - _Requirements: 2.1, 2.2, 1.1, 1.2, 3.1, 3.2, 3.3, 3.4_

  - [ ] 3.2 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - SseWriter Inheritance Support
    - **IMPORTANT**: Re-run the SAME test from task 1 - do NOT write a new test
    - The test from task 1 encodes the expected behavior
    - When this test passes, it confirms the expected behavior is satisfied
    - Run bug condition exploration test from step 1
    - Verify that `DuplicateObserverHeartbeatWriter` can now be loaded and instantiated
    - Verify that the class is an instance of `SseWriter`
    - Verify that overridden methods are callable
    - **EXPECTED OUTCOME**: Test PASSES (confirms bug is fixed)
    - _Requirements: 2.1, 2.2_

  - [ ] 3.3 Verify preservation tests still pass
    - **Property 2: Preservation** - Direct SseWriter Usage Continues to Work
    - **IMPORTANT**: Re-run the SAME tests from task 2 - do NOT write new tests
    - Run preservation property tests from step 2
    - Verify that all direct SseWriter usage continues to work identically
    - Verify that sendEvent, sendData, sendComment, complete, isAlive all work as before
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - Confirm all tests still pass after fix (no regressions)
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

## Phase 4: Checkpoint

- [~] 4. Checkpoint - Ensure all tests pass
  - Verify that the bug condition exploration test (Property 1) passes
  - Verify that the preservation property tests (Property 2) pass
  - Verify that the full integration test `testDuplicateOperationObserverContinuesForwardingProgressUntilBuildFinishes` can now run
  - Verify that no other tests have been broken by the fix
  - Ensure all tests pass, ask the user if questions arise
  - _Requirements: 2.1, 2.2, 3.1, 3.2, 3.3, 3.4_
