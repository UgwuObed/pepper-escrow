<?php

namespace App\Services;

use App\Models\ApiToken;
use App\Models\ClientConfig;
use App\Models\Merchant;
use Illuminate\Http\Request;

class TenantService
{
    public function getMerchantFromRequest(Request $request): ?Merchant
    {
        $token = $request->get('api_token');
        if ($token instanceof ApiToken && $token->merchant_id) {
            return $token->merchant;
        }

        return null;
    }

    public function getClientConfig(Merchant $merchant): ClientConfig
    {
        $config = $merchant->clientConfig;

        if (!$config) {
            $config = $merchant->clientConfig()->create(ClientConfig::defaultConfig());
        }

        return $config;
    }

    public function updateClientConfig(Merchant $merchant, array $data): ClientConfig
    {
        $config = $this->getClientConfig($merchant);
        $config->update($data);
        return $config->fresh();
    }

    public function scopeByMerchant($query, Merchant $merchant)
    {
        return $query->where('appid', $merchant->id);
    }
}
