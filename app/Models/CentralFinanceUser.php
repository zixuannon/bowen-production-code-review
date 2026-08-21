<?php

namespace App\Models;

/**
 * A Central Finance account assignment always resolves the central identity
 * directory, even if a legacy tenant connection happens to be the request's
 * default connection.
 */
class CentralFinanceUser extends User
{
    protected $connection = 'mysql';

    protected $table = 'users';
}
