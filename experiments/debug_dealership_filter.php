<?php

/**
 * Debug script to understand dealership_id filtering behavior
 * Tests how query parameters are processed and filtered
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Task;
use App\Models\AutoDealership;

echo "=== Debug Dealership Filtering ===\n\n";

// First, let's see what dealerships exist
echo "Available dealerships:\n";
$dealerships = AutoDealership::all(['id', 'name']);
foreach ($dealerships as $d) {
    echo "  ID: {$d->id}, Name: {$d->name}\n";
}
echo "\n";

// Check tasks for each dealership
echo "Tasks per dealership:\n";
foreach ($dealerships as $d) {
    $count = Task::where('dealership_id', $d->id)->count();
    echo "  Dealership {$d->id} ({$d->name}): {$count} tasks\n";
}
echo "\n";

// Now let's simulate what happens with different query parameter values
echo "=== Simulating query parameter processing ===\n\n";

$testCases = [
    ['dealership_id' => '1'],
    ['dealership_id' => '0'],
    ['dealership_id' => null],
    [],  // no parameter
];

foreach ($testCases as $index => $params) {
    echo "Test case " . ($index + 1) . ": ";

    if (!isset($params['dealership_id'])) {
        echo "No dealership_id parameter\n";
        $dealershipId = null;
    } else {
        echo "dealership_id = " . var_export($params['dealership_id'], true) . "\n";

        // Simulate the current code logic
        $dealershipId = isset($params['dealership_id']) && $params['dealership_id'] !== null
            ? (int) $params['dealership_id']
            : null;
    }

    echo "  After processing: dealershipId = " . var_export($dealershipId, true) . "\n";
    echo "  Truthiness check: if (\$dealershipId) = " . ($dealershipId ? 'true' : 'false') . "\n";

    // Execute the query
    $query = Task::query();
    if ($dealershipId) {
        $query->where('dealership_id', $dealershipId);
        echo "  Filter applied: WHERE dealership_id = {$dealershipId}\n";
    } else {
        echo "  Filter NOT applied (showing all tasks)\n";
    }

    $count = $query->count();
    echo "  Result: {$count} tasks\n";

    // Show first few task IDs
    if ($count > 0) {
        $taskIds = $query->limit(5)->pluck('id')->toArray();
        echo "  Sample task IDs: " . implode(', ', $taskIds) . "\n";
    }

    echo "\n";
}

// Now let's check what the actual HTTP request would look like
echo "=== HTTP Request Simulation ===\n\n";

// Simulate Illuminate\Http\Request
use Illuminate\Http\Request;

$httpCases = [
    ['dealership_id=1'],
    ['dealership_id=0'],
    [''],  // no query string
];

foreach ($httpCases as $index => $queryString) {
    echo "HTTP case " . ($index + 1) . ": Query string = " . ($queryString ? "?{$queryString}" : "(empty)") . "\n";

    // Create a fake request
    $request = Request::create('/api/v1/tasks' . ($queryString ? "?{$queryString}" : ''), 'GET');

    $dealershipId = $request->query('dealership_id') !== null
        ? (int) $request->query('dealership_id')
        : null;

    echo "  \$request->query('dealership_id') = " . var_export($request->query('dealership_id'), true) . "\n";
    echo "  After !== null check and cast: " . var_export($dealershipId, true) . "\n";
    echo "  Truthiness: if (\$dealershipId) = " . ($dealershipId ? 'true' : 'false') . "\n";

    $query = Task::query();
    if ($dealershipId) {
        $query->where('dealership_id', $dealershipId);
        echo "  Filter WOULD be applied\n";
    } else {
        echo "  Filter WOULD NOT be applied\n";
    }

    echo "\n";
}

echo "=== Analysis ===\n";
echo "If dealership_id=1 is not filtering correctly, possible causes:\n";
echo "1. The query string is not being parsed correctly\n";
echo "2. The database has no tasks with dealership_id=1\n";
echo "3. There's a type mismatch in the WHERE clause\n";
echo "4. The frontend is not sending the parameter correctly\n";
echo "\nRun this script to see actual behavior!\n";
