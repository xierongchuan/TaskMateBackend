<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\ExpenseRequest;
use App\Models\AuditLog;
use App\Enums\Role;
use App\Enums\ExpenseStatus;
use App\Services\ExpenseService;
use SergiX44\Nutgram\Nutgram;

beforeEach(function () {
    $this->mockBot = Mockery::mock(Nutgram::class);
    app()->instance(Nutgram::class, $this->mockBot);
});

afterEach(function () {
    Mockery::close();
});

describe('Expense Request Workflow', function () {
    describe('Complete Expense Request Flow', function () {
        it('processes complete expense request workflow from creation to approval', function () {
            // Arrange
            $requester = User::factory()->create([
                'role' => Role::USER->value,
                'company_id' => 1,
                'telegram_id' => 123456789,
                'full_name' => 'John Requester',
            ]);

            $director = User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 1,
                'telegram_id' => 987654321,
                'full_name' => 'Jane Director',
            ]);

            $accountant = User::factory()->create([
                'role' => Role::ACCOUNTANT->value,
                'company_id' => 1,
                'telegram_id' => 555666777,
                'full_name' => 'Bob Accountant',
            ]);

            // Mock bot messages
            $this->mockBot->shouldReceive('sendMessage')
                ->twice() // Once to director, once to accountant
                ->andReturn(null);

            // Act - Step 1: Create expense request
            $requestId = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'Office supplies for team',
                2500.50,
                'UZS'
            );

            // Assert - Verify request creation
            expect($requestId)->toBeInt()->toBeGreaterThan(0);

            $expenseRequest = ExpenseRequest::find($requestId);
            expect($expenseRequest)
                ->not->toBeNull()
                ->and($expenseRequest->status)->toBe(ExpenseStatus::PENDING->value)
                ->and($expenseRequest->amount)->toBe(2500.50);

            // Verify audit log for creation
            $creationAudit = AuditLog::where('record_id', $requestId)
                ->where('action', 'insert')
                ->first();
            expect($creationAudit)->not->toBeNull();

            // Act - Step 2: Director approves request
            $expenseRequest->update(['status' => ExpenseStatus::APPROVED->value]);

            // Act - Step 3: Send to accountant
            ExpenseService::sendToAccountant(
                $this->mockBot,
                $requester,
                $requestId,
                2500.50,
                'UZS'
            );

            // Assert - Verify approval status
            expect($expenseRequest->fresh()->status)->toBe(ExpenseStatus::APPROVED->value);

            // Act - Step 4: Accountant issues payment
            $expenseRequest->update(['status' => ExpenseStatus::ISSUED->value]);

            // Assert - Verify final status
            expect($expenseRequest->fresh()->status)->toBe(ExpenseStatus::ISSUED->value);
        });

        it('handles expense request decline workflow', function () {
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
                ->andReturn(null);

            // Act - Create and decline request
            $requestId = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'Unnecessary expense',
                1000.00,
                'UZS'
            );

            $expenseRequest = ExpenseRequest::find($requestId);
            $expenseRequest->update(['status' => ExpenseStatus::DECLINED->value]);

            // Assert
            expect($expenseRequest->fresh()->status)->toBe(ExpenseStatus::DECLINED->value);
        });
    });

    describe('Expense Request Creation Edge Cases', function () {
        it('handles expense request for user without company director', function () {
            // Arrange
            $requester = User::factory()->create([
                'role' => Role::USER->value,
                'company_id' => 999, // Company with no director
            ]);

            // Act
            $requestId = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'Request without director',
                500.00,
                'UZS'
            );

            // Assert
            expect($requestId)->toBeInt()->toBeGreaterThan(0);

            $expenseRequest = ExpenseRequest::find($requestId);
            expect($expenseRequest)
                ->not->toBeNull()
                ->and($expenseRequest->status)->toBe(ExpenseStatus::PENDING->value);
        });

        it('handles multiple expense requests from same user', function () {
            // Arrange
            $requester = User::factory()->create([
                'role' => Role::USER->value,
                'company_id' => 1,
            ]);

            User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 1,
                'telegram_id' => 987654321,
            ]);

            $this->mockBot->shouldReceive('sendMessage')
                ->times(3)
                ->andReturn(null);

            // Act - Create multiple requests
            $requestId1 = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'First expense',
                100.00
            );

            $requestId2 = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'Second expense',
                200.00
            );

            $requestId3 = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'Third expense',
                300.00
            );

            // Assert
            expect($requestId1)->toBeInt()->toBeGreaterThan(0)
                ->and($requestId2)->toBeInt()->toBeGreaterThan($requestId1)
                ->and($requestId3)->toBeInt()->toBeGreaterThan($requestId2);

            $userRequests = ExpenseRequest::where('requester_id', $requester->id)->get();
            expect($userRequests)->toHaveCount(3);
        });

        it('handles extreme expense amounts', function () {
            // Arrange
            $requester = User::factory()->create([
                'role' => Role::USER->value,
                'company_id' => 1,
            ]);

            User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 1,
                'telegram_id' => 987654321,
            ]);

            $this->mockBot->shouldReceive('sendMessage')
                ->times(3)
                ->andReturn(null);

            $extremeAmounts = [0.01, 999999.99, 1000000];

            // Act & Assert
            foreach ($extremeAmounts as $amount) {
                $requestId = ExpenseService::createRequest(
                    $this->mockBot,
                    $requester,
                    "Extreme amount test: {$amount}",
                    $amount
                );

                expect($requestId)->toBeInt()->toBeGreaterThan(0);

                $expenseRequest = ExpenseRequest::find($requestId);
                // Compare as strings to avoid floating point precision issues
                expect((string) $expenseRequest->amount)->toBe((string) (float) $amount);
            }
        });

        it('handles different currencies correctly', function () {
            // Arrange
            $requester = User::factory()->create([
                'role' => Role::USER->value,
                'company_id' => 1,
            ]);

            User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 1,
                'telegram_id' => 987654321,
            ]);

            $this->mockBot->shouldReceive('sendMessage')
                ->times(4)
                ->andReturn(null);

            $currencies = ['UZS', 'USD', 'EUR', 'RUB'];

            // Act & Assert
            foreach ($currencies as $currency) {
                $requestId = ExpenseService::createRequest(
                    $this->mockBot,
                    $requester,
                    "Currency test: {$currency}",
                    100.00,
                    $currency
                );

                expect($requestId)->toBeInt()->toBeGreaterThan(0);

                $expenseRequest = ExpenseRequest::find($requestId);
                expect($expenseRequest->currency)->toBe($currency);
            }
        });
    });

    describe('Expense Request Deletion', function () {
        it('deletes expense request with audit trail', function () {
            // Arrange
            $user = User::factory()->create();
            $expenseRequest = ExpenseRequest::factory()->create([
                'requester_id' => $user->id,
                'status' => ExpenseStatus::PENDING->value,
            ]);

            $expenseService = new ExpenseService();
            $actor = User::factory()->create(); // Create real actor user
            $reason = 'Duplicate request';

            // Act
            $expenseService->deleteRequest($expenseRequest->id, $actor->id, $reason);

            // Assert
            expect(ExpenseRequest::find($expenseRequest->id))->toBeNull();

            $deletionAudit = AuditLog::where('record_id', $expenseRequest->id)
                ->where('action', 'delete')
                ->first();

            expect($deletionAudit)
                ->not->toBeNull()
                ->and($deletionAudit->actor_id)->toBe($actor->id)
                ->and($deletionAudit->payload['reason'])->toBe($reason);
        });

        it('handles deletion of non-existent request', function () {
            // Arrange
            $expenseService = new ExpenseService();
            $nonExistentId = 999999;

            // Act & Assert
            expect(fn () => $expenseService->deleteRequest($nonExistentId, 1, 'Test'))
                ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
        });
    });

    describe('Multi-Company Expense Isolation', function () {
        it('isolates expense requests by company', function () {
            // Arrange
            $company1User = User::factory()->create(['company_id' => 1]);
            $company2User = User::factory()->create(['company_id' => 2]);

            $company1Director = User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 1,
                'telegram_id' => 111111111,
            ]);

            $company2Director = User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 2,
                'telegram_id' => 222222222,
            ]);

            // Mock bot to send to directors
            $this->mockBot->shouldReceive('sendMessage')
                ->atLeast()
                ->once()
                ->andReturn(null);

            // Act
            $company1RequestId = ExpenseService::createRequest(
                $this->mockBot,
                $company1User,
                'Company 1 expense',
                1000.00
            );

            $company2RequestId = ExpenseService::createRequest(
                $this->mockBot,
                $company2User,
                'Company 2 expense',
                2000.00
            );

            // Assert
            expect($company1RequestId)->toBeInt()->toBeGreaterThan(0)
                ->and($company2RequestId)->toBeInt()->toBeGreaterThan(0);

            $company1Request = ExpenseRequest::find($company1RequestId);
            $company2Request = ExpenseRequest::find($company2RequestId);

            expect($company1Request->company_id)->toBe(1)
                ->and($company2Request->company_id)->toBe(2);

            // Verify company isolation in queries
            $company1Requests = ExpenseRequest::where('company_id', 1)->get();
            $company2Requests = ExpenseRequest::where('company_id', 2)->get();

            expect($company1Requests)->toHaveCount(1)
                ->and($company2Requests)->toHaveCount(1)
                ->and($company1Requests->first()->id)->toBe($company1RequestId)
                ->and($company2Requests->first()->id)->toBe($company2RequestId);
        });
    });

    describe('Audit Trail Verification', function () {
        it('maintains complete audit trail for expense lifecycle', function () {
            // Arrange
            $requester = User::factory()->create([
                'company_id' => 1,
            ]);

            User::factory()->create([
                'role' => Role::DIRECTOR->value,
                'company_id' => 1,
                'telegram_id' => 987654321,
            ]);

            $this->mockBot->shouldReceive('sendMessage')
                ->once()
                ->andReturn(null);

            // Act - Create request
            $requestId = ExpenseService::createRequest(
                $this->mockBot,
                $requester,
                'Audit trail test',
                1500.00
            );

            // Act - Delete request
            $expenseService = new ExpenseService();
            $deleteActor = User::factory()->create(); // Create real actor for deletion
            $expenseService->deleteRequest($requestId, $deleteActor->id, 'Testing audit trail');

            // Assert - Verify complete audit trail
            $auditLogs = AuditLog::where('record_id', $requestId)
                ->orderBy('created_at')
                ->get();

            expect($auditLogs)->toHaveCount(2);

            // Verify creation audit
            $creationAudit = $auditLogs->first();
            expect($creationAudit->action)->toBe('insert')
                ->and($creationAudit->actor_id)->toBe($requester->id)
                ->and($creationAudit->payload)->toHaveKey('amount', 1500.00);

            // Verify deletion audit
            $deletionAudit = $auditLogs->last();
            expect($deletionAudit->action)->toBe('delete')
                ->and($deletionAudit->actor_id)->toBe($deleteActor->id)
                ->and($deletionAudit->payload)->toHaveKey('reason', 'Testing audit trail');
        });
    });
});
