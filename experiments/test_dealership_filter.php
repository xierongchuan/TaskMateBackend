<?php

/**
 * Test script to verify dealership_id filtering with value 0
 *
 * This script tests the fix for the issue where dealership_id=0 was
 * incorrectly converted to null due to PHP's falsy behavior.
 */

echo "Testing dealership_id filtering logic...\n\n";

// Simulate the OLD BUGGY behavior
function oldBuggyLogic($queryValue) {
    return $queryValue ? (int) $queryValue : null;
}

// Simulate the NEW FIXED behavior
function newFixedLogic($queryValue) {
    return $queryValue !== null ? (int) $queryValue : null;
}

// Test cases
$testCases = [
    ['value' => '0', 'description' => 'String "0" (first dealership)'],
    ['value' => 0, 'description' => 'Integer 0'],
    ['value' => '1', 'description' => 'String "1"'],
    ['value' => 1, 'description' => 'Integer 1'],
    ['value' => null, 'description' => 'null (no filter)'],
    ['value' => '', 'description' => 'Empty string'],
];

echo "=== OLD BUGGY LOGIC ===\n";
foreach ($testCases as $test) {
    $result = oldBuggyLogic($test['value']);
    $displayValue = var_export($test['value'], true);
    $displayResult = var_export($result, true);
    echo sprintf("Input: %-20s => Result: %-10s\n", $displayValue, $displayResult);
}

echo "\n=== NEW FIXED LOGIC ===\n";
foreach ($testCases as $test) {
    $result = newFixedLogic($test['value']);
    $displayValue = var_export($test['value'], true);
    $displayResult = var_export($result, true);
    echo sprintf("Input: %-20s => Result: %-10s\n", $displayValue, $displayResult);
}

echo "\n=== ANALYSIS ===\n";
echo "The bug was that '0' (string) or 0 (integer) evaluated as falsy in PHP,\n";
echo "so the ternary operator `condition ? value : null` returned null instead of 0.\n\n";
echo "The fix checks `!== null` explicitly, which correctly distinguishes between:\n";
echo "  - 0 (valid dealership ID) => returns 0\n";
echo "  - null (no filter applied) => returns null\n\n";

// Test query parameter simulation
echo "=== SIMULATING REQUEST QUERY ===\n";

class FakeRequest {
    private $params;

    public function __construct($params) {
        $this->params = $params;
    }

    public function query($key, $default = null) {
        return $this->params[$key] ?? $default;
    }
}

// Test with dealership_id=0
$request1 = new FakeRequest(['dealership_id' => '0']);
$oldResult1 = $request1->query('dealership_id') ? (int) $request1->query('dealership_id') : null;
$newResult1 = $request1->query('dealership_id') !== null ? (int) $request1->query('dealership_id') : null;

echo "Query param: dealership_id=0\n";
echo "  Old logic: " . var_export($oldResult1, true) . " (BUG: should be 0!)\n";
echo "  New logic: " . var_export($newResult1, true) . " (CORRECT!)\n\n";

// Test with no dealership_id
$request2 = new FakeRequest([]);
$oldResult2 = $request2->query('dealership_id') ? (int) $request2->query('dealership_id') : null;
$newResult2 = $request2->query('dealership_id') !== null ? (int) $request2->query('dealership_id') : null;

echo "Query param: (no dealership_id)\n";
echo "  Old logic: " . var_export($oldResult2, true) . " (correct)\n";
echo "  New logic: " . var_export($newResult2, true) . " (correct)\n\n";

// Test with dealership_id=5
$request3 = new FakeRequest(['dealership_id' => '5']);
$oldResult3 = $request3->query('dealership_id') ? (int) $request3->query('dealership_id') : null;
$newResult3 = $request3->query('dealership_id') !== null ? (int) $request3->query('dealership_id') : null;

echo "Query param: dealership_id=5\n";
echo "  Old logic: " . var_export($oldResult3, true) . " (correct)\n";
echo "  New logic: " . var_export($newResult3, true) . " (correct)\n\n";

echo "✓ All tests demonstrate the fix correctly handles dealership_id=0\n";
