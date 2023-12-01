<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Log;
use App\Services\PaymentService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use GuzzleHttp\Client;

class RaiffeisenSbp extends PaymentService
{
    public const NAME = 'raiffeisen-sbp';

    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(['base_uri' => getenv('PAYMENT_RAIFFEISEN_SBP_ENDPOINT')]);
    }

    public function makePayment(Payment $payment): ?string
    {
        $data = [
            'amount' => (string)$payment->amount,
            'currency' => getenv('CURRENCY') ?: 'RUB',
            'order' => $payment->id,
            'qrType' => 'QRDynamic',
            // 'qrExpirationDate' => Carbon::now()->addMinutes(10)->toIso8601String(),
            'sbpMerchantId' => getenv('PAYMENT_RAIFFEISEN_SBP_ID'),
            'redirectUrl' => $this->getSuccessUrl($payment),
        ];

        /* if ($payment->subscription) {
            $data['subscription'] = [
                'id' => (string)$payment->subscription->id,
                'subscriptionPurpose' => $payment->subscription->level->title,
            ];
        } */

        $response = $this->client->post('qrs', ['json' => $data]);
        $output = json_decode((string)$response->getBody(), true);
        if (!empty($output['qrId'])) {
            $payment->remote_id = $output['qrId'];
            $payment->link = $output['payload'];
            $payment->save();

            /*
            if (!empty($output['subscriptionId']) && $payment->subscription) {
                $payment->subscription->remote_id = $output['subscriptionId'];
                $payment->subscription->save();
            } */

            return $this::getQR($payment);
        }

        return null;
    }

    public function getPaymentStatus(Payment $payment): ?bool
    {
        try {
            $response = $this->client->get('qrs/' . $payment->remote_id, [
                'headers' => ['Authorization' => 'Bearer ' . getenv('PAYMENT_RAIFFEISEN_SBP_KEY')],
            ]);
            $output = json_decode((string)$response->getBody(), true);
            if ($output['qrStatus'] === 'PAID') {
                return true;
            }
            if (in_array($output['qrStatus'], ['CANCELLED', 'EXPIRED'])) {
                return false;
            }
        } catch (\Throwable  $e) {
            Log::error($e);
        }

        return null;
    }

    public function getSuccessUrl(Payment $payment): string
    {
        return rtrim(getenv('SITE_URL'), '/') . '/user/payments/' . $payment->id;
    }

    public static function getQR(Payment $payment): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(400),
            new SvgImageBackEnd()
        );
        $svg = (new Writer($renderer))->writeString($payment->link);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}