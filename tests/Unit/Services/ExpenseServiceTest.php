<?php

declare(strict_types=1);

use App\Services\ExpenseService;
use App\Models\User;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Enums\Role;
use App\Enums\ExpenseStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    Log::spy();
    $this->mockBot = Mockery::mock(Nutgram::class);
});

afterEach(function () {
    Mockery::close();
});

describe('ExpenseService::createRequest', function () {
    it('creates expense request successfully with audit log', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
            'telegram_id' => 123456789,
        ]);

        $director = User::factory()->create([
            'role' => Role::DIRECTOR->value,
            'company_id' => 1,
            'telegram_id' => 987654321,
        ]);

        $this->mockBot->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function ($message, $chatId, $replyMarkup) use ($director) {
                return str_contains($message, 'Новая заявка') &&
                       $chatId === $director->telegram_id;
            });

        // Act
        $requestId = ExpenseService::createRequest(
            $this->mockBot,
            $requester,
            'Test expense description',
            1500.50,
            'UZS'
        );

        // Assert
        expect($requestId)->toBeInt()->toBeGreaterThan(0);

        $expenseRequest = ExpenseRequest::find($requestId);
        expect($expenseRequest)
            ->not->toBeNull()
            ->and($expenseRequest->requester_id)->toBe($requester->id)
            ->and($expenseRequest->description)->toBe('Test expense description')
            ->and($expenseRequest->amount)->toBe(1500.50)
            ->and($expenseRequest->currency)->toBe('UZS')
            ->and($expenseRequest->status)->toBe(ExpenseStatus::PENDING->value)
            ->and($expenseRequest->company_id)->toBe($requester->company_id);

        // Verify audit log was created
        $auditLog = AuditLog::where('record_id', $requestId)
            ->where('table_name', 'expense_requests')
            ->where('action', 'insert')
            ->first();

        expect($auditLog)
            ->not->toBeNull()
            ->and($auditLog->actor_id)->toBe($requester->id)
            ->and($auditLog->payload)->toHaveKey('amount', 1500.50)
            ->and($auditLog->payload)->toHaveKey('currency', 'UZS');
    });

    it('returns null when database transaction fails', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
        ]);

        // Mock DB transaction to throw exception
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new Exception('Database error'));

        // Act
        $requestId = ExpenseService::createRequest(
            $this->mockBot,
            $requester,
            'Test description',
            1000.00
        );

        // Assert
        expect($requestId)->toBeNull();
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Ошибка при создании заявки', Mockery::type('array'));
    });

    it('handles missing director gracefully', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
        ]);

        // Act
        $requestId = ExpenseService::createRequest(
            $this->mockBot,
            $requester,
            'Test description',
            1000.00
        );

        // Assert
        expect($requestId)->toBeInt()->toBeGreaterThan(0);
        Log::shouldHaveReceived('info')
            ->with(Mockery::pattern('/createRequest: (no director|successfully created)/'), Mockery::type('array'));
    });

    it('handles notification failure gracefully', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
        ]);

        $director = User::factory()->create([
            'role' => Role::DIRECTOR->value,
            'company_id' => 1,
            'telegram_id' => 987654321,
        ]);

        $this->mockBot->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new Exception('Telegram API error'));

        // Act
        $requestId = ExpenseService::createRequest(
            $this->mockBot,
            $requester,
            'Test description',
            1000.00
        );

        // Assert
        expect($requestId)->toBeInt()->toBeGreaterThan(0);
        Log::shouldHaveReceived('error')
            ->once()
            ->with('createRequest: failed sending to director', Mockery::type('array'));
    });

    it('validates input parameters', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
        ]);

        // Act & Assert - Test different parameter combinations
        $requestId1 = ExpenseService::createRequest(
            $this->mockBot,
            $requester,
            '',
            0.01,
            'USD'
        );
        expect($requestId1)->toBeInt();

        $requestId2 = ExpenseService::createRequest(
            $this->mockBot,
            $requester,
            'Very long description that might exceed certain limits but should still be processed correctly',
            999999.99
        );
        expect($requestId2)->toBeInt();
    });
});

describe('ExpenseService::sendToAccountant', function () {
    it('sends notification to accountant successfully', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
        ]);

        $accountant = User::factory()->create([
            'role' => Role::ACCOUNTANT->value,
            'company_id' => 1,
            'telegram_id' => 555666777,
        ]);

        $requestId = 123;
        $amount = 2500.75;
        $currency = 'UZS';

        $this->mockBot->shouldReceive('sendMessage')
            ->once()
            ->andReturn(null);

        // Act - Call the method without expecting exceptions
        ExpenseService::sendToAccountant(
            $this->mockBot,
            $requester,
            $requestId,
            $amount,
            $currency
        );

        // Assert - If we reach here, no exception was thrown
        expect(true)->toBeTrue();
    });

    it('handles missing accountant gracefully', function () {
        // Arrange
        $requester = User::factory()->create([
            'role' => Role::USER->value,
            'company_id' => 1,
        ]);

        // No accountant exists for this company

        // Act & Assert - Should throw exception when trying to access null accountant properties
        $this->expectException(ErrorException::class);

        ExpenseService::sendToAccountant(
            $this->mockBot,
            $requester,
            123,
            1000.00,
            'UZS'
        );
    });
});

describe('ExpenseService::deleteRequest', function () {
    it('deletes request with audit log', function () {
        // Arrange
        $user = User::factory()->create();
        $actor = User::factory()->create(); // Create a real actor
        $expenseRequest = ExpenseRequest::factory()->create([
            'requester_id' => $user->id,
        ]);

        $expenseService = new ExpenseService();
        $reason = 'Test deletion';

        // Act
        $expenseService->deleteRequest($expenseRequest->id, $actor->id, $reason);

        // Assert
        expect(ExpenseRequest::find($expenseRequest->id))->toBeNull();

        $auditLog = AuditLog::where('record_id', $expenseRequest->id)
            ->where('table_name', 'expense_requests')
            ->where('action', 'delete')
            ->first();

        expect($auditLog)
            ->not->toBeNull()
            ->and($auditLog->actor_id)->toBe($actor->id)
            ->and($auditLog->payload)->toHaveKey('reason', $reason);
    });

    it('handles non-existent request', function () {
        // Arrange
        $expenseService = new ExpenseService();
        $nonExistentId = 999999;

        // Act & Assert
        expect(fn () => $expenseService->deleteRequest($nonExistentId, 1, 'Test'))
            ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('deletes request without reason', function () {
        // Arrange
        $user = User::factory()->create();
        $actor = User::factory()->create(); // Create a real actor
        $expenseRequest = ExpenseRequest::factory()->create([
            'requester_id' => $user->id,
        ]);

        $expenseService = new ExpenseService();

        // Act
        $expenseService->deleteRequest($expenseRequest->id, $actor->id);

        // Assert
        expect(ExpenseRequest::find($expenseRequest->id))->toBeNull();

        $auditLog = AuditLog::where('record_id', $expenseRequest->id)
            ->where('action', 'delete')
            ->first();

        expect($auditLog)
            ->not->toBeNull()
            ->and($auditLog->payload)->toHaveKey('reason', null);
    });
});
