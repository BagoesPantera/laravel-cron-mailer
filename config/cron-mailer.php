<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Table
    |--------------------------------------------------------------------------
    |
    | The table used to store the pending e-mails before they are delivered by
    | the scheduled worker. The migration reads this value dynamically, so it
    | may be renamed freely.
    |
    */

    'table_name' => 'pending_emails',

    /*
    |--------------------------------------------------------------------------
    | Maximum Attempts
    |--------------------------------------------------------------------------
    |
    | How many times a single e-mail may be retried before it is abandoned by
    | the worker. Rows that already reached this number are skipped.
    |
    */

    'max_attempts' => 3,

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | The number of pending e-mails pulled from the table on every worker run.
    | Keep it small enough for the run to stay inside the scheduler window.
    |
    */

    'batch_size' => 10,

];
