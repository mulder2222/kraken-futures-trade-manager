<?php

declare(strict_types=1);

namespace App\Kraken;

use App\Config\TradingRuntime;
use App\Dto\KrakenFill;
use App\Dto\KrakenOpenOrder;
use App\Dto\KrakenOpenPosition;
use App\Dto\KrakenOrderActionResult;
use App\Exception\KrakenApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class KrakenFuturesClient implements KrakenFuturesClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly TradingRuntime $runtime,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {
    }

    public function sendOrder(array $payload): KrakenOrderActionResult
    {
        return $this->normalizeOrderActionResult($this->request('POST', ['/sendorder', '/sendOrder'], $payload), 'sendStatus');
    }

    public function editOrder(array $payload): KrakenOrderActionResult
    {
        return $this->normalizeOrderActionResult($this->request('POST', ['/editorder', '/editOrder'], $payload), 'editStatus');
    }

    public function cancelOrder(string $orderId): KrakenOrderActionResult
    {
        return $this->normalizeOrderActionResult($this->request('POST', ['/cancelorder', '/cancelOrder'], ['order_id' => $orderId]), 'cancelStatus');
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $response = $this->request('GET', '/openorders', $symbol !== null ? ['symbol' => $symbol] : []);
        $orders = $response['openOrders'] ?? $response['orders'] ?? [];

        return array_map(fn (array $order): KrakenOpenOrder => $this->normalizeOpenOrder($order), $orders);
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        $response = $this->request('GET', '/openpositions', $symbol !== null ? ['symbol' => $symbol] : []);
        $positions = $response['openPositions'] ?? $response['positions'] ?? [];

        return array_values(array_filter(
            array_map(fn (array $position): ?KrakenOpenPosition => $this->normalizeOpenPosition($position), $positions),
            static fn (?KrakenOpenPosition $position): bool => $position !== null
        ));
    }

    public function getFills(?string $symbol = null): array
    {
        $response = $this->request('GET', '/fills', $symbol !== null ? ['symbol' => $symbol] : []);
        $fills = $response['fills'] ?? [];

        return array_map(fn (array $fill): KrakenFill => $this->normalizeFill($fill), $fills);
    }

    public function batchOrder(array $payload): array
    {
        return $this->request('POST', ['/batchorder', '/batchOrder'], $payload);
    }

    /**
     * @param string|list<string> $endpoints
     */
    private function request(string $method, string|array $endpoints, array $payload): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new KrakenApiException('Kraken Futures API credentials are not configured.');
        }

        $encodedPayload = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $lastException = null;
        $endpointCandidates = is_array($endpoints) ? $endpoints : [$endpoints];

        for ($attempt = 1; $attempt <= $this->runtime->maxRetries(); ++$attempt) {
            foreach ($endpointCandidates as $endpoint) {
                $path = '/derivatives/api/v3'.$endpoint;
                $url = rtrim($this->runtime->krakenBaseUri(), '/').$path;
                $signPaths = array_values(array_unique([
                    $path,
                    preg_replace('#^/derivatives#', '', $path) ?? $path,
                ]));

                foreach ($signPaths as $signPath) {
                    $nonce = (string) (int) floor(microtime(true) * 1000);

                    try {
                        $response = $this->client->request($method, $url, [
                            'headers' => [
                                'APIKey' => $this->apiKey,
                                'Nonce' => $nonce,
                                'Authent' => $this->sign($encodedPayload, $nonce, $signPath),
                            ],
                            'query' => $method === 'GET' ? $payload : [],
                            'body' => $method === 'POST' ? $payload : [],
                            'timeout' => $this->runtime->requestTimeout(),
                        ]);

                        $statusCode = $response->getStatusCode();
                        $content = $response->getContent(false);
                        $data = json_decode($content, true);
                        $data = is_array($data) ? $data : ['raw' => $content];

                        if ($statusCode >= 500 || $statusCode === 429) {
                            throw new KrakenApiException(sprintf('Kraken HTTP %d: %s', $statusCode, $this->extractErrorMessage($data)), ['response' => $data]);
                        }

                        if (($data['result'] ?? null) !== 'success') {
                            throw new KrakenApiException($this->extractErrorMessage($data), ['response' => $data]);
                        }

                        if ($signPath !== $path || $endpoint !== $endpointCandidates[0]) {
                            $this->logger->info('Kraken request succeeded with alternate endpoint/signing path', [
                                'endpoint' => $endpoint,
                                'sign_path' => $signPath,
                            ]);
                        }

                        return $data;
                    } catch (TransportExceptionInterface|KrakenApiException $exception) {
                        $lastException = $exception;

                        $this->logger->warning('Kraken request failed', [
                            'endpoint' => $endpoint,
                            'attempt' => $attempt,
                            'sign_path' => $signPath,
                            'error' => $exception->getMessage(),
                            'payload' => $payload,
                            'response' => $exception instanceof KrakenApiException ? $exception->payload() : [],
                        ]);

                        $isAuthenticationError = $exception instanceof KrakenApiException
                            && str_contains(strtolower($exception->getMessage()), 'authenticationerror');

                        if (!$isAuthenticationError || $signPath === end($signPaths)) {
                            continue;
                        }
                    }
                }
            }

            if ($attempt < $this->runtime->maxRetries()) {
                usleep($attempt * 200_000);
            }
        }

        if ($lastException instanceof KrakenApiException) {
            throw $lastException;
        }

        throw new KrakenApiException('Kraken request failed after retries.', ['endpoint' => $endpoint]);
    }

    private function sign(string $postData, string $nonce, string $endpointPath): string
    {
        $message = $postData.$nonce.$endpointPath;
        $hash = hash('sha256', $message, true);
        $secret = base64_decode($this->apiSecret, true);

        if ($secret === false) {
            throw new KrakenApiException('Kraken API secret must be base64 encoded.');
        }

        return base64_encode(hash_hmac('sha512', $hash, $secret, true));
    }

    private function normalizeOrderActionResult(array $response, string $statusKey): KrakenOrderActionResult
    {
        $status = $response[$statusKey] ?? [];
        $orderStatus = (string) ($status['status'] ?? 'unknown');

        if (!in_array($orderStatus, ['placed', 'edited', 'cancelled'], true)) {
            throw new KrakenApiException(sprintf('Kraken order rejected: %s', $orderStatus), ['response' => $response]);
        }

        return new KrakenOrderActionResult(
            $orderStatus,
            (string) ($status['order_id'] ?? $status['orderId'] ?? ''),
            isset($status['receivedTime']) ? (string) $status['receivedTime'] : null,
            $response,
        );
    }

    private function extractErrorMessage(array $data): string
    {
        $error = $data['error'] ?? null;

        if (is_string($error) && $error !== '') {
            return $error;
        }

        if (is_array($error) && $error !== []) {
            return implode('; ', array_map(
                static fn (mixed $item): string => is_scalar($item)
                    ? (string) $item
                    : (json_encode($item, JSON_UNESCAPED_SLASHES) ?: 'unknown'),
                $error
            ));
        }

        $sendStatus = $data['sendStatus']['status'] ?? null;
        if (is_string($sendStatus) && $sendStatus !== '') {
            return $sendStatus;
        }

        $editStatus = $data['editStatus']['status'] ?? null;
        if (is_string($editStatus) && $editStatus !== '') {
            return $editStatus;
        }

        $cancelStatus = $data['cancelStatus']['status'] ?? null;
        if (is_string($cancelStatus) && $cancelStatus !== '') {
            return $cancelStatus;
        }

        $message = $data['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'Unknown Kraken API error: '.json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function normalizeOpenOrder(array $order): KrakenOpenOrder
    {
        return new KrakenOpenOrder(
            (string) ($order['order_id'] ?? $order['orderId'] ?? ''),
            (string) ($order['symbol'] ?? $order['instrument'] ?? ''),
            ((int) ($order['direction'] ?? 0)) === 1 ? 'sell' : (string) ($order['side'] ?? 'buy'),
            (string) ($order['type'] ?? $order['orderType'] ?? 'unknown'),
            (float) ($order['qty'] ?? $order['quantity'] ?? 0.0),
            (float) ($order['filled'] ?? $order['filledQty'] ?? 0.0),
            isset($order['limit_price']) ? (float) $order['limit_price'] : (isset($order['limitPrice']) ? (float) $order['limitPrice'] : null),
            isset($order['stop_price']) ? (float) $order['stop_price'] : (isset($order['stopPrice']) ? (float) $order['stopPrice'] : null),
            (bool) ($order['reduce_only'] ?? $order['reduceOnly'] ?? false),
            $order,
        );
    }

    private function normalizeOpenPosition(array $position): ?KrakenOpenPosition
    {
        $size = (float) ($position['size'] ?? $position['balance'] ?? 0.0);
        $symbol = (string) ($position['symbol'] ?? $position['instrument'] ?? '');

        if ($symbol === '' || abs($size) <= 0.0) {
            return null;
        }

        return new KrakenOpenPosition(
            $symbol,
            $size,
            (float) ($position['entryPrice'] ?? $position['entry_price'] ?? 0.0),
            (float) ($position['markPrice'] ?? $position['mark_price'] ?? 0.0),
            (float) ($position['pnl'] ?? 0.0),
            $position,
        );
    }

    private function normalizeFill(array $fill): KrakenFill
    {
        return new KrakenFill(
            (string) ($fill['order_id'] ?? $fill['orderId'] ?? ''),
            (string) ($fill['symbol'] ?? $fill['instrument'] ?? ''),
            (string) ($fill['side'] ?? $fill['direction'] ?? ''),
            (float) ($fill['size'] ?? $fill['qty'] ?? $fill['quantity'] ?? 0.0),
            (float) ($fill['price'] ?? 0.0),
            $fill,
        );
    }
}
