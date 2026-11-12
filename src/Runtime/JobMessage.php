<?php

namespace App\Runtime;

interface JobMessage
{
    public function getJobId(): string;
}
