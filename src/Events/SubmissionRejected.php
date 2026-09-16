<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Events;

use AtlasFlow\EFacturaRo\Laravel\Models\Submission;

/** ANAF said nok; `messages` are its words, also stored in `last_error`. */
final class SubmissionRejected
{
    /**
     * @param  list<string>  $messages
     */
    public function __construct(
        public readonly Submission $submission,
        public readonly array $messages,
    ) {}
}
