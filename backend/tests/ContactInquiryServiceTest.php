<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/ContactInquiryService.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'FAIL: %s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function expectInvalidArgument(callable $fn, string $messageContains, string $label): void
{
    try {
        $fn();
        throw new RuntimeException('FAIL: ' . $label . ' (expected InvalidArgumentException)');
    } catch (InvalidArgumentException $exception) {
        assertTrue(
            str_contains($exception->getMessage(), $messageContains),
            $label . ' message'
        );
    }
}

echo "ContactInquiryService unit tests\n";
echo str_repeat('=', 48) . "\n";

expectInvalidArgument(
    static fn () => ContactInquiryService::validateInput([]),
    'お問い合わせ種別',
    'missing category'
);
echo "OK missing category\n";

expectInvalidArgument(
    static fn () => ContactInquiryService::validateInput([
        'category' => 'bug',
        'subject' => '',
        'body' => '内容',
        'replyEmail' => 'a@example.com',
    ]),
    '件名',
    'missing subject'
);
echo "OK missing subject\n";

expectInvalidArgument(
    static fn () => ContactInquiryService::validateInput([
        'category' => 'bug',
        'subject' => '件名',
        'body' => '',
        'replyEmail' => 'a@example.com',
    ]),
    'お問い合わせ内容',
    'missing body'
);
echo "OK missing body\n";

expectInvalidArgument(
    static fn () => ContactInquiryService::validateInput([
        'category' => 'bug',
        'subject' => '件名',
        'body' => '内容',
        'replyEmail' => 'not-an-email',
    ]),
    '返信先メール',
    'invalid reply email'
);
echo "OK invalid reply email\n";

$longSubject = str_repeat('あ', ContactInquiryService::MAX_SUBJECT_LENGTH + 1);
expectInvalidArgument(
    static fn () => ContactInquiryService::validateInput([
        'category' => 'other',
        'subject' => $longSubject,
        'body' => '内容',
        'replyEmail' => 'a@example.com',
    ]),
    '件名は',
    'subject too long'
);
echo "OK subject too long\n";

$valid = ContactInquiryService::validateInput([
    'category' => 'billing',
    'subject' => '課金について',
    'body' => '解約したいです',
    'replyEmail' => 'User@Example.com',
]);
assertSame('billing', $valid['category'], 'category');
assertSame('課金について', $valid['subject'], 'subject');
assertSame('解約したいです', $valid['body'], 'body');
assertSame('user@example.com', $valid['replyEmail'], 'reply email normalized');
echo "OK valid input\n";

$honeypot = ContactInquiryService::validateInput([
    'honeypot' => 'bot-filled',
    'category' => '',
]);
assertSame('other', $honeypot['category'], 'honeypot short-circuit');
echo "OK honeypot short-circuit\n";

echo str_repeat('=', 48) . "\n";
echo "All ContactInquiryService tests passed.\n";
