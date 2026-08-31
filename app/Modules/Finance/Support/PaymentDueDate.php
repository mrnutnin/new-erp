<?php

namespace App\Modules\Finance\Support;

use DateTimeImmutable;
use InvalidArgumentException;

final class PaymentDueDate
{
    public static function calculate(string $documentDate, string $dueRule, mixed $creditDays): string
    {
        if (! preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/D', $documentDate)) {
            throw new InvalidArgumentException('Document date must use Y-m-d format.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $documentDate);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $documentDate) {
            throw new InvalidArgumentException('Document date is invalid.');
        }
        if (! in_array($dueRule, ['DUE_ON_DATE', 'END_OF_MONTH'], true)) {
            throw new InvalidArgumentException('Due rule is invalid.');
        }
        if (! is_int($creditDays) || $creditDays < 0 || $creditDays > 3650) {
            throw new InvalidArgumentException('Credit days must be an integer between 0 and 3650.');
        }

        $dueDate = $date->modify("+{$creditDays} days");
        if ($dueRule === 'END_OF_MONTH') {
            $dueDate = $dueDate->modify('last day of this month');
        }

        return $dueDate->format('Y-m-d');
    }
}
