<?php

declare(strict_types=1);

use App\Services\ValidationService;
use App\Models\User;
use App\Models\Task;

describe('ValidationService', function () {
    beforeEach(function () {
        $this->service = new ValidationService();
    });

    it('validates amount', function () {
        $result = $this->service->validateAmount('100000');
        expect($result['valid'])->toBeTrue()
            ->and($result['value'])->toBe(100000.0);

        $result = $this->service->validateAmount('invalid');
        expect($result['valid'])->toBeFalse();
    });

    it('validates comment', function () {
        $result = $this->service->validateComment('Valid comment');
        expect($result['valid'])->toBeTrue();

        $result = $this->service->validateComment('');
        expect($result['valid'])->toBeFalse();
    });


});
