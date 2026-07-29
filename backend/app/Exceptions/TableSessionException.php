<?php

declare(strict_types=1);

namespace App\Exceptions;

class TableSessionException extends DomainException
{
    protected int $statusCode = 422;

    protected string $errorCode = 'table_unavailable';

    public static function unknownToken(): self
    {
        $exception = new self('This QR code is not recognised. Please ask a member of staff.');
        $exception->statusCode = 404;
        $exception->errorCode = 'table_not_found';

        return $exception;
    }

    public static function tableDisabled(string $number): self
    {
        return new self("Table {$number} is not accepting orders at the moment.", [
            'table_number' => $number,
        ]);
    }

    public static function branchClosed(string $branch): self
    {
        return new self("{$branch} is currently closed.", ['branch' => $branch]);
    }

    public static function sessionClosed(): self
    {
        return new self('This table session has already been closed.');
    }
}
