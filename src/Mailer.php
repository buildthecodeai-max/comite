<?php

declare(strict_types=1);

namespace CommitteeManager;

final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $from = getenv('MAIL_FROM') ?: 'noreply@committee-manager.local';
        $headers = implode("\r\n", [
            'From: ' . $from,
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        return @mail($to, $subject, $body, $headers);
    }
}
