<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'login'        => $this->login,
            'full_name'    => $this->full_name,
            'role'         => $this->in_bot_role,
            'telegram_id'  => $this->telegram_id,
            'phone_number' => $this->phone_number,
            'company_id'   => $this->company_id,
            // 'status'       => $this->status,
        ];
    }
}
