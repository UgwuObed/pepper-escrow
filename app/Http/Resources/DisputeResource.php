<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'customer_id' => $this->customer_id,
            'customer_email' => $this->customer_email,
            'merchant_email' => $this->merchant_email,
            'appid' => $this->appid,
            'transcode' => $this->transcode,
            'dispute_referenceid' => $this->dispute_referenceid,
            'dispute_category' => $this->dispute_category,
            'dispute_description' => $this->dispute_description,
            'arbitrator_name' => $this->arbitrator_name,
            'arbitrator_profile' => $this->arbitrator_profile,
            'final_resolution' => $this->final_resolution,
            'resolution_date' => $this->resolution_date,
            'files' => DisputeFileResource::collection($this->whenLoaded('files')),
        ];
    }
}
