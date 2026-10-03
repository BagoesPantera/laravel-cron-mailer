<?php

namespace Pantera\CronMailer\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
