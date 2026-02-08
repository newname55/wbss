<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

var_dump(getenv('LINE_CHANNEL_ID'));
var_dump(getenv('LINE_MSG_CHANNEL_SECRET'));
var_dump(getenv('LINE_MSG_CHANNEL_ACCESS_TOKEN'));