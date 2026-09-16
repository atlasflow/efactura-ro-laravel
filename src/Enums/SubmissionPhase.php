<?php

declare(strict_types=1);

namespace AtlasFlow\EFacturaRo\Laravel\Enums;

/**
 * The persisted state of one submission. PENDING_UPLOAD and PROCESSING are
 * live and driven by PollSubmission; the rest are terminal.
 */
enum SubmissionPhase: string
{
    /** Validated; the upload is waiting because ANAF was unavailable. */
    case PENDING_UPLOAD = 'pending_upload';

    /** Uploaded; ANAF has not answered ok or nok yet. */
    case PROCESSING = 'processing';

    /** ANAF said ok: delivered to the buyer's SPV. */
    case ACCEPTED = 'accepted';

    /** ANAF said nok: the error list is in `last_error`. */
    case REJECTED = 'rejected';

    /** ANAF refused the upload outright (ExecutionStatus 1 or "XML cu erori nepreluat de sistem"). */
    case REFUSED = 'refused';

    /** Polling gave up after `submissions.max_attempts`; the state at ANAF is unknown and must be reconciled from the inbox. */
    case ABANDONED = 'abandoned';

    public function isLive(): bool
    {
        return $this === self::PENDING_UPLOAD || $this === self::PROCESSING;
    }
}
