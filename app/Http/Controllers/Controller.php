<?php

namespace App\Http\Controllers;

use PDOException;
use Throwable;

abstract class Controller
{
    /**
     * The message of a refused action, to show on the page. A database error (a lock that waited
     * too long, say) is thrown on to the error page and the log instead: its message names the
     * database server and the query. PDOException covers Laravel's QueryException, and the one it
     * throws when the database stops a transaction inside another.
     *
     * @throws PDOException
     */
    protected function refusalMessage(Throwable $exception): string
    {
        if ($exception instanceof PDOException) {
            throw $exception;
        }

        return $exception->getMessage();
    }
}
