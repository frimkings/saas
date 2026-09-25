<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class IssueOfflineLicense extends Command
{
    protected $signature = 'license:issue {installation : Installation UUID shown on the clinic license page} {expires : Expiry date YYYY-MM-DD} {--signing-key= : Path to a base64 Ed25519 secret key, kept on the developer machine} {--plan=Pro : Display name} {--feature=* : Included feature; repeat for multiple features (omit for all). Add clinical and/or optical to limit the product}';
    protected $description = 'Issue an offline license using a developer-held signing key';

    public function handle(): int
    {
        $data = validator([
            'installation' => $this->argument('installation'), 'expires' => $this->argument('expires'),
            'plan' => $this->option('plan'), 'features' => $this->option('feature'),
        ], ['installation' => 'required|uuid', 'expires' => 'required|date_format:Y-m-d|after_or_equal:today',
            'plan' => 'required|string|max:100', 'features' => 'array',
            'features.*' => ['string', \Illuminate\Validation\Rule::in(array_merge(['*'], \App\Support\PlanProduct::PRODUCT_KEYS, config('license.pro_features', [])))]]);
        if ($data->fails()) {
            $this->error($data->errors()->first());
            return self::FAILURE;
        }
        $path = $this->option('signing-key');
        if (!function_exists('sodium_crypto_sign_detached') || !$path || !is_file($path) || !is_readable($path)) {
            $this->error('Sodium and a readable developer signing-key file are required.');
            return self::FAILURE;
        }
        $secret = base64_decode(trim(file_get_contents($path)), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) !== config('license.public_key')) {
            $this->error('The signing key must match the public key configured on clinic installations.');
            return self::FAILURE;
        }
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $payload = $encode(json_encode([
            'tier' => 'pro', 'installation_id' => $this->argument('installation'),
            'issued' => now()->toDateString(), 'expires' => $this->argument('expires'),
            'plan' => $this->option('plan'), 'features' => $this->option('feature') ?: ['*'],
        ], JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached($payload, $secret);
        sodium_memzero($secret);
        $this->line('EYECLINIC-PRO-'.$payload.'.'.$encode($signature));
        return self::SUCCESS;
    }
}
