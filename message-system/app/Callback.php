<?php
declare(strict_types=1);

final class Callback
{
    public static function handle(string $channel): void
    {
        $settings = config();
        $headers = Receiver::headersFromServer($_SERVER);

        if ($settings['callback_token'] !== '') {
            $provided = (string) ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? $_GET['token'] ?? '');
            if (!hash_equals((string) $settings['callback_token'], $provided)) {
                self::respond(403, 'Unauthorized.');
                return;
            }
        }

        try {
            $database = new Database($settings['database_path']);
            $receiver = new Receiver($database, $settings);
            $maximum = (int) $settings['max_body_bytes'];
            $declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

            if ($declaredLength > $maximum) {
                $result = $receiver->reject($channel, $_SERVER, '请求体超过允许大小。', $declaredLength);
            } else {
                $body = file_get_contents('php://input', false, null, 0, $maximum + 1);
                if ($body === false) {
                    $result = $receiver->reject($channel, $_SERVER, '无法读取原始请求体。');
                } elseif (strlen($body) > $maximum) {
                    $result = $receiver->reject($channel, $_SERVER, '请求体超过允许大小。', strlen($body));
                } else {
                    $result = $receiver->receive($channel, $body, $_SERVER, $headers);
                }
            }
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $result = ['status' => 500, 'message' => 'Receiver initialization failed.'];
        }

        self::respond((int) $result['status'], (string) $result['message']);
    }

    private static function respond(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $message;
    }
}
